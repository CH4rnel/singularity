// SPDX-License-Identifier: GPL-2.0-or-later
pragma solidity =0.7.6;
pragma abicoder v2;

import '@openzeppelin/contracts-v3/token/ERC721/IERC721Receiver.sol';

import '../pancake-v3-core/libraries/TransferHelper.sol';
import '../pancake-v3-periphery/interfaces/INonfungiblePositionManager.sol';
import '../pancake-v3-periphery/libraries/PositionValue.sol';

/// @title Permanent home for a launch's liquidity position, with its fees still payable
/// @notice A v2 launch burns its LP token, and the fees that LP would have earned are lost with it,
/// because in v2 fees compound into the reserves a burned LP can no longer redeem. In v3 fees accrue
/// *outside* the position and are claimed separately, so liquidity can be locked forever and still
/// pay out. This contract is that: it takes a position NFT and never lets it leave -- there is no
/// `decreaseLiquidity` here, no `burn`, and no way to transfer the NFT out -- while `collect` stays
/// open to anyone and splits what it collects between the launch's creator and the treasury.
///
/// @dev The split is snapshotted when the position arrives and is never touched again. The owner can
/// retune `defaultCreatorBps` for future launches as often as they like; what an existing launch was
/// promised is not the owner's to change. A creator fee that can be revoked is not a reason to launch
/// here, and a promise that cannot be checked in the contract is not a promise.
contract LaunchLocker is IERC721Receiver {
    uint16 internal constant BPS = 10000;

    INonfungiblePositionManager public immutable positions;

    address public owner;

    /// @notice Where the non-creator share of collected fees goes
    address public treasury;

    /// @notice The creator's share applied to positions locked from now on, in basis points
    uint16 public defaultCreatorBps;

    struct Lock {
        // who receives the creator share of this position's fees
        address creator;
        // the creator's share, fixed at the moment this position was locked
        uint16 creatorBps;
        // set once the position is here, so tokenId 0 and an unknown tokenId read differently
        bool locked;
    }

    /// @notice The terms each locked position was accepted under
    mapping(uint256 => Lock) public locks;

    /// @notice Every position this contract holds, in the order they arrived
    uint256[] public lockedIds;

    event Locked(uint256 indexed tokenId, address indexed creator, uint16 creatorBps);
    event Collected(
        uint256 indexed tokenId,
        address indexed creator,
        uint256 creatorAmount0,
        uint256 creatorAmount1,
        uint256 treasuryAmount0,
        uint256 treasuryAmount1
    );
    event CreatorChanged(uint256 indexed tokenId, address indexed from, address indexed to);
    event DefaultCreatorBpsChanged(uint16 from, uint16 to);
    event TreasuryChanged(address indexed from, address indexed to);
    event OwnerChanged(address indexed from, address indexed to);

    modifier onlyOwner() {
        require(msg.sender == owner, 'NOT_OWNER');
        _;
    }

    constructor(
        INonfungiblePositionManager _positions,
        address _treasury,
        uint16 _defaultCreatorBps
    ) {
        require(_treasury != address(0), 'TREASURY');
        require(_defaultCreatorBps <= BPS, 'BPS');
        positions = _positions;
        treasury = _treasury;
        defaultCreatorBps = _defaultCreatorBps;
        owner = msg.sender;
        emit OwnerChanged(address(0), msg.sender);
        emit TreasuryChanged(address(0), _treasury);
        emit DefaultCreatorBpsChanged(0, _defaultCreatorBps);
    }

    /// @notice Accepts a position NFT and locks it permanently
    /// @dev `data` is the abi-encoded creator address. Anything else is refused rather than defaulted:
    /// a position locked to nobody would pay its whole fee stream to the treasury forever, silently,
    /// and there would be no way back because the NFT can never leave.
    function onERC721Received(
        address,
        address,
        uint256 tokenId,
        bytes calldata data
    ) external override returns (bytes4) {
        require(msg.sender == address(positions), 'NOT_POSITION');
        require(data.length == 32, 'NO_CREATOR');
        address creator = abi.decode(data, (address));
        require(creator != address(0), 'NO_CREATOR');
        require(!locks[tokenId].locked, 'LOCKED');

        uint16 bps = defaultCreatorBps;
        locks[tokenId] = Lock({creator: creator, creatorBps: bps, locked: true});
        lockedIds.push(tokenId);

        emit Locked(tokenId, creator, bps);
        return this.onERC721Received.selector;
    }

    /// @notice Collects a locked position's accrued fees and pays them out
    /// @dev Permissionless on purpose: the creator should not need this contract's operator, or any
    /// key at all, to be paid. Nothing accumulates here -- collecting and splitting is one call.
    function collect(uint256 tokenId)
        external
        returns (
            uint256 creatorAmount0,
            uint256 creatorAmount1,
            uint256 treasuryAmount0,
            uint256 treasuryAmount1
        )
    {
        Lock memory lock = locks[tokenId];
        require(lock.locked, 'NOT_LOCKED');

        (, , address token0, address token1, , , , , , , , ) = positions.positions(tokenId);

        (uint256 amount0, uint256 amount1) = positions.collect(
            INonfungiblePositionManager.CollectParams({
                tokenId: tokenId,
                recipient: address(this),
                amount0Max: type(uint128).max,
                amount1Max: type(uint128).max
            })
        );

        // amount0 and amount1 are bounded by uint128, so the multiplication cannot overflow uint256.
        creatorAmount0 = (amount0 * lock.creatorBps) / BPS;
        creatorAmount1 = (amount1 * lock.creatorBps) / BPS;
        treasuryAmount0 = amount0 - creatorAmount0;
        treasuryAmount1 = amount1 - creatorAmount1;

        address _treasury = treasury;
        if (creatorAmount0 > 0) TransferHelper.safeTransfer(token0, lock.creator, creatorAmount0);
        if (creatorAmount1 > 0) TransferHelper.safeTransfer(token1, lock.creator, creatorAmount1);
        if (treasuryAmount0 > 0) TransferHelper.safeTransfer(token0, _treasury, treasuryAmount0);
        if (treasuryAmount1 > 0) TransferHelper.safeTransfer(token1, _treasury, treasuryAmount1);

        emit Collected(
            tokenId,
            lock.creator,
            creatorAmount0,
            creatorAmount1,
            treasuryAmount0,
            treasuryAmount1
        );
    }

    /// @notice What `collect` would pay out right now, before it is called
    function claimable(uint256 tokenId)
        external
        view
        returns (
            uint256 creatorAmount0,
            uint256 creatorAmount1,
            uint256 treasuryAmount0,
            uint256 treasuryAmount1
        )
    {
        Lock memory lock = locks[tokenId];
        require(lock.locked, 'NOT_LOCKED');

        (uint256 amount0, uint256 amount1) = PositionValue.fees(positions, tokenId);
        creatorAmount0 = (amount0 * lock.creatorBps) / BPS;
        creatorAmount1 = (amount1 * lock.creatorBps) / BPS;
        treasuryAmount0 = amount0 - creatorAmount0;
        treasuryAmount1 = amount1 - creatorAmount1;
    }

    /// @notice How many positions are locked here
    function lockedCount() external view returns (uint256) {
        return lockedIds.length;
    }

    /// @notice Hands this position's fee stream to somebody else
    /// @dev Only the current creator. A project that changes hands should not need our permission,
    /// and we should not be able to redirect their fees without them.
    function setCreator(uint256 tokenId, address to) external {
        Lock storage lock = locks[tokenId];
        require(lock.locked, 'NOT_LOCKED');
        require(msg.sender == lock.creator, 'NOT_CREATOR');
        require(to != address(0), 'ZERO');
        emit CreatorChanged(tokenId, lock.creator, to);
        lock.creator = to;
    }

    /// @notice Sets the creator share for positions locked from now on
    /// @dev Existing locks keep the share they were accepted under; see the note on this contract.
    function setDefaultCreatorBps(uint16 bps) external onlyOwner {
        require(bps <= BPS, 'BPS');
        emit DefaultCreatorBpsChanged(defaultCreatorBps, bps);
        defaultCreatorBps = bps;
    }

    function setTreasury(address to) external onlyOwner {
        require(to != address(0), 'ZERO');
        emit TreasuryChanged(treasury, to);
        treasury = to;
    }

    function setOwner(address to) external onlyOwner {
        emit OwnerChanged(owner, to);
        owner = to;
    }
}
