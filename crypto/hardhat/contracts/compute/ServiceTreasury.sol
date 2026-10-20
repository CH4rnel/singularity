// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ReentrancyGuard} from "@openzeppelin/contracts/security/ReentrancyGuard.sol";
import {CyberiaCompute} from "./CyberiaCompute.sol";

/// @title ServiceTreasury — a service's own wallet, which pays for its own compute.
/// @notice One per service (the DEX, the launchpad, prediction markets, the
///         explorer…). The service's fees are sent here — it is an ordinary
///         address as far as a fee recipient is concerned — and the treasury is
///         the payer on the service's compute leases.
///
///         The point is that paying for compute needs no vote. The DAO decides
///         *policy* once, in one proposal through its timelock — which provider,
///         at what rate, how many days of runway to keep prepaid, how much may
///         leave in a day, how much is never spent on compute — and from then on
///         `refill()` is permissionless: any keeper, any agent, any passer-by
///         can call it, and the most it can ever do is move this treasury's own
///         money into this treasury's own leases, within that policy.
///
///         The guardian is the service's operations key: it can pause or close a
///         lease the service is not getting (its watcher does that) and roll out
///         a new release (a new manifest hash), but it cannot open a lease, change
///         a rate or move a single wei anywhere but back here.
contract ServiceTreasury is ReentrancyGuard {
    CyberiaCompute public immutable compute;
    string public service;

    address public owner; // the DAO's timelock
    address public guardian;

    /// @notice Seconds of prepaid runway refill() tops every lease up to.
    uint64 public runwayTarget;
    /// @notice Wei refill() may move into leases in one UTC day.
    uint256 public maxRefillPerDay;
    /// @notice Wei this treasury keeps whatever its leases want.
    uint256 public reserve;

    mapping(uint256 => uint256) public refilledOnDay;
    uint256[] internal _leases;

    event Received(address indexed from, uint256 amount);
    event LeaseAdded(uint256 indexed id, address indexed provider, uint256 rate, uint256 deposit);
    event LeaseDropped(uint256 indexed id);
    event Refilled(uint256 indexed id, uint256 amount, address indexed keeper);
    event PolicySet(uint64 runwayTarget, uint256 maxRefillPerDay, uint256 reserve);
    event GuardianSet(address guardian);
    event Withdrawn(address indexed to, uint256 amount);
    event OwnershipTransferred(address indexed from, address indexed to);

    modifier onlyOwner() {
        require(msg.sender == owner, "not owner");
        _;
    }

    modifier onlyOwnerOrGuardian() {
        require(msg.sender == owner || msg.sender == guardian, "not guardian");
        _;
    }

    constructor(
        CyberiaCompute compute_,
        string memory service_,
        address owner_,
        address guardian_,
        uint64 runwayTarget_,
        uint256 maxRefillPerDay_,
        uint256 reserve_
    ) {
        compute = compute_;
        service = service_;
        owner = owner_ == address(0) ? msg.sender : owner_;
        guardian = guardian_;
        runwayTarget = runwayTarget_;
        maxRefillPerDay = maxRefillPerDay_;
        reserve = reserve_;
        emit OwnershipTransferred(address(0), owner);
        emit GuardianSet(guardian_);
        emit PolicySet(runwayTarget_, maxRefillPerDay_, reserve_);
    }

    /// @notice Fees, grants and refunds all arrive here.
    receive() external payable {
        if (msg.sender != address(compute)) emit Received(msg.sender, msg.value);
    }

    // ── what the DAO votes on ──────────────────────────────────────────────

    /// @notice Hire a provider. The guardian is the lease's manager.
    function openLease(address provider, uint128 rate, bytes32 specHash, string calldata specURI, uint256 deposit)
        external
        onlyOwner
        returns (uint256 id)
    {
        require(deposit <= address(this).balance, "insufficient funds");
        id = compute.openLease{value: deposit}(provider, rate, guardian, specHash, specURI);
        _leases.push(id);
        emit LeaseAdded(id, provider, rate, deposit);
    }

    function setPolicy(uint64 runwayTarget_, uint256 maxRefillPerDay_, uint256 reserve_) external onlyOwner {
        runwayTarget = runwayTarget_;
        maxRefillPerDay = maxRefillPerDay_;
        reserve = reserve_;
        emit PolicySet(runwayTarget_, maxRefillPerDay_, reserve_);
    }

    /// @notice A new guardian manages leases opened from now on; existing
    ///         leases keep the manager they were opened with until replaced.
    function setGuardian(address guardian_) external onlyOwner {
        guardian = guardian_;
        emit GuardianSet(guardian_);
    }

    function withdraw(address to, uint256 amount) external onlyOwner nonReentrant {
        (bool sent, ) = to.call{value: amount}("");
        require(sent, "send failed");
        emit Withdrawn(to, amount);
    }

    function transferOwnership(address to) external onlyOwner {
        require(to != address(0), "zero owner");
        emit OwnershipTransferred(owner, to);
        owner = to;
    }

    // ── what operations may do ─────────────────────────────────────────────

    function pauseLease(uint256 id) external onlyOwnerOrGuardian {
        compute.pause(id);
    }

    function resumeLease(uint256 id) external onlyOwnerOrGuardian {
        compute.resume(id);
    }

    function updateSpec(uint256 id, bytes32 specHash, string calldata specURI) external onlyOwnerOrGuardian {
        compute.updateSpec(id, specHash, specURI);
    }

    /// @notice Close a lease and bring its unspent balance home.
    function closeLease(uint256 id) external onlyOwnerOrGuardian {
        compute.close(id);
        _drop(id);
        _reclaim();
    }

    // ── what anybody may do ────────────────────────────────────────────────

    /// @notice Top every live lease up to `runwayTarget` seconds of runway, as
    ///         far as the daily limit and the reserve allow. Leases closed by
    ///         their provider are dropped and their refunds reclaimed.
    function refill() external nonReentrant {
        uint256 day = block.timestamp / 1 days;
        uint256 i = 0;
        while (i < _leases.length) {
            uint256 id = _leases[i];
            CyberiaCompute.Lease memory l = compute.lease(id);
            if (l.closed) {
                _leases[i] = _leases[_leases.length - 1];
                _leases.pop();
                emit LeaseDropped(id);
                continue;
            }
            i++;
            if (l.pausedAt != 0) continue;

            uint256 want = uint256(l.rate) * runwayTarget;
            uint256 have = compute.remaining(id);
            if (have >= want) continue;
            uint256 amount = want - have;

            // The policy may have been lowered below what today already moved.
            uint256 spent = refilledOnDay[day];
            uint256 dayLeft = maxRefillPerDay > spent ? maxRefillPerDay - spent : 0;
            if (amount > dayLeft) amount = dayLeft;
            uint256 spendable = address(this).balance > reserve ? address(this).balance - reserve : 0;
            if (amount > spendable) amount = spendable;
            if (amount == 0) continue;

            refilledOnDay[day] += amount;
            compute.topUp{value: amount}(id);
            emit Refilled(id, amount, msg.sender);
        }
        if (compute.claimable(address(this)) > 0) _reclaim();
    }

    /// @notice Pull refunds the compute contract is holding for this treasury.
    function reclaim() external {
        _reclaim();
    }

    function leases() external view returns (uint256[] memory) {
        return _leases;
    }

    /// @notice Seconds the whole service can keep running on what it has:
    ///         prepaid runway plus everything above the reserve, at the sum of
    ///         its live rates. What a dashboard should print beside the service.
    function totalRunway() external view returns (uint256) {
        uint256 rate;
        uint256 funds = address(this).balance > reserve ? address(this).balance - reserve : 0;
        for (uint256 i = 0; i < _leases.length; i++) {
            CyberiaCompute.Lease memory l = compute.lease(_leases[i]);
            if (l.closed || l.pausedAt != 0) continue;
            rate += l.rate;
            funds += compute.remaining(_leases[i]);
        }
        return rate == 0 ? 0 : funds / rate;
    }

    function _reclaim() internal {
        if (compute.claimable(address(this)) > 0) compute.withdraw();
    }

    function _drop(uint256 id) internal {
        for (uint256 i = 0; i < _leases.length; i++) {
            if (_leases[i] == id) {
                _leases[i] = _leases[_leases.length - 1];
                _leases.pop();
                emit LeaseDropped(id);
                return;
            }
        }
    }
}
