// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

interface ILendingComptroller {
    function isMarketListed(address market) external view returns (bool);
    function collateralFactor(address market) external view returns (uint256);
    function closeFactorMantissa() external view returns (uint256);
    function liquidationIncentiveMantissa() external view returns (uint256);

    function oracle() external view returns (address);

    function enterMarkets(address[] calldata markets) external returns (uint256[] memory);
    function exitMarket(address market) external;
    function getAssetsIn(address account) external view returns (address[] memory);

    /// @return error (0 on success), liquidity (USD-scaled 1e18), shortfall (USD-scaled 1e18)
    function getAccountLiquidity(address account)
        external view returns (uint256, uint256, uint256);

    function mintAllowed(address market, address minter, uint256 mintAmount) external;
    function redeemAllowed(address market, address redeemer, uint256 redeemShares) external;
    function borrowAllowed(address market, address borrower, uint256 borrowAmount) external;
    function repayBorrowAllowed(address market, address borrower, uint256 repayAmount) external;
    function liquidateBorrowAllowed(
        address marketBorrowed,
        address marketCollateral,
        address borrower,
        uint256 repayAmount
    ) external;
    function seizeAllowed(address marketCollateral, address marketBorrowed) external;

    /// @notice Compute how many collateral shares correspond to a given repay amount.
    function liquidateCalculateSeizeShares(
        address marketBorrowed,
        address marketCollateral,
        uint256 actualRepayAmount
    ) external view returns (uint256);
}
