// SPDX-License-Identifier: GPL-2.0-or-later
pragma solidity ^0.8.19;

import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {SafeERC20} from "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";

interface IWrappedNative {
    function withdraw(uint256 amount) external;
}

/// @title Where the protocol's share of swap fees goes
/// @notice A v3 pool hands its protocol fee to whoever the factory owner points `collectProtocol` at.
///         This is that address: it holds nothing for long, and splits whatever arrives between a set
///         of recipients by weights the owner can retune at any time.
///
/// @dev Deliberately dumb. It does not swap, it does not price anything, and it does not decide when
///      to pay out -- `distribute` is open to anyone, so a recipient never depends on an operator
///      remembering. Anything cleverer (buy back and burn, for instance) is a recipient of its own,
///      which keeps the thing that moves money separate from the thing that decides how much.
contract FeeSplitter {
    using SafeERC20 for IERC20;

    uint256 internal constant BPS = 10_000;

    struct Share {
        address recipient;
        /// @dev in basis points; the set must sum to exactly BPS, so what is unaccounted for is
        /// visible as a configuration error rather than as tokens quietly piling up here
        uint16 weight;
    }

    address public owner;

    /// @notice The chain's wrapped native token, so a fee taken in it can be paid out as the coin
    address public immutable wrappedNative;

    Share[] private _shares;

    /// @notice Native owed to a recipient whose payout failed, claimable by anyone on their behalf
    /// @dev A recipient that cannot accept a plain transfer would otherwise stop every payout in the
    ///      same call, including the ones to recipients that were fine.
    mapping(address => uint256) public owedNative;

    event SharesChanged(Share[] shares);
    event Distributed(address indexed token, uint256 total);
    event Paid(address indexed token, address indexed recipient, uint256 amount);
    event PayoutDeferred(address indexed recipient, uint256 amount);
    event PayoutClaimed(address indexed recipient, uint256 amount);
    event Unwrapped(uint256 amount);
    event OwnerChanged(address indexed from, address indexed to);

    error NotOwner();
    error BadShares();
    error NothingToDistribute();
    error NothingOwed();
    error ClaimFailed();

    modifier onlyOwner() {
        if (msg.sender != owner) revert NotOwner();
        _;
    }

    constructor(address _wrappedNative, Share[] memory initialShares) {
        wrappedNative = _wrappedNative;
        owner = msg.sender;
        emit OwnerChanged(address(0), msg.sender);
        _setShares(initialShares);
    }

    receive() external payable {}

    // -------------------------------------------------------------------------------------------
    // Distribution
    // -------------------------------------------------------------------------------------------

    /// @notice Splits this contract's whole balance of `token` among the recipients
    function distribute(address token) public returns (uint256 total) {
        total = IERC20(token).balanceOf(address(this));
        if (total == 0) revert NothingToDistribute();

        uint256 last = _shares.length - 1;
        uint256 paid;
        for (uint256 i = 0; i < last; i++) {
            Share memory share = _shares[i];
            uint256 amount = (total * share.weight) / BPS;
            paid += amount;
            if (amount > 0) {
                IERC20(token).safeTransfer(share.recipient, amount);
                emit Paid(token, share.recipient, amount);
            }
        }
        // the last recipient absorbs the rounding remainder, so nothing is stranded here
        uint256 remainder = total - paid;
        if (remainder > 0) {
            IERC20(token).safeTransfer(_shares[last].recipient, remainder);
            emit Paid(token, _shares[last].recipient, remainder);
        }

        emit Distributed(token, total);
    }

    /// @notice Splits this contract's whole native balance among the recipients
    function distributeNative() public returns (uint256 total) {
        total = address(this).balance - _totalOwed();
        if (total == 0) revert NothingToDistribute();

        uint256 last = _shares.length - 1;
        uint256 paid;
        for (uint256 i = 0; i < last; i++) {
            Share memory share = _shares[i];
            uint256 amount = (total * share.weight) / BPS;
            paid += amount;
            _payNative(share.recipient, amount);
        }
        _payNative(_shares[last].recipient, total - paid);

        emit Distributed(address(0), total);
    }

    /// @notice Turns wrapped native held here into the coin and splits it
    /// @dev The gas station's tank, the one recipient that must be paid in the coin itself, is the
    ///      reason this exists: a WCYBER/X pool's protocol fee arrives wrapped.
    function unwrapAndDistributeNative() external returns (uint256 total) {
        uint256 wrapped = IERC20(wrappedNative).balanceOf(address(this));
        if (wrapped == 0) revert NothingToDistribute();
        IWrappedNative(wrappedNative).withdraw(wrapped);
        emit Unwrapped(wrapped);
        return distributeNative();
    }

    /// @notice Splits several tokens in one call
    function distributeMany(address[] calldata tokens) external {
        for (uint256 i = 0; i < tokens.length; i++) {
            distribute(tokens[i]);
        }
    }

    /// @notice Sends a recipient the native payouts that could not be delivered when they were split
    function claimNative(address recipient) external {
        uint256 amount = owedNative[recipient];
        if (amount == 0) revert NothingOwed();
        owedNative[recipient] = 0;
        (bool ok, ) = recipient.call{value: amount}("");
        if (!ok) revert ClaimFailed();
        emit PayoutClaimed(recipient, amount);
    }

    function _payNative(address recipient, uint256 amount) private {
        if (amount == 0) return;
        (bool ok, ) = recipient.call{value: amount, gas: 30_000}("");
        if (ok) {
            emit Paid(address(0), recipient, amount);
        } else {
            owedNative[recipient] += amount;
            emit PayoutDeferred(recipient, amount);
        }
    }

    function _totalOwed() private view returns (uint256 total) {
        uint256 length = _shares.length;
        for (uint256 i = 0; i < length; i++) {
            total += owedNative[_shares[i].recipient];
        }
    }

    // -------------------------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------------------------

    /// @notice Replaces the whole recipient set; weights must sum to exactly 10000
    function setShares(Share[] calldata newShares) external onlyOwner {
        _setShares(newShares);
    }

    function _setShares(Share[] memory newShares) private {
        uint256 length = newShares.length;
        if (length == 0) revert BadShares();

        uint256 sum;
        for (uint256 i = 0; i < length; i++) {
            if (newShares[i].recipient == address(0)) revert BadShares();
            sum += newShares[i].weight;
        }
        if (sum != BPS) revert BadShares();

        delete _shares;
        for (uint256 i = 0; i < length; i++) {
            _shares.push(newShares[i]);
        }
        emit SharesChanged(newShares);
    }

    function shares() external view returns (Share[] memory) {
        return _shares;
    }

    function shareCount() external view returns (uint256) {
        return _shares.length;
    }

    function setOwner(address to) external onlyOwner {
        emit OwnerChanged(owner, to);
        owner = to;
    }
}
