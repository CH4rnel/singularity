// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

interface IPriceOracle {
    /// @notice Price of one unit of the market's underlying token, scaled by 1e18.
    /// Returned price is expressed in a common quote asset (e.g. USD) and is
    /// normalized so it can be compared across markets with different decimals.
    function getUnderlyingPrice(address market) external view returns (uint256);
}
