// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";
import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {SafeERC20} from "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import {IERC721} from "@openzeppelin/contracts/token/ERC721/IERC721.sol";

/// @title Generic fixed-price ERC-20-priced NFT marketplace.
/// @notice Approval-style escrow — the NFT stays in the seller's wallet until
///         purchase. Payment is in an arbitrary ERC-20 chosen per-listing.
///         A `feeBps` cut goes to `feeRecipient` (max 10%).
contract NFTMarket is Ownable {
    using SafeERC20 for IERC20;

    struct Listing {
        address nft;
        uint256 tokenId;
        address seller;
        address paymentToken;
        uint256 price;
        bool active;
    }

    Listing[] public listings;

    /// Marketplace fee in basis points (100 = 1%). Capped at 1000 (10%).
    uint256 public feeBps;
    address public feeRecipient;

    event Listed(
        uint256 indexed listingId,
        address indexed nft,
        uint256 indexed tokenId,
        address seller,
        address paymentToken,
        uint256 price
    );
    event PriceUpdated(uint256 indexed listingId, uint256 newPrice);
    event Cancelled(uint256 indexed listingId);
    event Sold(uint256 indexed listingId, address indexed buyer, uint256 price);

    constructor(address feeRecipient_, uint256 feeBps_) {
        require(feeBps_ <= 1000, "NFTMarket: fee>10%");
        require(feeRecipient_ != address(0), "NFTMarket: zero feeRecipient");
        feeRecipient = feeRecipient_;
        feeBps = feeBps_;
    }

    function listingsLength() external view returns (uint256) {
        return listings.length;
    }

    function list(
        address nft,
        uint256 tokenId,
        address paymentToken,
        uint256 price
    ) external returns (uint256 listingId) {
        require(price > 0, "NFTMarket: price=0");
        require(paymentToken != address(0), "NFTMarket: zero paymentToken");
        require(IERC721(nft).ownerOf(tokenId) == msg.sender, "NFTMarket: not owner");
        require(
            IERC721(nft).getApproved(tokenId) == address(this) ||
                IERC721(nft).isApprovedForAll(msg.sender, address(this)),
            "NFTMarket: not approved"
        );

        listings.push(
            Listing({
                nft: nft,
                tokenId: tokenId,
                seller: msg.sender,
                paymentToken: paymentToken,
                price: price,
                active: true
            })
        );
        listingId = listings.length - 1;
        emit Listed(listingId, nft, tokenId, msg.sender, paymentToken, price);
    }

    function updatePrice(uint256 listingId, uint256 newPrice) external {
        Listing storage l = listings[listingId];
        require(l.active, "NFTMarket: inactive");
        require(l.seller == msg.sender, "NFTMarket: not seller");
        require(newPrice > 0, "NFTMarket: price=0");
        l.price = newPrice;
        emit PriceUpdated(listingId, newPrice);
    }

    function cancel(uint256 listingId) external {
        Listing storage l = listings[listingId];
        require(l.active, "NFTMarket: inactive");
        require(l.seller == msg.sender, "NFTMarket: not seller");
        l.active = false;
        emit Cancelled(listingId);
    }

    function buy(uint256 listingId) external {
        Listing storage l = listings[listingId];
        require(l.active, "NFTMarket: inactive");
        // Cancel-on-transfer: if the seller moved the NFT outside the market,
        // the listing is stale.
        require(IERC721(l.nft).ownerOf(l.tokenId) == l.seller, "NFTMarket: seller moved NFT");

        l.active = false;
        uint256 fee = (l.price * feeBps) / 10000;
        uint256 sellerProceeds = l.price - fee;

        IERC20 pay = IERC20(l.paymentToken);
        pay.safeTransferFrom(msg.sender, l.seller, sellerProceeds);
        if (fee > 0) {
            pay.safeTransferFrom(msg.sender, feeRecipient, fee);
        }
        IERC721(l.nft).transferFrom(l.seller, msg.sender, l.tokenId);
        emit Sold(listingId, msg.sender, l.price);
    }

    function setFee(uint256 bps, address recipient) external onlyOwner {
        require(bps <= 1000, "NFTMarket: fee>10%");
        require(recipient != address(0), "NFTMarket: zero recipient");
        feeBps = bps;
        feeRecipient = recipient;
    }
}
