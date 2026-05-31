// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

interface ILendingMarket {
    function underlying() external view returns (address);
    function comptroller() external view returns (address);
    function interestRateModel() external view returns (address);

    function totalBorrows() external view returns (uint256);
    function totalReserves() external view returns (uint256);
    function getCash() external view returns (uint256);

    function exchangeRateStored() external view returns (uint256);
    function borrowBalanceStored(address account) external view returns (uint256);

    function accrueInterest() external;

    /// @notice Used by the comptroller during liquidations to transfer collateral
    /// shares from borrower to liquidator without invoking redeem checks.
    function seize(address liquidator, address borrower, uint256 seizeShares) external;
}
