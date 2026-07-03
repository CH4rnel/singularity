// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";
import {ReentrancyGuard} from "@openzeppelin/contracts/security/ReentrancyGuard.sol";

/// @title PredictionMarket — parimutuel YES/NO prediction markets in native CYBER.
/// @notice Anyone opens a market with a question and a betting deadline for a
///         flat creation fee (the oracle/owner creates for free). Anyone bets
///         native CYBER on YES or NO while the market is open. After the
///         deadline the oracle reports the outcome and winners split the whole
///         pot pro-rata to their stake; the protocol fee is taken from the
///         losing pool only, so a market with no losers pays everyone back
///         exactly.
///
///         Trust model matches the rest of the Cyberia stack (bridge relayer,
///         token owners): the oracle is a trusted EOA. Bettors are protected
///         from a vanished oracle by a hard refund window — if a market is not
///         resolved within RESOLVE_WINDOW of closing, every stake becomes
///         reclaimable and resolution is permanently disabled.
contract PredictionMarket is Ownable, ReentrancyGuard {
    enum Outcome {
        None, // open or awaiting resolution
        Yes,
        No,
        Invalid // cancelled/unresolvable: everyone refunds, no fee
    }

    struct Market {
        address creator;
        string question;
        uint64 closeTime; // betting closes at this timestamp
        uint64 resolvedAt; // 0 until resolved
        Outcome outcome;
        uint256 yesPool;
        uint256 noPool;
        // Fee actually sent at resolve time (snapshot, so later feeBps changes
        // cannot desync claim math from the contract balance).
        uint256 feePaid;
    }

    /// @notice Flat view row for the frontend (one multicall-free read).
    struct MarketView {
        uint256 id;
        address creator;
        string question;
        uint64 closeTime;
        uint64 resolvedAt;
        Outcome outcome;
        uint256 yesPool;
        uint256 noPool;
        uint256 feePaid;
    }

    /// @dev Unresolved past closeTime + RESOLVE_WINDOW => refunds open, resolve disabled.
    uint256 public constant RESOLVE_WINDOW = 30 days;
    /// @dev Caps how far out a market can close, so stakes cannot be parked
    ///      in limbo for years by a spam question nobody will resolve.
    uint256 public constant MAX_MARKET_DURATION = 365 days;
    uint256 public constant MAX_FEE_BPS = 1_000; // 10%
    uint256 public constant MAX_QUESTION_LENGTH = 200;

    /// @notice Fee in basis points, charged on the losing pool at resolution.
    uint256 public feeBps = 200;
    address public feeRecipient;
    /// @notice Minimum single bet, in wei of native CYBER.
    uint256 public minBet = 1e15;
    /// @notice Flat anti-spam fee to open a market (waived for the oracle).
    uint256 public createFee = 1 ether;

    Market[] private _markets;
    mapping(uint256 => mapping(address => uint256)) public yesBetOf;
    mapping(uint256 => mapping(address => uint256)) public noBetOf;
    mapping(uint256 => mapping(address => bool)) public claimed;

    event MarketCreated(
        uint256 indexed id,
        address indexed creator,
        string question,
        uint64 closeTime
    );
    event BetPlaced(uint256 indexed id, address indexed user, bool yes, uint256 amount);
    event MarketResolved(uint256 indexed id, Outcome outcome, uint256 fee);
    event Claimed(uint256 indexed id, address indexed user, uint256 amount);

    constructor(address initialOwner) Ownable() {
        if (initialOwner != address(0) && initialOwner != msg.sender) {
            _transferOwnership(initialOwner);
        }
        feeRecipient = owner();
    }

    // ---------------------------------------------------------------- create

    /// @notice Open a new market. Betting runs until `closeTime`. Anyone can
    ///         create by paying exactly `createFee` (sent to the treasury);
    ///         the oracle creates for free. Resolution stays with the oracle.
    function createMarket(string calldata question, uint64 closeTime)
        external
        payable
        returns (uint256 id)
    {
        uint256 len = bytes(question).length;
        require(len > 0 && len <= MAX_QUESTION_LENGTH, "PM: bad question");
        require(closeTime > block.timestamp, "PM: close in past");
        require(
            uint256(closeTime) <= block.timestamp + MAX_MARKET_DURATION,
            "PM: too far out"
        );

        uint256 fee = msg.sender == owner() ? 0 : createFee;
        require(msg.value == fee, "PM: wrong create fee");

        id = _markets.length;
        Market storage m = _markets.push();
        m.creator = msg.sender;
        m.question = question;
        m.closeTime = closeTime;

        if (msg.value > 0) {
            (bool ok, ) = feeRecipient.call{value: msg.value}("");
            require(ok, "PM: fee transfer failed");
        }

        emit MarketCreated(id, msg.sender, question, closeTime);
    }

    // ---------------------------------------------------------------- oracle

    /// @notice Report the outcome. Yes/No only after betting closed; Invalid
    ///         (= cancel, full refunds) any time. Fee is taken from the losing
    ///         pool and only when both sides have money — otherwise winners
    ///         simply get their stake back and no fee is due.
    function resolve(uint256 id, Outcome outcome) external onlyOwner {
        Market storage m = _market(id);
        require(m.outcome == Outcome.None, "PM: resolved");
        require(outcome != Outcome.None, "PM: bad outcome");
        require(
            block.timestamp <= uint256(m.closeTime) + RESOLVE_WINDOW,
            "PM: refund window open"
        );

        uint256 fee = 0;
        if (outcome == Outcome.Yes || outcome == Outcome.No) {
            require(block.timestamp >= m.closeTime, "PM: betting open");
            uint256 winPool = outcome == Outcome.Yes ? m.yesPool : m.noPool;
            uint256 losePool = outcome == Outcome.Yes ? m.noPool : m.yesPool;
            if (winPool > 0 && losePool > 0) {
                fee = (losePool * feeBps) / 10_000;
            }
        }

        m.outcome = outcome;
        m.resolvedAt = uint64(block.timestamp);
        m.feePaid = fee;

        if (fee > 0) {
            (bool ok, ) = feeRecipient.call{value: fee}("");
            require(ok, "PM: fee transfer failed");
        }

        emit MarketResolved(id, outcome, fee);
    }

    function setFeeBps(uint256 newFeeBps) external onlyOwner {
        require(newFeeBps <= MAX_FEE_BPS, "PM: fee too high");
        feeBps = newFeeBps;
    }

    function setFeeRecipient(address newRecipient) external onlyOwner {
        require(newRecipient != address(0), "PM: zero recipient");
        feeRecipient = newRecipient;
    }

    function setMinBet(uint256 newMinBet) external onlyOwner {
        require(newMinBet > 0, "PM: zero min bet");
        minBet = newMinBet;
    }

    /// @dev Zero is allowed: creation becomes free for everyone.
    function setCreateFee(uint256 newCreateFee) external onlyOwner {
        createFee = newCreateFee;
    }

    // --------------------------------------------------------------- bettors

    /// @notice Stake `msg.value` native CYBER on YES (`yes = true`) or NO.
    function bet(uint256 id, bool yes) external payable {
        Market storage m = _market(id);
        require(m.outcome == Outcome.None, "PM: resolved");
        require(block.timestamp < m.closeTime, "PM: betting closed");
        require(msg.value >= minBet, "PM: below min bet");

        if (yes) {
            m.yesPool += msg.value;
            yesBetOf[id][msg.sender] += msg.value;
        } else {
            m.noPool += msg.value;
            noBetOf[id][msg.sender] += msg.value;
        }

        emit BetPlaced(id, msg.sender, yes, msg.value);
    }

    /// @notice Withdraw winnings (or the refund) for one market.
    function claim(uint256 id) external nonReentrant {
        Market storage m = _market(id);
        require(!claimed[id][msg.sender], "PM: already claimed");

        uint256 payout = _payoutOf(id, m, msg.sender);
        require(payout > 0, "PM: nothing to claim");

        claimed[id][msg.sender] = true;
        (bool ok, ) = msg.sender.call{value: payout}("");
        require(ok, "PM: transfer failed");

        emit Claimed(id, msg.sender, payout);
    }

    // ----------------------------------------------------------------- views

    function marketCount() external view returns (uint256) {
        return _markets.length;
    }

    function getMarket(uint256 id) external view returns (MarketView memory) {
        return _toView(id, _market(id));
    }

    /// @notice Page through markets: `limit` rows starting at `offset`,
    ///         truncated at the end of the list.
    function getMarkets(uint256 offset, uint256 limit)
        external
        view
        returns (MarketView[] memory out)
    {
        uint256 total = _markets.length;
        if (offset >= total) {
            return new MarketView[](0);
        }
        uint256 end = offset + limit;
        if (end > total) {
            end = total;
        }
        out = new MarketView[](end - offset);
        for (uint256 i = offset; i < end; i++) {
            out[i - offset] = _toView(i, _markets[i]);
        }
    }

    /// @notice `user`'s stakes/claim state over the same window as getMarkets.
    function getUserBets(address user, uint256 offset, uint256 limit)
        external
        view
        returns (
            uint256[] memory yesBets,
            uint256[] memory noBets,
            bool[] memory claimedArr,
            uint256[] memory claimable
        )
    {
        uint256 total = _markets.length;
        uint256 end = offset + limit;
        if (end > total) {
            end = total;
        }
        uint256 n = offset >= total ? 0 : end - offset;
        yesBets = new uint256[](n);
        noBets = new uint256[](n);
        claimedArr = new bool[](n);
        claimable = new uint256[](n);
        for (uint256 i = 0; i < n; i++) {
            uint256 id = offset + i;
            yesBets[i] = yesBetOf[id][user];
            noBets[i] = noBetOf[id][user];
            claimedArr[i] = claimed[id][user];
            claimable[i] = claimed[id][user]
                ? 0
                : _payoutOf(id, _markets[id], user);
        }
    }

    /// @notice What `user` could claim from market `id` right now (0 if
    ///         already claimed, unresolved-and-not-yet-refundable, or lost).
    function claimableOf(uint256 id, address user) external view returns (uint256) {
        if (claimed[id][user]) {
            return 0;
        }
        return _payoutOf(id, _market(id), user);
    }

    // ------------------------------------------------------------- internals

    function _market(uint256 id) internal view returns (Market storage) {
        require(id < _markets.length, "PM: no such market");
        return _markets[id];
    }

    function _toView(uint256 id, Market storage m)
        internal
        view
        returns (MarketView memory)
    {
        return MarketView({
            id: id,
            creator: m.creator,
            question: m.question,
            closeTime: m.closeTime,
            resolvedAt: m.resolvedAt,
            outcome: m.outcome,
            yesPool: m.yesPool,
            noPool: m.noPool,
            feePaid: m.feePaid
        });
    }

    function _payoutOf(uint256 id, Market storage m, address user)
        internal
        view
        returns (uint256)
    {
        Outcome o = m.outcome;

        if (o == Outcome.None) {
            // Refund only once the oracle's resolve window has lapsed.
            if (block.timestamp <= uint256(m.closeTime) + RESOLVE_WINDOW) {
                return 0;
            }
            return yesBetOf[id][user] + noBetOf[id][user];
        }

        if (o == Outcome.Invalid) {
            return yesBetOf[id][user] + noBetOf[id][user];
        }

        uint256 winPool = o == Outcome.Yes ? m.yesPool : m.noPool;
        uint256 losePool = o == Outcome.Yes ? m.noPool : m.yesPool;
        uint256 userWin = o == Outcome.Yes ? yesBetOf[id][user] : noBetOf[id][user];

        if (winPool == 0) {
            // Nobody bet the winning side: losing stakes are refunded in full
            // (no fee was taken at resolve in this case).
            return o == Outcome.Yes ? noBetOf[id][user] : yesBetOf[id][user];
        }
        if (userWin == 0) {
            return 0;
        }
        // Stake back + pro-rata share of the losing pool net of the fee.
        return userWin + (userWin * (losePool - m.feePaid)) / winPool;
    }
}
