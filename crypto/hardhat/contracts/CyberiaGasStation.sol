// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

/// @title CyberiaGasStation — a tank of CYBER that pays other people's first fee.
/// @notice The wallet is non-custodial and browser-side, so nothing about a user
///         is known here except an address. That is enough for the only problem
///         this contract solves: an address that holds something on Cyberia but
///         holds no CYBER cannot move any of it, because a fee is payable only
///         in the coin the chain runs on.
///
///         So the station hands that address a small amount of CYBER — enough
///         for dozens of transactions, worth far less than the assets already
///         sitting in it — and the user then signs their own transaction in the
///         ordinary way. Nothing about signing changes; there is no forwarder,
///         no smart account, no meta-transaction. That is the whole point: every
///         action the wallet can perform is covered by this, including the ones
///         nobody has written yet.
///
///         Who *deserves* a drip is a question about the world (does this
///         address hold anything? has it asked ten times today from one IP?) and
///         is answered off-chain by the backend, which holds an operator key.
///         What the station will do *at most*, no matter who is asking or who
///         has stolen that key, is answered here: a fixed amount, to an address
///         below a balance ceiling, once per cooldown, under a daily cap. That
///         split is deliberate. The operator key lives on an internet-facing
///         server; the tank does not have to.
contract CyberiaGasStation {
    /// @notice Owner: sets policy, appoints operators, and can drain the tank.
    address public owner;

    /// @notice Addresses allowed to pull a drip out of the tank on someone's behalf.
    /// @dev The backend's dedicated sponsor EOA. Deliberately not the bridge
    ///      relayer: that key is shared with the Telegram minter and the DCA bot,
    ///      and transactions from it already race each other for nonces.
    mapping(address => bool) public isOperator;

    /// @notice Wei handed to one address per claim.
    uint256 public dripAmount;

    /// @notice A claim is only allowed while the recipient holds less than this.
    /// @dev Sized to the most expensive single action the wallet can build, so
    ///      "below the ceiling" means "cannot reliably pay for one transaction"
    ///      rather than "is not rich".
    uint256 public balanceCeiling;

    /// @notice Seconds one address must wait between claims.
    uint256 public cooldown;

    /// @notice Wei the station will spend in one UTC day, across everyone.
    /// @dev The ceiling on a compromised operator key. A day is a fixed bucket
    ///      (timestamp / 1 days), not a rolling window, so no one has to call
    ///      anything to reset it.
    uint256 public dailyCap;

    /// @notice Stops all claims without touching policy or moving funds.
    bool public paused;

    /// @notice Last time each address was sponsored, as a unix timestamp.
    mapping(address => uint256) public lastClaimAt;

    /// @notice How many times each address has been sponsored, ever.
    mapping(address => uint256) public claimCount;

    /// @notice Wei spent per UTC day bucket.
    mapping(uint256 => uint256) public spentOnDay;

    /// @notice Wei this station has handed out since deployment.
    uint256 public totalSponsored;

    /// @notice Claims served since deployment.
    uint256 public sponsoredCount;

    event Funded(address indexed from, uint256 amount);
    event Sponsored(address indexed to, uint256 amount, address indexed operator);
    event OperatorSet(address indexed operator, bool allowed);
    event PolicySet(uint256 dripAmount, uint256 balanceCeiling, uint256 cooldown, uint256 dailyCap);
    event PausedSet(bool paused);
    event Withdrawn(address indexed to, uint256 amount);
    event OwnershipTransferred(address indexed from, address indexed to);

    modifier onlyOwner() {
        require(msg.sender == owner, "not owner");
        _;
    }

    modifier onlyOperator() {
        require(isOperator[msg.sender], "not operator");
        _;
    }

    /// @param initialOwner Owner of the station; the deployer when zero.
    /// @dev The owner is an operator from the start so a freshly deployed
    ///      station is usable from the console before a backend key exists.
    constructor(address initialOwner) payable {
        owner = initialOwner == address(0) ? msg.sender : initialOwner;
        isOperator[owner] = true;

        // Defaults in CYBER at Cyberia's ~1.5 gwei pool floor: 0.01 covers
        // roughly three hundred coin transfers, fifty token transfers or a
        // dozen swaps at the wallet's own gas cap, and the ceiling is one
        // swap's worth — the point below which an address is stuck.
        dripAmount = 0.01 ether;
        balanceCeiling = 0.001 ether;
        cooldown = 6 hours;
        dailyCap = 5 ether;

        emit OperatorSet(owner, true);
        emit PolicySet(dripAmount, balanceCeiling, cooldown, dailyCap);

        if (msg.value > 0) {
            emit Funded(msg.sender, msg.value);
        }
    }

    /// @notice Anyone may fund the tank. The treasury does; so could a DAO, a
    ///         token project paying for its own users, or a passer-by.
    receive() external payable {
        emit Funded(msg.sender, msg.value);
    }

    /// @notice Send one drip to `to`, paid for by the tank.
    /// @dev Reverts rather than returning false: the operator asks `canClaim`
    ///      first, so a revert here means the world moved between the two calls
    ///      (or someone is trying it on), and both deserve a failed transaction.
    /// @return amount Wei actually sent.
    function claim(address payable to) external onlyOperator returns (uint256 amount) {
        require(to != address(0), "zero recipient");
        require(!paused, "paused");

        (bool ok, string memory reason) = canClaim(to);
        require(ok, reason);

        amount = dripAmount;
        uint256 day = block.timestamp / 1 days;

        // Effects before interaction: the cooldown and the day's tally are
        // written first, so a recipient that is a contract cannot re-enter this
        // and claim twice — the second attempt fails its own cooldown check.
        lastClaimAt[to] = block.timestamp;
        claimCount[to] += 1;
        spentOnDay[day] += amount;
        totalSponsored += amount;
        sponsoredCount += 1;

        (bool sent, ) = to.call{value: amount}("");
        require(sent, "transfer failed");

        emit Sponsored(to, amount, msg.sender);
    }

    /// @notice Whether `to` may be sponsored right now, and why not when it may not.
    /// @dev A view, so the backend and the wallet read the same policy this
    ///      contract enforces instead of keeping a second copy of it that can
    ///      drift. The reason strings are what `claim` reverts with.
    function canClaim(address to) public view returns (bool ok, string memory reason) {
        if (paused) {
            return (false, "paused");
        }

        if (address(this).balance < dripAmount) {
            return (false, "station empty");
        }

        if (to.balance >= balanceCeiling) {
            return (false, "has gas");
        }

        if (lastClaimAt[to] != 0 && block.timestamp < lastClaimAt[to] + cooldown) {
            return (false, "cooling down");
        }

        if (spentOnDay[block.timestamp / 1 days] + dripAmount > dailyCap) {
            return (false, "daily cap reached");
        }

        return (true, "");
    }

    /// @notice Wei the station may still spend today.
    function remainingToday() external view returns (uint256) {
        uint256 spent = spentOnDay[block.timestamp / 1 days];

        return spent >= dailyCap ? 0 : dailyCap - spent;
    }

    /// @notice Seconds until `to` may claim again; zero when it may claim now.
    function cooldownRemaining(address to) external view returns (uint256) {
        uint256 last = lastClaimAt[to];

        if (last == 0 || block.timestamp >= last + cooldown) {
            return 0;
        }

        return last + cooldown - block.timestamp;
    }

    /// @notice Everything an operator or a status page needs, in one call.
    function summary()
        external
        view
        returns (
            uint256 tank,
            uint256 drip,
            uint256 ceiling,
            uint256 wait,
            uint256 cap,
            uint256 remaining,
            uint256 servedTotal,
            uint256 spentTotal,
            bool isPaused
        )
    {
        uint256 spent = spentOnDay[block.timestamp / 1 days];

        return (
            address(this).balance,
            dripAmount,
            balanceCeiling,
            cooldown,
            dailyCap,
            spent >= dailyCap ? 0 : dailyCap - spent,
            sponsoredCount,
            totalSponsored,
            paused
        );
    }

    /// @notice How many drips the tank can still serve at the current amount.
    function dripsLeft() external view returns (uint256) {
        return dripAmount == 0 ? 0 : address(this).balance / dripAmount;
    }

    function setOperator(address operator, bool allowed) external onlyOwner {
        require(operator != address(0), "zero operator");
        isOperator[operator] = allowed;

        emit OperatorSet(operator, allowed);
    }

    /// @dev A zero cooldown or a zero ceiling is allowed — both are policy an
    ///      operator may legitimately want — but a zero drip is not, since it
    ///      would burn a transaction to give nothing.
    function setPolicy(
        uint256 newDripAmount,
        uint256 newBalanceCeiling,
        uint256 newCooldown,
        uint256 newDailyCap
    ) external onlyOwner {
        require(newDripAmount > 0, "zero drip");
        require(newDailyCap >= newDripAmount, "cap below drip");

        dripAmount = newDripAmount;
        balanceCeiling = newBalanceCeiling;
        cooldown = newCooldown;
        dailyCap = newDailyCap;

        emit PolicySet(newDripAmount, newBalanceCeiling, newCooldown, newDailyCap);
    }

    function setPaused(bool newPaused) external onlyOwner {
        paused = newPaused;

        emit PausedSet(newPaused);
    }

    /// @notice Take CYBER back out of the tank.
    function withdraw(address payable to, uint256 amount) external onlyOwner {
        require(to != address(0), "zero recipient");
        require(amount <= address(this).balance, "insufficient tank");

        (bool sent, ) = to.call{value: amount}("");
        require(sent, "transfer failed");

        emit Withdrawn(to, amount);
    }

    function transferOwnership(address newOwner) external onlyOwner {
        require(newOwner != address(0), "zero owner");

        emit OwnershipTransferred(owner, newOwner);
        owner = newOwner;
    }
}
