// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";
import {IERC20Metadata} from "@openzeppelin/contracts/token/ERC20/extensions/IERC20Metadata.sol";

import {IPriceOracle} from "./interfaces/IPriceOracle.sol";
import {ILendingMarket} from "./interfaces/ILendingMarket.sol";

/// @notice Admin-set price oracle.
/// Stored prices are USD per whole token, scaled by 1e18.
/// `getUnderlyingPrice` normalizes by 10**(36 - decimals) to match Compound's
/// convention so liquidity math is decimals-agnostic.
contract SimplePriceOracle is IPriceOracle, Ownable {
    /// @notice Raw USD price per 1.0 of the underlying token, mantissa 1e18.
    mapping(address => uint256) public prices;

    event PricePosted(address indexed underlying, uint256 oldPrice, uint256 newPrice);

    function setUnderlyingPrice(address underlying, uint256 priceMantissa) external onlyOwner {
        uint256 old = prices[underlying];
        prices[underlying] = priceMantissa;
        emit PricePosted(underlying, old, priceMantissa);
    }

    function setUnderlyingPrices(address[] calldata underlyings, uint256[] calldata priceMantissas)
        external onlyOwner
    {
        require(underlyings.length == priceMantissas.length, "len mismatch");
        for (uint256 i = 0; i < underlyings.length; i++) {
            uint256 old = prices[underlyings[i]];
            prices[underlyings[i]] = priceMantissas[i];
            emit PricePosted(underlyings[i], old, priceMantissas[i]);
        }
    }

    function getUnderlyingPrice(address market) external view returns (uint256) {
        address underlying = ILendingMarket(market).underlying();
        uint256 price = prices[underlying];
        uint8 decimals = IERC20Metadata(underlying).decimals();
        // Scale so that price * underlyingAmount (raw) yields USD in 1e36 fixed point.
        return price * (10 ** (36 - 18 - decimals));
    }
}
