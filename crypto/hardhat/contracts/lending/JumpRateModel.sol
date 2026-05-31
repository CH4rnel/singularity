// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {IInterestRateModel} from "./interfaces/IInterestRateModel.sol";

/// @notice Kinked utilization rate model (Compound JumpRateModel v2).
/// Borrow rate is linear up to `kink`, then jumps to a steeper slope.
/// All rates are per-block, mantissa-scaled (1e18).
contract JumpRateModel is IInterestRateModel {
    uint256 public constant MANTISSA = 1e18;

    /// @notice Assumed average block time, used only to convert per-year inputs.
    uint256 public immutable blocksPerYear;

    /// @notice Base APY when utilization is 0, per block (mantissa).
    uint256 public immutable baseRatePerBlock;
    /// @notice Slope of borrow rate up to the kink, per block (mantissa).
    uint256 public immutable multiplierPerBlock;
    /// @notice Slope of borrow rate above the kink, per block (mantissa).
    uint256 public immutable jumpMultiplierPerBlock;
    /// @notice Utilization point at which the jump multiplier engages (mantissa).
    uint256 public immutable kink;

    constructor(
        uint256 baseRatePerYear,
        uint256 multiplierPerYear,
        uint256 jumpMultiplierPerYear,
        uint256 kink_,
        uint256 blocksPerYear_
    ) {
        require(blocksPerYear_ > 0, "blocksPerYear=0");
        require(kink_ <= MANTISSA, "kink>1");
        blocksPerYear = blocksPerYear_;
        baseRatePerBlock = baseRatePerYear / blocksPerYear_;
        multiplierPerBlock = multiplierPerYear / blocksPerYear_;
        jumpMultiplierPerBlock = jumpMultiplierPerYear / blocksPerYear_;
        kink = kink_;
    }

    function utilizationRate(uint256 cash, uint256 borrows, uint256 reserves)
        public pure returns (uint256)
    {
        if (borrows == 0) return 0;
        uint256 denom = cash + borrows - reserves;
        if (denom == 0) return 0;
        return (borrows * MANTISSA) / denom;
    }

    function getBorrowRate(uint256 cash, uint256 borrows, uint256 reserves)
        public view returns (uint256)
    {
        uint256 util = utilizationRate(cash, borrows, reserves);
        if (util <= kink) {
            return (util * multiplierPerBlock) / MANTISSA + baseRatePerBlock;
        }
        uint256 normal = (kink * multiplierPerBlock) / MANTISSA + baseRatePerBlock;
        uint256 excess = util - kink;
        return (excess * jumpMultiplierPerBlock) / MANTISSA + normal;
    }

    function getSupplyRate(uint256 cash, uint256 borrows, uint256 reserves, uint256 reserveFactorMantissa)
        external view returns (uint256)
    {
        uint256 oneMinusReserveFactor = MANTISSA - reserveFactorMantissa;
        uint256 borrowRate = getBorrowRate(cash, borrows, reserves);
        uint256 rateToPool = (borrowRate * oneMinusReserveFactor) / MANTISSA;
        return (utilizationRate(cash, borrows, reserves) * rateToPool) / MANTISSA;
    }
}
