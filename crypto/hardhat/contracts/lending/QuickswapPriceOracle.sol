// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";
import {IERC20Metadata} from "@openzeppelin/contracts/token/ERC20/extensions/IERC20Metadata.sol";

import {IPriceOracle} from "./interfaces/IPriceOracle.sol";
import {ILendingMarket} from "./interfaces/ILendingMarket.sol";

interface IUniswapV2Factory {
    function getPair(address tokenA, address tokenB) external view returns (address pair);
}

interface IUniswapV2Pair {
    function getReserves() external view returns (uint112 reserve0, uint112 reserve1, uint32 blockTimestampLast);
    function token0() external view returns (address);
    function token1() external view returns (address);
}

/// @notice Spot-price oracle backed by a Uniswap V2 factory (Quickswap fork).
/// All prices are quoted in `quoteToken` (e.g. WCYBER) which the protocol treats
/// as the $1 reference. Prices are returned in Compound's normalized form:
///   getUnderlyingPrice(market) = price_per_whole_token_USD_mantissa * 10^(36 - 18 - decimals)
///
/// If a pair for `(underlying, quoteToken)` does not exist or has zero reserves,
/// the oracle falls back to the admin-set `fallbackPrice` (mantissa 1e18 USD per
/// whole token). This lets us list markets for assets that have no liquidity yet.
///
/// SECURITY: spot prices are manipulable in a single block. For production, this
/// should be replaced with a TWAP oracle (PriceCumulativeLast snapshots).
contract QuickswapPriceOracle is IPriceOracle, Ownable {
    IUniswapV2Factory public immutable factory;
    address public immutable quoteToken;
    uint8 public immutable quoteDecimals;

    /// @notice Fallback USD-per-whole-token, mantissa 1e18.
    mapping(address => uint256) public fallbackPrice;

    event FallbackPriceSet(address indexed underlying, uint256 oldPrice, uint256 newPrice);

    constructor(IUniswapV2Factory factory_, address quoteToken_) {
        factory = factory_;
        quoteToken = quoteToken_;
        quoteDecimals = IERC20Metadata(quoteToken_).decimals();
    }

    function setFallbackPrice(address underlying, uint256 priceMantissa) external onlyOwner {
        emit FallbackPriceSet(underlying, fallbackPrice[underlying], priceMantissa);
        fallbackPrice[underlying] = priceMantissa;
    }

    function setFallbackPrices(address[] calldata underlyings, uint256[] calldata pricesMantissa)
        external onlyOwner
    {
        require(underlyings.length == pricesMantissa.length, "len");
        for (uint256 i = 0; i < underlyings.length; i++) {
            emit FallbackPriceSet(underlyings[i], fallbackPrice[underlyings[i]], pricesMantissa[i]);
            fallbackPrice[underlyings[i]] = pricesMantissa[i];
        }
    }

    function getUnderlyingPrice(address market) external view returns (uint256) {
        address underlying = ILendingMarket(market).underlying();
        uint8 underlyingDecimals = IERC20Metadata(underlying).decimals();

        // The quote token is the unit; report 1.0 USD per whole token.
        if (underlying == quoteToken) {
            // (1 * 1e18) * 10^(36 - 18 - dec) = 10^(36 - dec)
            return 10 ** (36 - underlyingDecimals);
        }

        address pair = factory.getPair(underlying, quoteToken);
        if (pair != address(0)) {
            (uint112 r0, uint112 r1, ) = IUniswapV2Pair(pair).getReserves();
            address token0 = IUniswapV2Pair(pair).token0();
            (uint256 reserveUnderlying, uint256 reserveQuote) = token0 == underlying
                ? (uint256(r0), uint256(r1))
                : (uint256(r1), uint256(r0));

            if (reserveUnderlying > 0 && reserveQuote > 0) {
                // price = (reserveQuote / reserveUnderlying) in raw-unit ratio.
                // Normalize for Compound: reserveQuote * 10^36 / (reserveUnderlying * 10^quoteDecimals).
                return (reserveQuote * (10 ** 36)) / (reserveUnderlying * (10 ** quoteDecimals));
            }
        }

        uint256 fb = fallbackPrice[underlying];
        if (fb == 0) return 0;
        return fb * 10 ** (36 - 18 - underlyingDecimals);
    }
}
