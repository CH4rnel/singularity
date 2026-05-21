// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

interface IInterestRateModel {
    /// @notice Borrow rate per block, scaled by 1e18.
    function getBorrowRate(uint256 cash, uint256 borrows, uint256 reserves)
        external view returns (uint256);

    /// @notice Supply rate per block, scaled by 1e18.
    function getSupplyRate(uint256 cash, uint256 borrows, uint256 reserves, uint256 reserveFactorMantissa)
        external view returns (uint256);

    /// @notice Utilization = borrows / (cash + borrows - reserves), scaled by 1e18.
    function utilizationRate(uint256 cash, uint256 borrows, uint256 reserves)
        external pure returns (uint256);
}
