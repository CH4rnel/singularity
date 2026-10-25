// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {SafeERC20} from "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import {ReentrancyGuard} from "@openzeppelin/contracts/security/ReentrancyGuard.sol";

interface IWrappedNative is IERC20 {
    function deposit() external payable;

    function withdraw(uint256 amount) external;
}

/// The part of LaunchToken this hub uses: a plain WCYBER transfer to the token
/// followed by `distribute()` is a payment to every holder in proportion.
interface IHolderRewards {
    function distribute() external returns (uint256 credited);
}

/// The part of LaunchLocker this hub uses. `collect` is permissionless and pays
/// the creator share to `locks(id).creator`, which is this hub once the launch
/// key has called `setCreator(id, hub)`.
interface ILaunchLocker {
    function collect(uint256 tokenId)
        external
        returns (uint256, uint256, uint256, uint256, uint256, uint256);

    function setCreator(uint256 tokenId, address to) external;
}

/// @title ServiceHub: one adapter between every Cyberia service and its token
/// @notice A service token is worth what its service actually sends it. This
/// contract is where a service sends it: payments for the service (in CYBER or
/// in the token itself) and the creator share of the token's own pool fees all
/// land here and are split the same way every time:
///
///   - `protocolFeeBps` of a *payment* to `feeRecipient` (never of pool fees,
///     which already paid the protocol its 1% inside the locker);
///   - `holdersBps` to the token's holders, through the token's own reward
///     ledger (`LaunchToken.distribute`), claimable by every holder as CYBER;
///   - the rest to the service's treasury, which only its operator may spend.
///
/// A payment made in the service token is burned outright, as is the token
/// side of collected pool fees: the only supply sink that needs no price.
///
/// @dev What this contract deliberately does not do. It mints nothing and
/// emits nothing: every wei it pays out arrived first, so no number here is a
/// promise. It holds no price and no oracle, so a discount is a view a service
/// reads off-chain when it quotes, never an amount it trusts from a caller.
/// Parameters belong to each service's `operator` -- a key today, the
/// executor of that token's DAO when one exists on chain -- within bounds the
/// contract itself enforces.
contract ServiceHub is ReentrancyGuard {
    using SafeERC20 for IERC20;
    using SafeERC20 for IWrappedNative;

    uint16 public constant BPS = 10_000;

    /// @notice Ceiling on the protocol's cut of a payment (1%)
    uint16 public constant MAX_PROTOCOL_FEE_BPS = 100;

    /// @notice Ceiling on a holder discount a service may advertise (50%)
    uint16 public constant MAX_DISCOUNT_BPS = 5_000;

    address public constant BURN = 0x000000000000000000000000000000000000dEaD;

    IWrappedNative public immutable wrappedNative;
    ILaunchLocker public immutable locker;

    /// @notice Where the protocol's cut goes. Fixed at deploy: nobody can
    /// redirect it later, the owner included.
    address public immutable feeRecipient;

    address public owner;
    uint16 public protocolFeeBps;

    struct Service {
        bool listed;
        // who spends the treasury and tunes the split (a DAO executor later)
        address operator;
        // the token's LaunchLocker position, 0 if its fees are not routed here
        uint256 lockId;
        // share of every inflow sent to holders through the token's ledger
        uint16 holdersBps;
        // discount a service grants a payer holding at least `discountMinBalance`
        uint16 discountBps;
        uint256 discountMinBalance;
        // WCYBER held for the operator
        uint256 treasury;
    }

    /// @notice Services by their token's address
    mapping(address => Service) public services;
    address[] public serviceTokens;

    /// @notice Sum of every treasury: what this contract owes in WCYBER.
    /// Anything above it arrived without saying for whom (see `creditUnattributed`).
    uint256 public accounted;

    event ServiceListed(address indexed token, address indexed operator, uint256 lockId);
    event ServiceUpdated(address indexed token, uint256 lockId, uint16 holdersBps, uint16 discountBps, uint256 discountMinBalance);
    event OperatorChanged(address indexed token, address indexed from, address indexed to);
    event Paid(address indexed token, address indexed payer, uint256 amount, bytes32 indexed ref);
    event PaidInToken(address indexed token, address indexed payer, uint256 amount, bytes32 indexed ref);
    event Routed(address indexed token, uint256 protocolFee, uint256 toHolders, uint256 toTreasury);
    event FeesCollected(address indexed token, uint256 wrapped, uint256 burned);
    event TreasuryWithdrawn(address indexed token, address indexed to, uint256 amount, bool unwrapped);
    event LockReleased(address indexed token, uint256 lockId, address indexed to);
    event ProtocolFeeChanged(uint16 from, uint16 to);
    event OwnerChanged(address indexed from, address indexed to);

    error NotOwner();
    error NotOperator();
    error Unlisted();
    error AlreadyListed();
    error Bounds();
    error Zero();
    error NoLock();
    error Insufficient();

    modifier onlyOwner() {
        if (msg.sender != owner) revert NotOwner();
        _;
    }

    modifier onlyOperator(address token) {
        Service storage s = services[token];
        if (!s.listed) revert Unlisted();
        if (msg.sender != s.operator && msg.sender != owner) revert NotOperator();
        _;
    }

    constructor(IWrappedNative wrappedNative_, ILaunchLocker locker_, address feeRecipient_, uint16 protocolFeeBps_) {
        if (address(wrappedNative_) == address(0) || feeRecipient_ == address(0)) revert Zero();
        if (protocolFeeBps_ > MAX_PROTOCOL_FEE_BPS) revert Bounds();
        wrappedNative = wrappedNative_;
        locker = locker_;
        feeRecipient = feeRecipient_;
        protocolFeeBps = protocolFeeBps_;
        owner = msg.sender;
        emit OwnerChanged(address(0), msg.sender);
        emit ProtocolFeeChanged(0, protocolFeeBps_);
    }

    /// @dev Only the wrapper may send the coin here, when a treasury is withdrawn unwrapped.
    receive() external payable {
        if (msg.sender != address(wrappedNative)) revert Zero();
    }

    // ------------------------------------------------------------------ admin

    function listService(address token, address operator, uint256 lockId, uint16 holdersBps) external onlyOwner {
        if (token == address(0) || operator == address(0)) revert Zero();
        if (holdersBps > BPS) revert Bounds();
        Service storage s = services[token];
        if (s.listed) revert AlreadyListed();
        s.listed = true;
        s.operator = operator;
        s.lockId = lockId;
        s.holdersBps = holdersBps;
        serviceTokens.push(token);
        emit ServiceListed(token, operator, lockId);
        emit ServiceUpdated(token, lockId, holdersBps, 0, 0);
    }

    /// @notice The service's own knobs, within the contract's bounds.
    function setService(
        address token,
        uint256 lockId,
        uint16 holdersBps,
        uint16 discountBps,
        uint256 discountMinBalance
    ) external onlyOperator(token) {
        if (holdersBps > BPS || discountBps > MAX_DISCOUNT_BPS) revert Bounds();
        Service storage s = services[token];
        s.lockId = lockId;
        s.holdersBps = holdersBps;
        s.discountBps = discountBps;
        s.discountMinBalance = discountMinBalance;
        emit ServiceUpdated(token, lockId, holdersBps, discountBps, discountMinBalance);
    }

    /// @notice Hand a service to its DAO executor (or back). Owner or the current operator.
    function setOperator(address token, address operator) external onlyOperator(token) {
        if (operator == address(0)) revert Zero();
        emit OperatorChanged(token, services[token].operator, operator);
        services[token].operator = operator;
    }

    /// @notice Hand a launch position's creator share on (to a new hub version,
    /// or a DAO's own contract). Without this, `setCreator(id, hub)` would be a
    /// one-way door: only the current creator may move it, and that is the hub.
    function releaseLock(address token, address to) external onlyOwner {
        Service storage s = services[token];
        if (!s.listed) revert Unlisted();
        if (s.lockId == 0) revert NoLock();
        if (to == address(0)) revert Zero();
        locker.setCreator(s.lockId, to);
        emit LockReleased(token, s.lockId, to);
    }

    function setProtocolFee(uint16 bps) external onlyOwner {
        if (bps > MAX_PROTOCOL_FEE_BPS) revert Bounds();
        emit ProtocolFeeChanged(protocolFeeBps, bps);
        protocolFeeBps = bps;
    }

    function transferOwnership(address to) external onlyOwner {
        if (to == address(0)) revert Zero();
        emit OwnerChanged(owner, to);
        owner = to;
    }

    // --------------------------------------------------------------- payments

    /// @notice Pay a service in CYBER. `ref` is the service's own order id, so
    /// its backend can match the `Paid` event to what it sold.
    function pay(address token, bytes32 ref) external payable nonReentrant {
        if (!services[token].listed) revert Unlisted();
        if (msg.value == 0) revert Zero();
        wrappedNative.deposit{value: msg.value}();
        emit Paid(token, msg.sender, msg.value, ref);
        _route(token, msg.value, true);
    }

    /// @notice Pay a service in its own token. The whole amount is burned.
    function payInToken(address token, uint256 amount, bytes32 ref) external nonReentrant {
        if (!services[token].listed) revert Unlisted();
        if (amount == 0) revert Zero();
        IERC20(token).safeTransferFrom(msg.sender, BURN, amount);
        emit PaidInToken(token, msg.sender, amount, ref);
    }

    /// @notice Pull the creator share of the token's pool fees and route it.
    /// Permissionless: anyone may pay the gas to push fees to the holders.
    function collectLaunchFees(address token) external nonReentrant returns (uint256 wrapped, uint256 burned) {
        Service storage s = services[token];
        if (!s.listed) revert Unlisted();
        if (s.lockId == 0 || address(locker) == address(0)) revert NoLock();

        uint256 wrappedBefore = wrappedNative.balanceOf(address(this));
        uint256 tokenBefore = IERC20(token).balanceOf(address(this));
        locker.collect(s.lockId);
        wrapped = wrappedNative.balanceOf(address(this)) - wrappedBefore;
        burned = IERC20(token).balanceOf(address(this)) - tokenBefore;

        if (burned > 0) IERC20(token).safeTransfer(BURN, burned);
        emit FeesCollected(token, wrapped, burned);
        if (wrapped > 0) _route(token, wrapped, false);
    }

    /// @notice Attribute WCYBER that arrived by plain transfer (e.g. a contract
    /// whose fee recipient was pointed here) to one service. Owner only, and
    /// never more than is actually unowed.
    function creditUnattributed(address token, uint256 amount) external onlyOwner nonReentrant {
        if (!services[token].listed) revert Unlisted();
        if (amount == 0 || amount > unattributed()) revert Insufficient();
        _route(token, amount, false);
    }

    function withdrawTreasury(address token, address to, uint256 amount, bool unwrap)
        external
        onlyOperator(token)
        nonReentrant
    {
        if (to == address(0)) revert Zero();
        Service storage s = services[token];
        if (amount > s.treasury) revert Insufficient();
        s.treasury -= amount;
        accounted -= amount;
        if (unwrap) {
            wrappedNative.withdraw(amount);
            (bool ok, ) = to.call{value: amount}("");
            if (!ok) revert Insufficient();
        } else {
            wrappedNative.safeTransfer(to, amount);
        }
        emit TreasuryWithdrawn(token, to, amount, unwrap);
    }

    // ------------------------------------------------------------------ views

    /// @notice The discount `payer` gets on `token`'s service, in bps. A
    /// service applies it when it quotes; the hub never computes a price.
    function discountOf(address token, address payer) external view returns (uint16) {
        Service storage s = services[token];
        if (!s.listed || s.discountBps == 0) return 0;
        return IERC20(token).balanceOf(payer) >= s.discountMinBalance ? s.discountBps : 0;
    }

    function unattributed() public view returns (uint256) {
        uint256 balance = wrappedNative.balanceOf(address(this));
        return balance > accounted ? balance - accounted : 0;
    }

    function serviceCount() external view returns (uint256) {
        return serviceTokens.length;
    }

    // --------------------------------------------------------------- internal

    function _route(address token, uint256 amount, bool isPayment) internal {
        Service storage s = services[token];
        uint256 fee = isPayment ? (amount * protocolFeeBps) / BPS : 0;
        uint256 rest = amount - fee;
        uint256 toHolders = (rest * s.holdersBps) / BPS;
        uint256 toTreasury = rest - toHolders;

        if (fee > 0) wrappedNative.safeTransfer(feeRecipient, fee);
        if (toHolders > 0) {
            wrappedNative.safeTransfer(token, toHolders);
            // A ledger that cannot credit yet (no eligible holder, a sum too
            // small to spread) keeps the WCYBER for its next round.
            try IHolderRewards(token).distribute() {} catch {}
        }
        s.treasury += toTreasury;
        accounted += toTreasury;
        emit Routed(token, fee, toHolders, toTreasury);
    }
}
