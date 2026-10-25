// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";
import {ReentrancyGuard} from "@openzeppelin/contracts/security/ReentrancyGuard.sol";
import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {SafeERC20} from "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";

/// @title CyberiaOTC — fixed-price over-the-counter offers, with no fee.
/// @notice A maker escrows an asset and names a price for it in another
///         asset. Takers fill the whole offer or, if the maker allows, any
///         part of it not smaller than `minFill`, at that price — no pool, so
///         no slippage and no price impact. `address(0)` is the native coin.
///
///         There is no platform fee: the taker's payment goes to the maker in
///         full, in the same transaction that hands the taker the escrowed
///         asset. The contract has no fee field, no treasury and nothing to
///         sweep, so a fee could only ever arrive as a new contract.
///
///         A private offer names its only taker — which is how a deal agreed
///         in chat becomes binding. The maker can reprice, top up, reduce or
///         cancel; after expiry anyone may `reclaim`, which always pays the
///         maker. The owner can only pause new offers and fills; cancel,
///         reduce and reclaim stay open, so escrowed funds are never stuck.
contract CyberiaOTC is Ownable, ReentrancyGuard {
    using SafeERC20 for IERC20;

    struct Order {
        address maker;
        address sellToken;
        address buyToken;
        /// The only taker allowed; zero = anyone.
        address taker;
        /// The price as a ratio: `priceBuy` of the buy asset per `priceSell`
        /// of the sell asset. Changed only by `reprice`.
        uint256 priceSell;
        uint256 priceBuy;
        /// Still escrowed and for sale.
        uint256 remaining;
        /// Smallest part a taker may buy, unless buying all that remains.
        uint256 minFill;
        /// Unix seconds; zero = never expires.
        uint64 expiry;
        bool partialFill;
        bool closed;
    }

    Order[] private _orders;

    bool public paused;

    event OrderCreated(
        uint256 indexed id,
        address indexed maker,
        address indexed taker,
        address sellToken,
        uint256 sellAmount,
        address buyToken,
        uint256 buyAmount,
        uint256 minFill,
        uint64 expiry,
        bool partialFill
    );
    event OrderFilled(
        uint256 indexed id,
        address indexed maker,
        address indexed taker,
        uint256 sold,
        uint256 paid,
        uint256 remaining
    );
    event OrderRepriced(uint256 indexed id, uint256 priceSell, uint256 priceBuy);
    event OrderResized(uint256 indexed id, uint256 remaining);
    event OrderCancelled(uint256 indexed id, uint256 refunded);
    event OrderReclaimed(uint256 indexed id, uint256 refunded);
    event Paused(bool paused);

    constructor(address owner_) {
        require(owner_ != address(0), "OTC: zero owner");
        _transferOwnership(owner_);
    }

    // ── Offers ────────────────────────────────────────────────────────────

    /// @param sellAmount Escrowed now, and the base of the price.
    /// @param buyAmount  What all of `sellAmount` costs.
    function create(
        address sellToken,
        uint256 sellAmount,
        address buyToken,
        uint256 buyAmount,
        address taker,
        uint64 expiry,
        bool partialFill,
        uint256 minFill
    ) external payable nonReentrant returns (uint256 id) {
        require(!paused, "OTC: paused");
        require(sellToken != buyToken, "OTC: same asset");
        require(sellAmount > 0 && buyAmount > 0, "OTC: zero amount");
        require(expiry == 0 || expiry > block.timestamp, "OTC: expired");
        require(taker != msg.sender, "OTC: taker is maker");
        require(minFill <= sellAmount, "OTC: minFill > amount");

        _receive(sellToken, sellAmount);

        id = _orders.length;
        _orders.push(
            Order({
                maker: msg.sender,
                sellToken: sellToken,
                buyToken: buyToken,
                taker: taker,
                priceSell: sellAmount,
                priceBuy: buyAmount,
                remaining: sellAmount,
                minFill: minFill,
                expiry: expiry,
                partialFill: partialFill,
                closed: false
            })
        );

        emit OrderCreated(
            id, msg.sender, taker, sellToken, sellAmount, buyToken, buyAmount, minFill, expiry, partialFill
        );
    }

    /// @notice Buy `amount` of the offer's sell asset at the offer's price.
    /// @param maxPay What the taker agreed to pay at most: a reprice that
    ///        lands before this transaction makes it revert, not overpay.
    function fill(uint256 id, uint256 amount, uint256 maxPay) external payable nonReentrant {
        require(!paused, "OTC: paused");
        Order storage o = _order(id);
        require(_live(o), "OTC: closed");
        require(o.taker == address(0) || o.taker == msg.sender, "OTC: private");
        require(msg.sender != o.maker, "OTC: own order");
        require(amount > 0 && amount <= o.remaining, "OTC: bad amount");
        if (amount < o.remaining) {
            require(o.partialFill, "OTC: fill all");
            require(amount >= o.minFill, "OTC: below minFill");
        }

        uint256 pay = _quote(o, amount);
        require(pay > 0, "OTC: zero pay");
        require(pay <= maxPay, "OTC: price moved");

        o.remaining -= amount;

        if (o.buyToken == address(0)) {
            require(msg.value == pay, "OTC: value != pay");
            _sendNative(o.maker, pay);
        } else {
            require(msg.value == 0, "OTC: unexpected value");
            // Straight from taker to maker: the contract never holds the payment.
            uint256 before = IERC20(o.buyToken).balanceOf(o.maker);
            IERC20(o.buyToken).safeTransferFrom(msg.sender, o.maker, pay);
            require(IERC20(o.buyToken).balanceOf(o.maker) - before == pay, "OTC: fee-on-transfer token");
        }

        _send(o.sellToken, msg.sender, amount);

        emit OrderFilled(id, o.maker, msg.sender, amount, pay, o.remaining);
    }

    /// @notice New price for the offer: `priceBuy` per `priceSell`. Fills
    ///         already made keep the price they were made at.
    function reprice(uint256 id, uint256 priceSell, uint256 priceBuy) external {
        Order storage o = _makerOrder(id);
        require(_live(o), "OTC: closed");
        require(priceSell > 0 && priceBuy > 0, "OTC: zero price");
        o.priceSell = priceSell;
        o.priceBuy = priceBuy;
        emit OrderRepriced(id, priceSell, priceBuy);
    }

    /// @notice Put more of the sell asset into the offer, at the same price.
    function topUp(uint256 id, uint256 amount) external payable nonReentrant {
        require(!paused, "OTC: paused");
        Order storage o = _makerOrder(id);
        require(_live(o), "OTC: closed");
        require(amount > 0, "OTC: zero amount");
        _receive(o.sellToken, amount);
        o.remaining += amount;
        emit OrderResized(id, o.remaining);
    }

    /// @notice Take part of the unsold remainder back. Works while paused.
    function reduce(uint256 id, uint256 amount) external nonReentrant {
        Order storage o = _makerOrder(id);
        require(!o.closed, "OTC: closed");
        require(amount > 0 && amount < o.remaining, "OTC: use cancel");
        o.remaining -= amount;
        _send(o.sellToken, o.maker, amount);
        emit OrderResized(id, o.remaining);
    }

    /// @notice Close the offer and refund the remainder. Works while paused.
    function cancel(uint256 id) external nonReentrant {
        Order storage o = _makerOrder(id);
        require(!o.closed, "OTC: closed");
        uint256 refund = _close(o);
        emit OrderCancelled(id, refund);
    }

    /// @notice Return an expired offer's remainder to its maker. Anyone may
    ///         call it; the money can only go to the maker.
    function reclaim(uint256 id) external nonReentrant {
        Order storage o = _order(id);
        require(!o.closed, "OTC: closed");
        require(o.expiry != 0 && block.timestamp >= o.expiry, "OTC: not expired");
        uint256 refund = _close(o);
        emit OrderReclaimed(id, refund);
    }

    // ── Reads ─────────────────────────────────────────────────────────────

    /// @notice What a taker pays for `amount` of the offer's sell asset,
    ///         rounded up so the maker never sells below the stated price.
    function quote(uint256 id, uint256 amount) external view returns (uint256) {
        return _quote(_order(id), amount);
    }

    function ordersLength() external view returns (uint256) {
        return _orders.length;
    }

    function getOrder(uint256 id) external view returns (Order memory) {
        return _order(id);
    }

    /// @notice A page of offers, newest first — one call for a list screen.
    function getOrders(uint256 offset, uint256 limit)
        external
        view
        returns (uint256[] memory ids, Order[] memory page)
    {
        uint256 total = _orders.length;
        if (offset >= total) {
            return (new uint256[](0), new Order[](0));
        }
        uint256 count = total - offset < limit ? total - offset : limit;
        ids = new uint256[](count);
        page = new Order[](count);
        for (uint256 i = 0; i < count; i++) {
            uint256 id = total - 1 - offset - i;
            ids[i] = id;
            page[i] = _orders[id];
        }
    }

    // ── Admin: pause only ─────────────────────────────────────────────────

    /// @notice Stops new offers, top-ups and fills. Taking money back stays open.
    function setPaused(bool paused_) external onlyOwner {
        paused = paused_;
        emit Paused(paused_);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    function _order(uint256 id) private view returns (Order storage) {
        require(id < _orders.length, "OTC: no order");
        return _orders[id];
    }

    function _makerOrder(uint256 id) private view returns (Order storage o) {
        o = _order(id);
        require(msg.sender == o.maker, "OTC: not maker");
    }

    function _live(Order storage o) private view returns (bool) {
        return !o.closed && o.remaining > 0 && (o.expiry == 0 || block.timestamp < o.expiry);
    }

    function _quote(Order storage o, uint256 amount) private view returns (uint256) {
        uint256 product = amount * o.priceBuy;
        return product == 0 ? 0 : (product - 1) / o.priceSell + 1;
    }

    function _close(Order storage o) private returns (uint256 refund) {
        refund = o.remaining;
        o.closed = true;
        o.remaining = 0;
        if (refund > 0) {
            _send(o.sellToken, o.maker, refund);
        }
    }

    /// Fee-on-transfer and rebasing tokens would make the escrow owe more
    /// than it holds, so a deposit must arrive in full or not at all.
    function _receive(address token, uint256 amount) private {
        if (token == address(0)) {
            require(msg.value == amount, "OTC: value != amount");
            return;
        }
        require(msg.value == 0, "OTC: unexpected value");
        uint256 before = IERC20(token).balanceOf(address(this));
        IERC20(token).safeTransferFrom(msg.sender, address(this), amount);
        require(IERC20(token).balanceOf(address(this)) - before == amount, "OTC: fee-on-transfer token");
    }

    function _send(address token, address to, uint256 amount) private {
        if (token == address(0)) {
            _sendNative(to, amount);
        } else {
            IERC20(token).safeTransfer(to, amount);
        }
    }

    function _sendNative(address to, uint256 amount) private {
        (bool ok, ) = payable(to).call{value: amount}("");
        require(ok, "OTC: native transfer failed");
    }
}
