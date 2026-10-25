// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {SafeERC20} from "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";
import {ReentrancyGuard} from "@openzeppelin/contracts/security/ReentrancyGuard.sol";

/// @title LainStakingVault
/// @notice Single-token staking for LAIN where the reward *is* the stake: it
/// streams in every second and compounds by itself, so there is nothing to
/// claim and nothing to re-stake by hand.
///
/// Every tier is a share pool. A position holds shares of its tier, and the
/// tier's assets grow as the stream is paid into it, so the value of a share
/// rises second by second. The stream is split between tiers by weight —
/// assets × boost — so a 90-day lock earns twice what the same amount earns
/// unlocked. Within a tier everybody earns the same rate.
///
/// The owner can do exactly two things: add a tier and set how long the
/// tokens already in the reserve take to stream out (never sooner than the
/// promise already made). It cannot withdraw the reserve or anybody's stake;
/// once LAIN is sent here it leaves only as a staker's withdrawal.
///
/// A lock that has run out stops earning its boost: anybody may `kick` it into
/// the flexible tier, and the owner of the position may `restake` it into any
/// tier for a fresh lock.
contract LainStakingVault is Ownable, ReentrancyGuard {
    using SafeERC20 for IERC20;

    struct Tier {
        uint64 lockSeconds;
        uint32 boostBps;
        uint256 assets;
        uint256 shares;
    }

    struct Position {
        uint8 tier;
        uint64 unlockAt;
        uint256 shares;
    }

    uint256 public constant MAX_TIERS = 8;
    uint256 public constant BPS = 10_000;
    uint32 public constant MAX_BOOST_BPS = 40_000;
    uint64 public constant MAX_LOCK = 730 days;
    uint256 public constant MIN_SCHEDULE = 1 days;
    /// One whole LAIN. Below it a first deposit could be priced so high that the
    /// next depositor loses a visible fraction to rounding.
    uint256 public constant MIN_DEPOSIT = 1 ether;

    /// Virtual shares and assets per tier, so a dust first deposit cannot inflate
    /// the share price until the next depositor's shares round to nothing.
    uint256 internal constant VIRTUAL_SHARES = 1e6;
    uint256 internal constant VIRTUAL_ASSETS = 1;

    IERC20 public immutable token;

    Tier[] internal _tiers;
    mapping(address => Position[]) internal _positions;

    /// Sum of every tier's assets: staked principal plus everything streamed so far.
    uint256 public totalStaked;
    /// Wei per second paid out of the reserve until `periodFinish`.
    uint256 public rewardRate;
    uint256 public periodFinish;
    uint256 public lastUpdate;

    event TierAdded(uint256 indexed tier, uint64 lockSeconds, uint32 boostBps);
    event Scheduled(uint256 reserve, uint256 rewardRate, uint256 periodFinish);
    event Deposited(address indexed user, uint256 indexed id, uint256 tier, uint256 assets, uint256 shares, uint64 unlockAt);
    event Withdrawn(address indexed user, uint256 indexed id, uint256 tier, uint256 assets);
    event Moved(address indexed user, uint256 indexed id, uint256 fromTier, uint256 toTier, uint256 assets, uint64 unlockAt);

    constructor(IERC20 token_, address owner_) {
        require(address(token_) != address(0), "token");
        token = token_;
        _transferOwnership(owner_);
        // Tier 0 is always the flexible one: no lock, no boost. `kick` lands here.
        _tiers.push(Tier({lockSeconds: 0, boostBps: uint32(BPS), assets: 0, shares: 0}));
        emit TierAdded(0, 0, uint32(BPS));
    }

    // ── owner ────────────────────────────────────────────────────────────

    /// Tiers are append-only: changing a boost under existing locks would change
    /// the deal those stakers agreed to.
    function addTier(uint64 lockSeconds, uint32 boostBps) external onlyOwner {
        require(_tiers.length < MAX_TIERS, "tiers");
        require(lockSeconds > 0 && lockSeconds <= MAX_LOCK, "lock");
        require(boostBps >= BPS && boostBps <= MAX_BOOST_BPS, "boost");
        _update();
        _tiers.push(Tier({lockSeconds: lockSeconds, boostBps: boostBps, assets: 0, shares: 0}));
        emit TierAdded(_tiers.length - 1, lockSeconds, boostBps);
    }

    /// Streams the whole reserve (whatever was sent here and not yet paid out)
    /// evenly over `duration`. Fund by plain transfer, then call this. The end
    /// may move later, never earlier, so a published schedule only gets longer.
    function schedule(uint256 duration) external onlyOwner {
        require(duration >= MIN_SCHEDULE, "duration");
        _update();
        uint256 finish = block.timestamp + duration;
        require(finish >= periodFinish, "shorter");
        uint256 available = reserve();
        uint256 rate = available / duration;
        require(rate > 0, "empty");
        rewardRate = rate;
        periodFinish = finish;
        lastUpdate = block.timestamp;
        emit Scheduled(available, rate, finish);
    }

    // ── stakers ──────────────────────────────────────────────────────────

    function deposit(uint256 tierId, uint256 amount) external nonReentrant returns (uint256 id) {
        require(tierId < _tiers.length, "tier");
        require(amount >= MIN_DEPOSIT, "amount");
        _update();

        uint256 before = token.balanceOf(address(this));
        token.safeTransferFrom(msg.sender, address(this), amount);
        uint256 received = token.balanceOf(address(this)) - before;

        Tier storage t = _tiers[tierId];
        uint256 shares = _toShares(t, received);
        require(shares > 0, "dust");
        t.assets += received;
        t.shares += shares;
        totalStaked += received;

        uint64 unlockAt = uint64(block.timestamp) + t.lockSeconds;
        id = _positions[msg.sender].length;
        _positions[msg.sender].push(Position({tier: uint8(tierId), unlockAt: unlockAt, shares: shares}));
        emit Deposited(msg.sender, id, tierId, received, shares, unlockAt);
    }

    /// Takes the whole position out: principal and everything it earned.
    function withdraw(uint256 id) external nonReentrant returns (uint256 assets) {
        Position storage p = _position(msg.sender, id);
        require(block.timestamp >= p.unlockAt, "locked");
        _update();

        uint256 tierId = p.tier;
        assets = _redeem(p);
        totalStaked -= assets;
        token.safeTransfer(msg.sender, assets);
        emit Withdrawn(msg.sender, id, tierId, assets);
    }

    /// Moves an unlocked position, with everything it earned, into a tier and
    /// starts that tier's lock from now.
    function restake(uint256 id, uint256 tierId) external nonReentrant {
        _move(msg.sender, id, tierId);
    }

    /// Anybody may move an expired lock into the flexible tier, so an old lock
    /// cannot keep a boost it no longer pays for.
    function kick(address user, uint256 id) external nonReentrant {
        Position storage p = _position(user, id);
        require(p.tier != 0, "flexible");
        _move(user, id, 0);
    }

    // ── views ────────────────────────────────────────────────────────────

    /// LAIN here that is not staked and not yet streamed.
    function reserve() public view returns (uint256) {
        return token.balanceOf(address(this)) - totalStaked;
    }

    function tierCount() external view returns (uint256) {
        return _tiers.length;
    }

    /// A tier as of this second, the stream since the last write included.
    function tier(uint256 tierId) external view returns (uint64 lockSeconds, uint32 boostBps, uint256 assets, uint256 shares) {
        Tier memory t = _tiers[tierId];
        uint256[] memory pending = _pending();
        return (t.lockSeconds, t.boostBps, t.assets + pending[tierId], t.shares);
    }

    function positionCount(address user) external view returns (uint256) {
        return _positions[user].length;
    }

    /// A position as of this second. `assets` is what `withdraw` would pay now.
    function position(address user, uint256 id)
        external
        view
        returns (uint256 tierId, uint64 unlockAt, uint256 shares, uint256 assets)
    {
        Position memory p = _positions[user][id];
        Tier memory t = _tiers[p.tier];
        t.assets += _pending()[p.tier];
        return (p.tier, p.unlockAt, p.shares, _toAssets(t, p.shares));
    }

    /// LAIN per second this tier earns per LAIN staked in it, scaled by 1e18.
    /// Multiply by 365 days for a simple APR; the compounding comes on top.
    function ratePerToken(uint256 tierId) external view returns (uint256) {
        if (block.timestamp >= periodFinish) {
            return 0;
        }
        uint256[] memory pending = _pending();
        uint256 weight;
        for (uint256 i = 0; i < _tiers.length; i++) {
            weight += (_tiers[i].assets + pending[i]) * _tiers[i].boostBps;
        }
        if (weight == 0) {
            return 0;
        }
        return (rewardRate * _tiers[tierId].boostBps * 1e18) / weight;
    }

    // ── internals ────────────────────────────────────────────────────────

    function _position(address user, uint256 id) internal view returns (Position storage p) {
        require(id < _positions[user].length, "position");
        p = _positions[user][id];
        require(p.shares > 0, "closed");
    }

    function _move(address user, uint256 id, uint256 tierId) internal {
        require(tierId < _tiers.length, "tier");
        Position storage p = _position(user, id);
        require(block.timestamp >= p.unlockAt, "locked");
        _update();

        uint256 fromTier = p.tier;
        uint256 assets = _redeem(p);
        Tier storage t = _tiers[tierId];
        uint256 shares = _toShares(t, assets);
        require(shares > 0, "dust");
        t.assets += assets;
        t.shares += shares;

        p.tier = uint8(tierId);
        p.shares = shares;
        p.unlockAt = uint64(block.timestamp) + t.lockSeconds;
        emit Moved(user, id, fromTier, tierId, assets, p.unlockAt);
    }

    /// Burns a position's shares and returns its assets; the caller decides
    /// whether they leave the contract or land in another tier.
    function _redeem(Position storage p) internal returns (uint256 assets) {
        Tier storage t = _tiers[p.tier];
        assets = _toAssets(t, p.shares);
        t.shares -= p.shares;
        t.assets -= assets;
        p.shares = 0;
    }

    function _toShares(Tier memory t, uint256 assets) internal pure returns (uint256) {
        return (assets * (t.shares + VIRTUAL_SHARES)) / (t.assets + VIRTUAL_ASSETS);
    }

    function _toAssets(Tier memory t, uint256 shares) internal pure returns (uint256) {
        return (shares * (t.assets + VIRTUAL_ASSETS)) / (t.shares + VIRTUAL_SHARES);
    }

    /// What the stream owes each tier since `lastUpdate`. Weights are frozen
    /// between writes; every deposit, withdrawal and move is a write, so the
    /// compounding is as fine-grained as the activity.
    function _pending() internal view returns (uint256[] memory owed) {
        uint256 n = _tiers.length;
        owed = new uint256[](n);
        uint256 end = block.timestamp < periodFinish ? block.timestamp : periodFinish;
        if (end <= lastUpdate) {
            return owed;
        }
        uint256 reward = (end - lastUpdate) * rewardRate;
        uint256 available = reserve();
        if (reward > available) {
            reward = available;
        }
        uint256 weight;
        for (uint256 i = 0; i < n; i++) {
            weight += _tiers[i].assets * _tiers[i].boostBps;
        }
        if (weight == 0) {
            // Nobody staked: the slice stays in the reserve for the next schedule.
            return owed;
        }
        for (uint256 i = 0; i < n; i++) {
            owed[i] = (reward * _tiers[i].assets * _tiers[i].boostBps) / weight;
        }
    }

    function _update() internal {
        uint256[] memory owed = _pending();
        uint256 paid;
        for (uint256 i = 0; i < owed.length; i++) {
            if (owed[i] > 0) {
                _tiers[i].assets += owed[i];
                paid += owed[i];
            }
        }
        totalStaked += paid;
        uint256 end = block.timestamp < periodFinish ? block.timestamp : periodFinish;
        if (end > lastUpdate) {
            lastUpdate = end;
        }
    }
}
