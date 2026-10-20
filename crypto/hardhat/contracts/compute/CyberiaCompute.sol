// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ReentrancyGuard} from "@openzeppelin/contracts/security/ReentrancyGuard.sol";

/// @title CyberiaCompute — a container, paid for by the second, in native CYBER.
/// @notice A service (or the treasury of one) opens a *lease* with a compute
///         provider: a rate in wei per second and a prepaid balance. Nothing is
///         invoiced and nothing is signed per payment — the provider's share
///         accrues continuously out of the prepaid balance, and either side can
///         read exactly how much is owed at any second. That is what makes it a
///         micropayment: a lease paying 0.5 CYBER a day moves about 5.8 gwei-
///         CYBER per second, and settling it is one call whenever anybody
///         likes.
///
///         The two ways this goes wrong are answered by two different things.
///
///         The payer does not pay: impossible beyond the balance, because
///         accrual is capped by what was prepaid. The provider's agent reads the
///         runway and stops the container when it is gone, so a provider never
///         works more than its own reaction time for free.
///
///         The provider does not run the container: the payer (or the lease's
///         manager — a watcher that probes the service) pauses or closes the
///         lease, which stops accrual that same block. The most a lying provider
///         earns is the rate times the watcher's detection window. Anything
///         worse — taking the money and serving a different image, going dark
///         on many leases at once — is what the provider's bond exists for, and
///         slashing it is a governance decision, because no contract can see a
///         container.
///
///         Who may provide is decided off-chain and recorded here by the owner
///         (the DAO's timelock): the node-operator threshold is 5% of CYBER.sol
///         on Solana, which this chain cannot read.
contract CyberiaCompute is ReentrancyGuard {
    /// @notice Fee ceiling, whoever owns the contract: 10%.
    uint16 public constant MAX_FEE_BPS = 1000;

    /// @notice A lease must open with at least this much runway prepaid, so a
    ///         provider that starts a container is paid for starting it.
    uint64 public constant MIN_PREPAY = 1 hours;

    /// @notice Time between asking for a bond back and getting it — long enough
    ///         for a slashing vote to pass.
    uint64 public constant UNBOND_DELAY = 7 days;

    struct Provider {
        uint128 bond;
        uint64 unbondAt; // 0 while bonded
        uint32 activeLeases;
        bool approved;
        string endpoint;
    }

    struct Lease {
        address payer;
        address provider;
        address manager; // may pause/close/update the spec on the payer's behalf
        uint128 rate; // wei per second
        uint128 balance; // prepaid wei not yet settled to anyone
        uint64 settledAt; // accrual is settled up to this second
        uint64 pausedAt; // 0 while running
        bool closed;
        bytes32 specHash; // keccak256 of the canonical service manifest
        string specURI; // where the manifest can be fetched (ipfs://…)
    }

    address public owner;
    address public feeRecipient;
    uint16 public feeBps;
    uint256 public minBond;

    mapping(address => Provider) public providers;
    mapping(uint256 => Lease) internal _leases;
    uint256 public leaseCount;

    /// @notice Wei anyone may withdraw: provider earnings, protocol fees and
    ///         refunds. Refunds are pulled rather than pushed so a payer that
    ///         rejects ether cannot stop a provider from closing a lease.
    mapping(address => uint256) public claimable;

    mapping(address => uint256[]) internal _byProvider;
    mapping(address => uint256[]) internal _byPayer;

    event ProviderRegistered(address indexed provider, uint256 bond, string endpoint);
    event ProviderApproved(address indexed provider, bool approved);
    event UnbondRequested(address indexed provider, uint64 at);
    event BondWithdrawn(address indexed provider, uint256 amount);
    event Slashed(address indexed provider, uint256 amount, address indexed to, string reason);
    event LeaseOpened(
        uint256 indexed id,
        address indexed payer,
        address indexed provider,
        address manager,
        uint256 rate,
        uint256 deposit,
        bytes32 specHash,
        string specURI
    );
    event ToppedUp(uint256 indexed id, address indexed from, uint256 amount);
    event Settled(uint256 indexed id, uint256 toProvider, uint256 fee);
    event Paused(uint256 indexed id, address by);
    event Resumed(uint256 indexed id, address by);
    event SpecUpdated(uint256 indexed id, bytes32 specHash, string specURI);
    event LeaseClosed(uint256 indexed id, address by, uint256 refund);
    event UsageReported(
        uint256 indexed id, uint64 periodStart, uint64 periodEnd, bytes32 usageHash, string uri
    );
    event Withdrawn(address indexed to, uint256 amount);
    event FeeSet(address recipient, uint16 bps);
    event MinBondSet(uint256 minBond);
    event OwnershipTransferred(address indexed from, address indexed to);

    modifier onlyOwner() {
        require(msg.sender == owner, "not owner");
        _;
    }

    constructor(address initialOwner, address initialFeeRecipient, uint16 initialFeeBps, uint256 initialMinBond) {
        require(initialFeeBps <= MAX_FEE_BPS, "fee too high");
        owner = initialOwner == address(0) ? msg.sender : initialOwner;
        feeRecipient = initialFeeRecipient == address(0) ? owner : initialFeeRecipient;
        feeBps = initialFeeBps;
        minBond = initialMinBond;
        emit OwnershipTransferred(address(0), owner);
        emit FeeSet(feeRecipient, feeBps);
        emit MinBondSet(minBond);
    }

    // ── providers ──────────────────────────────────────────────────────────

    /// @notice Post (or add to) a bond and name the endpoint the agent answers
    ///         on. Registering again cancels a pending unbond.
    function registerProvider(string calldata endpoint) external payable {
        Provider storage p = providers[msg.sender];
        p.bond += uint128(msg.value);
        p.endpoint = endpoint;
        p.unbondAt = 0;
        require(p.bond >= minBond, "bond too small");
        emit ProviderRegistered(msg.sender, p.bond, endpoint);
    }

    function setProviderApproved(address provider, bool approved) external onlyOwner {
        providers[provider].approved = approved;
        emit ProviderApproved(provider, approved);
    }

    /// @notice Stop taking new leases and start the clock on the bond. Leases
    ///         already open keep running until somebody closes them.
    function requestUnbond() external {
        Provider storage p = providers[msg.sender];
        require(p.bond > 0, "no bond");
        p.unbondAt = uint64(block.timestamp) + UNBOND_DELAY;
        emit UnbondRequested(msg.sender, p.unbondAt);
    }

    function withdrawBond() external nonReentrant {
        Provider storage p = providers[msg.sender];
        require(p.unbondAt != 0 && block.timestamp >= p.unbondAt, "still bonded");
        require(p.activeLeases == 0, "leases open");
        uint256 amount = p.bond;
        p.bond = 0;
        p.unbondAt = 0;
        _send(msg.sender, amount);
        emit BondWithdrawn(msg.sender, amount);
    }

    /// @notice Take part of a provider's bond, typically to the payer it failed.
    function slash(address provider, uint256 amount, address to, string calldata reason)
        external
        onlyOwner
        nonReentrant
    {
        Provider storage p = providers[provider];
        require(amount <= p.bond, "exceeds bond");
        p.bond -= uint128(amount);
        _send(to, amount);
        emit Slashed(provider, amount, to, reason);
    }

    // ── leases ─────────────────────────────────────────────────────────────

    function openLease(
        address provider,
        uint128 rate,
        address manager,
        bytes32 specHash,
        string calldata specURI
    ) external payable returns (uint256 id) {
        Provider storage p = providers[provider];
        require(p.approved && p.unbondAt == 0 && p.bond >= minBond, "provider unavailable");
        require(rate > 0, "zero rate");
        require(msg.value >= uint256(rate) * MIN_PREPAY, "prepay too small");
        require(specHash != bytes32(0), "no spec");

        id = ++leaseCount;
        Lease storage l = _leases[id];
        l.payer = msg.sender;
        l.provider = provider;
        l.manager = manager;
        l.rate = rate;
        l.balance = uint128(msg.value);
        l.settledAt = uint64(block.timestamp);
        l.specHash = specHash;
        l.specURI = specURI;
        p.activeLeases += 1;

        _byProvider[provider].push(id);
        _byPayer[msg.sender].push(id);
        emit LeaseOpened(id, msg.sender, provider, manager, rate, msg.value, specHash, specURI);
    }

    /// @notice Anyone may extend a lease's runway.
    /// @dev Settles first, so a lease that ran dry and is topped up later does
    ///      not pay for the time it was empty — the container was meant to be
    ///      stopped then.
    function topUp(uint256 id) external payable {
        Lease storage l = _open(id);
        _settle(id, l);
        l.balance += uint128(msg.value);
        emit ToppedUp(id, msg.sender, msg.value);
    }

    /// @notice Move whatever has accrued to the provider. Anybody may call it.
    function settle(uint256 id) external {
        Lease storage l = _leases[id];
        require(l.payer != address(0), "no lease");
        _settle(id, l);
    }

    function pause(uint256 id) external {
        Lease storage l = _open(id);
        _onlyPayerOrManager(l);
        require(l.pausedAt == 0, "paused");
        _settle(id, l);
        l.pausedAt = uint64(block.timestamp);
        emit Paused(id, msg.sender);
    }

    function resume(uint256 id) external {
        Lease storage l = _open(id);
        _onlyPayerOrManager(l);
        require(l.pausedAt != 0, "running");
        l.pausedAt = 0;
        l.settledAt = uint64(block.timestamp);
        emit Resumed(id, msg.sender);
    }

    /// @notice Point the lease at a new manifest — a new image digest is a new
    ///         release. The rate does not change; a new price is a new lease.
    function updateSpec(uint256 id, bytes32 specHash, string calldata specURI) external {
        Lease storage l = _open(id);
        _onlyPayerOrManager(l);
        require(specHash != bytes32(0), "no spec");
        l.specHash = specHash;
        l.specURI = specURI;
        emit SpecUpdated(id, specHash, specURI);
    }

    /// @notice End a lease. The payer or manager closes when it no longer wants
    ///         the service; the provider closes when it no longer wants to run
    ///         it. Either way accrual is settled and the rest goes back to the
    ///         payer as a claimable refund.
    function close(uint256 id) external {
        Lease storage l = _open(id);
        require(msg.sender == l.payer || msg.sender == l.manager || msg.sender == l.provider, "not a party");
        _settle(id, l);
        uint256 refund = l.balance;
        l.balance = 0;
        l.closed = true;
        providers[l.provider].activeLeases -= 1;
        claimable[l.payer] += refund;
        emit LeaseClosed(id, msg.sender, refund);
    }

    /// @notice The provider's agent commits to what it measured for a period:
    ///         a hash of the usage report and where to read it. Moves no money —
    ///         it is the evidence a dispute or a slashing vote reads.
    function reportUsage(uint256 id, uint64 periodStart, uint64 periodEnd, bytes32 usageHash, string calldata uri)
        external
    {
        Lease storage l = _leases[id];
        require(msg.sender == l.provider, "not provider");
        require(periodEnd > periodStart, "empty period");
        emit UsageReported(id, periodStart, periodEnd, usageHash, uri);
    }

    function withdraw() external nonReentrant {
        uint256 amount = claimable[msg.sender];
        require(amount > 0, "nothing to withdraw");
        claimable[msg.sender] = 0;
        _send(msg.sender, amount);
        emit Withdrawn(msg.sender, amount);
    }

    // ── reads ──────────────────────────────────────────────────────────────

    function lease(uint256 id) external view returns (Lease memory) {
        return _leases[id];
    }

    /// @notice Wei accrued and not yet settled.
    function accrued(uint256 id) public view returns (uint256) {
        return _owed(_leases[id]);
    }

    /// @notice Prepaid wei that has not accrued to anyone yet.
    function remaining(uint256 id) public view returns (uint256) {
        Lease storage l = _leases[id];
        return l.balance - _owed(l);
    }

    /// @notice Seconds of runway left at the lease's rate. A paused lease
    ///         reports what resuming it would buy; a closed one, zero.
    function runway(uint256 id) external view returns (uint256) {
        Lease storage l = _leases[id];
        if (l.rate == 0 || l.closed) return 0;
        return remaining(id) / l.rate;
    }

    function leasesOfProvider(address provider) external view returns (uint256[] memory) {
        return _byProvider[provider];
    }

    function leasesOfPayer(address payer) external view returns (uint256[] memory) {
        return _byPayer[payer];
    }

    // ── owner ──────────────────────────────────────────────────────────────

    function setFee(address recipient, uint16 bps) external onlyOwner {
        require(bps <= MAX_FEE_BPS, "fee too high");
        require(recipient != address(0), "zero recipient");
        feeRecipient = recipient;
        feeBps = bps;
        emit FeeSet(recipient, bps);
    }

    function setMinBond(uint256 amount) external onlyOwner {
        minBond = amount;
        emit MinBondSet(amount);
    }

    function transferOwnership(address to) external onlyOwner {
        require(to != address(0), "zero owner");
        emit OwnershipTransferred(owner, to);
        owner = to;
    }

    // ── internals ──────────────────────────────────────────────────────────

    function _open(uint256 id) internal view returns (Lease storage l) {
        l = _leases[id];
        require(l.payer != address(0), "no lease");
        require(!l.closed, "closed");
    }

    function _onlyPayerOrManager(Lease storage l) internal view {
        require(msg.sender == l.payer || msg.sender == l.manager, "not payer");
    }

    function _owed(Lease storage l) internal view returns (uint256) {
        if (l.closed) return 0;
        uint256 end = l.pausedAt != 0 ? l.pausedAt : block.timestamp;
        if (end <= l.settledAt) return 0;
        uint256 owed = uint256(l.rate) * (end - l.settledAt);
        return owed > l.balance ? l.balance : owed;
    }

    function _settle(uint256 id, Lease storage l) internal {
        uint256 owed = _owed(l);
        uint256 end = l.pausedAt != 0 ? l.pausedAt : block.timestamp;
        if (end > l.settledAt) l.settledAt = uint64(end);
        if (owed == 0) return;
        l.balance -= uint128(owed);
        uint256 fee = (owed * feeBps) / 10_000;
        claimable[l.provider] += owed - fee;
        if (fee > 0) claimable[feeRecipient] += fee;
        emit Settled(id, owed - fee, fee);
    }

    function _send(address to, uint256 amount) internal {
        if (amount == 0) return;
        (bool sent, ) = to.call{value: amount}("");
        require(sent, "send failed");
    }
}
