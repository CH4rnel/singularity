// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

import {ILendingComptroller} from "./interfaces/ILendingComptroller.sol";
import {ILendingMarket} from "./interfaces/ILendingMarket.sol";
import {IPriceOracle} from "./interfaces/IPriceOracle.sol";

/// @notice Risk manager. Tracks listed markets, per-asset collateral factors,
/// and runs the liquidity check used by markets to gate borrow/redeem/liquidate.
contract LendingComptroller is ILendingComptroller, Ownable {
    uint256 public constant MANTISSA = 1e18;
    uint256 public constant COLLATERAL_FACTOR_MAX = 0.9e18;
    uint256 public constant CLOSE_FACTOR_MIN = 0.05e18;
    uint256 public constant CLOSE_FACTOR_MAX = 0.9e18;
    uint256 public constant LIQUIDATION_INCENTIVE_MIN = 1e18;
    uint256 public constant LIQUIDATION_INCENTIVE_MAX = 1.5e18;

    struct Market {
        bool isListed;
        uint256 collateralFactorMantissa;
        mapping(address => bool) accountMembership;
    }

    mapping(address => Market) internal markets;
    mapping(address => address[]) internal accountAssets;

    address[] public allMarkets;

    IPriceOracle public priceOracle;
    uint256 public closeFactorMantissa = 0.5e18;
    uint256 public liquidationIncentiveMantissa = 1.08e18;

    /// @notice Pause flags. When true, the corresponding action is globally blocked.
    bool public mintPaused;
    bool public borrowPaused;
    bool public seizePaused;

    event MarketListed(address market);
    event NewCollateralFactor(address market, uint256 oldFactor, uint256 newFactor);
    event NewCloseFactor(uint256 oldFactor, uint256 newFactor);
    event NewLiquidationIncentive(uint256 oldIncentive, uint256 newIncentive);
    event NewPriceOracle(address oldOracle, address newOracle);
    event MarketEntered(address market, address account);
    event MarketExited(address market, address account);
    event ActionPaused(string action, bool paused);

    // -------- views --------

    function isMarketListed(address market) external view returns (bool) {
        return markets[market].isListed;
    }

    function collateralFactor(address market) external view returns (uint256) {
        return markets[market].collateralFactorMantissa;
    }

    function oracle() external view returns (address) { return address(priceOracle); }

    function getAssetsIn(address account) external view returns (address[] memory) {
        return accountAssets[account];
    }

    function getAllMarkets() external view returns (address[] memory) { return allMarkets; }

    // -------- membership --------

    function enterMarkets(address[] calldata mkts) external returns (uint256[] memory results) {
        results = new uint256[](mkts.length);
        for (uint256 i = 0; i < mkts.length; i++) {
            results[i] = _addToMarketInternal(mkts[i], msg.sender) ? 0 : 1;
        }
    }

    function _addToMarketInternal(address market, address account) internal returns (bool) {
        Market storage m = markets[market];
        if (!m.isListed) return false;
        if (m.accountMembership[account]) return true;
        m.accountMembership[account] = true;
        accountAssets[account].push(market);
        emit MarketEntered(market, account);
        return true;
    }

    function exitMarket(address market) external {
        ILendingMarket m = ILendingMarket(market);
        require(m.borrowBalanceStored(msg.sender) == 0, "outstanding borrow");
        uint256 shares = _balanceOf(market, msg.sender);
        if (shares > 0) {
            _checkLiquidityWithDelta(msg.sender, market, shares, 0);
        }

        Market storage marketRef = markets[market];
        if (!marketRef.accountMembership[msg.sender]) return;
        marketRef.accountMembership[msg.sender] = false;

        address[] storage list = accountAssets[msg.sender];
        for (uint256 i = 0; i < list.length; i++) {
            if (list[i] == market) {
                list[i] = list[list.length - 1];
                list.pop();
                break;
            }
        }
        emit MarketExited(market, msg.sender);
    }

    // -------- policy hooks called by markets --------

    function mintAllowed(address market, address, uint256) external view {
        require(!mintPaused, "mint paused");
        require(markets[market].isListed, "market not listed");
    }

    function redeemAllowed(address market, address redeemer, uint256 redeemShares) external view {
        require(markets[market].isListed, "market not listed");
        if (!markets[market].accountMembership[redeemer]) return;
        _checkLiquidityWithDelta(redeemer, market, redeemShares, 0);
    }

    function borrowAllowed(address market, address borrower, uint256 borrowAmount) external {
        require(!borrowPaused, "borrow paused");
        require(markets[market].isListed, "market not listed");
        // Auto-enter the market on first borrow so it counts as collateral candidate.
        if (!markets[market].accountMembership[borrower]) {
            require(msg.sender == market, "only market can auto-enter");
            _addToMarketInternal(market, borrower);
        }
        require(priceOracle.getUnderlyingPrice(market) != 0, "price=0");
        _checkLiquidityWithDelta(borrower, market, 0, borrowAmount);
    }

    function repayBorrowAllowed(address market, address, uint256) external view {
        require(markets[market].isListed, "market not listed");
    }

    function liquidateBorrowAllowed(
        address marketBorrowed,
        address marketCollateral,
        address borrower,
        uint256 repayAmount
    ) external view {
        require(markets[marketBorrowed].isListed && markets[marketCollateral].isListed, "market not listed");
        (, , uint256 shortfall) = _getAccountLiquidityInternal(borrower);
        require(shortfall > 0, "no shortfall");

        uint256 borrowBalance = ILendingMarket(marketBorrowed).borrowBalanceStored(borrower);
        uint256 maxClose = (closeFactorMantissa * borrowBalance) / MANTISSA;
        require(repayAmount <= maxClose, "too much repay");
    }

    function seizeAllowed(address marketCollateral, address marketBorrowed) external view {
        require(!seizePaused, "seize paused");
        require(markets[marketCollateral].isListed && markets[marketBorrowed].isListed, "market not listed");
        // Ensure both markets share this comptroller — prevents cross-deployment seizures.
        require(ILendingMarket(marketBorrowed).comptroller() == address(this), "comptroller mismatch");
        require(ILendingMarket(marketCollateral).comptroller() == address(this), "comptroller mismatch");
    }

    function liquidateCalculateSeizeShares(
        address marketBorrowed,
        address marketCollateral,
        uint256 actualRepayAmount
    ) external view returns (uint256) {
        uint256 priceBorrowed = priceOracle.getUnderlyingPrice(marketBorrowed);
        uint256 priceCollateral = priceOracle.getUnderlyingPrice(marketCollateral);
        require(priceBorrowed != 0 && priceCollateral != 0, "price=0");
        uint256 exchangeRate = ILendingMarket(marketCollateral).exchangeRateStored();

        // seizeShares = repay * incentive * priceBorrowed / (priceCollateral * exchangeRate)
        // Follow Compound's Exp arithmetic so every product is scaled by MANTISSA
        // before division — without dividing `numerator` here the result was off
        // by a full 1e18 factor, leading the seize step to burn far more shares
        // than the borrower owns.
        uint256 numerator = (liquidationIncentiveMantissa * priceBorrowed) / MANTISSA;
        uint256 denominator = (priceCollateral * exchangeRate) / MANTISSA;
        uint256 ratio = (numerator * MANTISSA) / denominator;
        return (ratio * actualRepayAmount) / MANTISSA;
    }

    // -------- liquidity --------

    function getAccountLiquidity(address account)
        external view returns (uint256, uint256, uint256)
    {
        return _getAccountLiquidityInternal(account);
    }

    function _getAccountLiquidityInternal(address account)
        internal view returns (uint256 err, uint256 liquidity, uint256 shortfall)
    {
        return _getHypotheticalLiquidity(account, address(0), 0, 0);
    }

    struct LiquidityVars {
        uint256 sumCollateral;
        uint256 sumBorrowPlusEffects;
        uint256 tokensToDenom;
        uint256 price;
    }

    /// @notice Like Compound's getHypotheticalAccountLiquidity. Treats `market`
    /// as if the user just redeemed `redeemShares` and borrowed `borrowAmount`.
    function _getHypotheticalLiquidity(
        address account,
        address market,
        uint256 redeemShares,
        uint256 borrowAmount
    ) internal view returns (uint256 err, uint256 liquidity, uint256 shortfall) {
        LiquidityVars memory v;
        address[] memory assets = accountAssets[account];
        for (uint256 i = 0; i < assets.length; i++) {
            address asset = assets[i];
            v.price = priceOracle.getUnderlyingPrice(asset);
            require(v.price != 0, "price=0");
            v.tokensToDenom = (
                ((markets[asset].collateralFactorMantissa * ILendingMarket(asset).exchangeRateStored()) / MANTISSA)
                    * v.price
            ) / MANTISSA;

            v.sumCollateral += (v.tokensToDenom * _balanceOf(asset, account)) / MANTISSA;
            v.sumBorrowPlusEffects += (v.price * ILendingMarket(asset).borrowBalanceStored(account)) / MANTISSA;

            if (asset == market) {
                v.sumBorrowPlusEffects += (v.tokensToDenom * redeemShares) / MANTISSA;
                v.sumBorrowPlusEffects += (v.price * borrowAmount) / MANTISSA;
            }
        }

        if (v.sumCollateral > v.sumBorrowPlusEffects) {
            return (0, v.sumCollateral - v.sumBorrowPlusEffects, 0);
        }
        return (0, 0, v.sumBorrowPlusEffects - v.sumCollateral);
    }

    function _checkLiquidityWithDelta(address account, address market, uint256 redeemShares, uint256 borrowAmount)
        internal view
    {
        (, , uint256 shortfall) = _getHypotheticalLiquidity(account, market, redeemShares, borrowAmount);
        require(shortfall == 0, "insufficient liquidity");
    }

    function _balanceOf(address market, address account) internal view returns (uint256) {
        return ILendingMarket(market) == ILendingMarket(address(0))
            ? 0
            : IERC20Like(market).balanceOf(account);
    }

    // -------- admin --------

    function setPriceOracle(IPriceOracle newOracle) external onlyOwner {
        emit NewPriceOracle(address(priceOracle), address(newOracle));
        priceOracle = newOracle;
    }

    function setCloseFactor(uint256 newFactor) external onlyOwner {
        require(newFactor >= CLOSE_FACTOR_MIN && newFactor <= CLOSE_FACTOR_MAX, "closeFactor OOB");
        emit NewCloseFactor(closeFactorMantissa, newFactor);
        closeFactorMantissa = newFactor;
    }

    function setLiquidationIncentive(uint256 newIncentive) external onlyOwner {
        require(
            newIncentive >= LIQUIDATION_INCENTIVE_MIN && newIncentive <= LIQUIDATION_INCENTIVE_MAX,
            "incentive OOB"
        );
        emit NewLiquidationIncentive(liquidationIncentiveMantissa, newIncentive);
        liquidationIncentiveMantissa = newIncentive;
    }

    function supportMarket(address market) external onlyOwner {
        require(!markets[market].isListed, "already listed");
        // Sanity: market must report a comptroller pointer back to us.
        require(ILendingMarket(market).comptroller() == address(this), "comptroller mismatch");
        markets[market].isListed = true;
        allMarkets.push(market);
        emit MarketListed(market);
    }

    function setCollateralFactor(address market, uint256 newFactor) external onlyOwner {
        require(markets[market].isListed, "market not listed");
        require(newFactor <= COLLATERAL_FACTOR_MAX, "factor>max");
        if (newFactor > 0) {
            require(address(priceOracle) != address(0), "no oracle");
            require(priceOracle.getUnderlyingPrice(market) != 0, "price=0");
        }
        emit NewCollateralFactor(market, markets[market].collateralFactorMantissa, newFactor);
        markets[market].collateralFactorMantissa = newFactor;
    }

    function setMintPaused(bool paused) external onlyOwner { mintPaused = paused; emit ActionPaused("Mint", paused); }
    function setBorrowPaused(bool paused) external onlyOwner { borrowPaused = paused; emit ActionPaused("Borrow", paused); }
    function setSeizePaused(bool paused) external onlyOwner { seizePaused = paused; emit ActionPaused("Seize", paused); }
}

interface IERC20Like {
    function balanceOf(address) external view returns (uint256);
}
