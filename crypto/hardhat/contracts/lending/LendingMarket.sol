// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {SafeERC20} from "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import {ReentrancyGuard} from "@openzeppelin/contracts/security/ReentrancyGuard.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

import {ILendingMarket} from "./interfaces/ILendingMarket.sol";
import {ILendingComptroller} from "./interfaces/ILendingComptroller.sol";
import {IInterestRateModel} from "./interfaces/IInterestRateModel.sol";

/// @notice One isolated money market for a single ERC20 underlying.
/// Share token balances represent the depositor's claim on the pool;
/// the exchange rate (underlying per share) drifts up over time as
/// interest accrues. Borrows are tracked separately with a borrow index.
contract LendingMarket is ERC20, ILendingMarket, Ownable, ReentrancyGuard {
    using SafeERC20 for IERC20;

    uint256 public constant MANTISSA = 1e18;
    uint256 public constant INITIAL_EXCHANGE_RATE = 2e16; // 1 underlying → 50 shares initially
    uint256 public constant RESERVE_FACTOR_MAX = 1e18;
    uint256 public constant BORROW_RATE_MAX_PER_BLOCK = 5e14; // ~0.05%/block ceiling

    IERC20 public immutable underlyingToken;
    uint8 private immutable _underlyingDecimals;

    ILendingComptroller public comptrollerContract;
    IInterestRateModel public rateModel;

    uint256 public totalBorrows;
    uint256 public totalReserves;
    uint256 public borrowIndex = MANTISSA;
    uint256 public accrualBlockNumber;
    uint256 public reserveFactorMantissa; // 0..1e18

    struct BorrowSnapshot {
        uint256 principal;
        uint256 interestIndex;
    }
    mapping(address => BorrowSnapshot) internal accountBorrows;

    event AccrueInterest(uint256 cashPrior, uint256 interestAccumulated, uint256 borrowIndex, uint256 totalBorrows);
    event Mint(address indexed minter, uint256 mintAmount, uint256 mintShares);
    event Redeem(address indexed redeemer, uint256 redeemAmount, uint256 redeemShares);
    event Borrow(address indexed borrower, uint256 borrowAmount, uint256 accountBorrows, uint256 totalBorrows);
    event RepayBorrow(address indexed payer, address indexed borrower, uint256 repayAmount, uint256 accountBorrows, uint256 totalBorrows);
    event LiquidateBorrow(address indexed liquidator, address indexed borrower, uint256 repayAmount, address collateralMarket, uint256 seizeShares);
    event NewComptroller(address oldComptroller, address newComptroller);
    event NewInterestRateModel(address oldModel, address newModel);
    event NewReserveFactor(uint256 oldFactor, uint256 newFactor);
    event ReservesAdded(address admin, uint256 addAmount, uint256 newTotalReserves);
    event ReservesReduced(address admin, uint256 reduceAmount, uint256 newTotalReserves);

    constructor(
        IERC20 underlying_,
        ILendingComptroller comptroller_,
        IInterestRateModel rateModel_,
        uint256 reserveFactorMantissa_,
        string memory name_,
        string memory symbol_,
        uint8 underlyingDecimals_,
        address admin_
    ) ERC20(name_, symbol_) {
        require(reserveFactorMantissa_ <= RESERVE_FACTOR_MAX, "reserveFactor>1");
        underlyingToken = underlying_;
        comptrollerContract = comptroller_;
        rateModel = rateModel_;
        reserveFactorMantissa = reserveFactorMantissa_;
        _underlyingDecimals = underlyingDecimals_;
        accrualBlockNumber = block.number;
        if (admin_ != address(0) && admin_ != msg.sender) {
            _transferOwnership(admin_);
        }
    }

    // Share token decimals are intentionally 8 (Compound convention) so the
    // exchange rate fits comfortably regardless of underlying decimals.
    function decimals() public pure override returns (uint8) {
        return 8;
    }

    // -------- views --------

    function underlying() external view returns (address) { return address(underlyingToken); }
    function comptroller() external view returns (address) { return address(comptrollerContract); }
    function interestRateModel() external view returns (address) { return address(rateModel); }

    function getCash() public view returns (uint256) {
        return underlyingToken.balanceOf(address(this));
    }

    /// @notice Underlying per share, mantissa 1e18. When totalSupply == 0, uses initial rate.
    function exchangeRateStored() public view returns (uint256) {
        uint256 supply = totalSupply();
        if (supply == 0) return INITIAL_EXCHANGE_RATE;
        uint256 cashPlusBorrowsMinusReserves = getCash() + totalBorrows - totalReserves;
        return (cashPlusBorrowsMinusReserves * MANTISSA) / supply;
    }

    function exchangeRateCurrent() external returns (uint256) {
        accrueInterest();
        return exchangeRateStored();
    }

    function borrowBalanceStored(address account) public view returns (uint256) {
        BorrowSnapshot memory s = accountBorrows[account];
        if (s.principal == 0) return 0;
        return (s.principal * borrowIndex) / s.interestIndex;
    }

    function borrowBalanceCurrent(address account) external returns (uint256) {
        accrueInterest();
        return borrowBalanceStored(account);
    }

    // -------- accrual --------

    function accrueInterest() public {
        uint256 currentBlock = block.number;
        if (accrualBlockNumber == currentBlock) return;

        uint256 cashPrior = getCash();
        uint256 borrowsPrior = totalBorrows;
        uint256 reservesPrior = totalReserves;
        uint256 indexPrior = borrowIndex;

        uint256 borrowRate = rateModel.getBorrowRate(cashPrior, borrowsPrior, reservesPrior);
        require(borrowRate <= BORROW_RATE_MAX_PER_BLOCK, "borrow rate too high");

        uint256 blockDelta = currentBlock - accrualBlockNumber;
        uint256 simpleInterestFactor = borrowRate * blockDelta; // mantissa
        uint256 interestAccumulated = (simpleInterestFactor * borrowsPrior) / MANTISSA;

        totalBorrows = borrowsPrior + interestAccumulated;
        totalReserves = reservesPrior + (reserveFactorMantissa * interestAccumulated) / MANTISSA;
        borrowIndex = indexPrior + (simpleInterestFactor * indexPrior) / MANTISSA;
        accrualBlockNumber = currentBlock;

        emit AccrueInterest(cashPrior, interestAccumulated, borrowIndex, totalBorrows);
    }

    // -------- user actions --------

    /// @notice Deposit `mintAmount` of underlying and receive shares.
    function mint(uint256 mintAmount) external nonReentrant returns (uint256 mintedShares) {
        accrueInterest();
        comptrollerContract.mintAllowed(address(this), msg.sender, mintAmount);

        uint256 rate = exchangeRateStored();
        uint256 balanceBefore = underlyingToken.balanceOf(address(this));
        underlyingToken.safeTransferFrom(msg.sender, address(this), mintAmount);
        uint256 received = underlyingToken.balanceOf(address(this)) - balanceBefore;

        mintedShares = (received * MANTISSA) / rate;
        require(mintedShares > 0, "zero shares");
        _mint(msg.sender, mintedShares);
        emit Mint(msg.sender, received, mintedShares);
    }

    /// @notice Burn `redeemShares` and withdraw the proportional underlying.
    function redeem(uint256 redeemShares) external nonReentrant returns (uint256 redeemAmount) {
        accrueInterest();
        comptrollerContract.redeemAllowed(address(this), msg.sender, redeemShares);

        uint256 rate = exchangeRateStored();
        redeemAmount = (redeemShares * rate) / MANTISSA;
        require(redeemAmount <= getCash(), "insufficient cash");

        _burn(msg.sender, redeemShares);
        underlyingToken.safeTransfer(msg.sender, redeemAmount);
        emit Redeem(msg.sender, redeemAmount, redeemShares);
    }

    /// @notice Withdraw exactly `redeemAmount` underlying by burning the necessary shares.
    function redeemUnderlying(uint256 redeemAmount) external nonReentrant returns (uint256 burnedShares) {
        accrueInterest();
        uint256 rate = exchangeRateStored();
        burnedShares = (redeemAmount * MANTISSA + rate - 1) / rate; // round up shares
        comptrollerContract.redeemAllowed(address(this), msg.sender, burnedShares);

        require(redeemAmount <= getCash(), "insufficient cash");
        _burn(msg.sender, burnedShares);
        underlyingToken.safeTransfer(msg.sender, redeemAmount);
        emit Redeem(msg.sender, redeemAmount, burnedShares);
    }

    function borrow(uint256 borrowAmount) external nonReentrant {
        accrueInterest();
        comptrollerContract.borrowAllowed(address(this), msg.sender, borrowAmount);
        require(borrowAmount <= getCash(), "insufficient cash");

        uint256 newBalance = borrowBalanceStored(msg.sender) + borrowAmount;
        accountBorrows[msg.sender] = BorrowSnapshot({principal: newBalance, interestIndex: borrowIndex});
        totalBorrows += borrowAmount;

        underlyingToken.safeTransfer(msg.sender, borrowAmount);
        emit Borrow(msg.sender, borrowAmount, newBalance, totalBorrows);
    }

    function repayBorrow(uint256 repayAmount) external nonReentrant returns (uint256 actual) {
        return _repayBorrowInternal(msg.sender, msg.sender, repayAmount);
    }

    function repayBorrowBehalf(address borrower, uint256 repayAmount) external nonReentrant returns (uint256 actual) {
        return _repayBorrowInternal(msg.sender, borrower, repayAmount);
    }

    function _repayBorrowInternal(address payer, address borrower, uint256 repayAmount) internal returns (uint256 actual) {
        accrueInterest();
        comptrollerContract.repayBorrowAllowed(address(this), borrower, repayAmount);

        uint256 owed = borrowBalanceStored(borrower);
        actual = repayAmount == type(uint256).max || repayAmount > owed ? owed : repayAmount;
        require(actual > 0, "nothing to repay");

        uint256 balanceBefore = underlyingToken.balanceOf(address(this));
        underlyingToken.safeTransferFrom(payer, address(this), actual);
        actual = underlyingToken.balanceOf(address(this)) - balanceBefore;

        uint256 newBalance = owed - actual;
        accountBorrows[borrower] = BorrowSnapshot({principal: newBalance, interestIndex: borrowIndex});
        totalBorrows -= actual;
        emit RepayBorrow(payer, borrower, actual, newBalance, totalBorrows);
    }

    /// @notice Repay part of `borrower`'s debt and seize a proportional amount of
    /// their collateral shares from `collateralMarket`.
    function liquidateBorrow(
        address borrower,
        uint256 repayAmount,
        ILendingMarket collateralMarket
    ) external nonReentrant returns (uint256 seizeShares) {
        accrueInterest();
        // Force the collateral market to also accrue before reading exchange rate.
        if (address(collateralMarket) != address(this)) {
            ILendingMarket(collateralMarket).accrueInterest();
        }
        comptrollerContract.liquidateBorrowAllowed(
            address(this), address(collateralMarket), borrower, repayAmount
        );
        require(borrower != msg.sender, "self liquidate");

        uint256 actualRepay = _repayBorrowInternal(msg.sender, borrower, repayAmount);
        seizeShares = comptrollerContract.liquidateCalculateSeizeShares(
            address(this), address(collateralMarket), actualRepay
        );
        require(seizeShares > 0, "zero seize");

        if (address(collateralMarket) == address(this)) {
            _seize(msg.sender, borrower, seizeShares);
        } else {
            collateralMarket.seize(msg.sender, borrower, seizeShares);
        }
        emit LiquidateBorrow(msg.sender, borrower, actualRepay, address(collateralMarket), seizeShares);
    }

    /// @notice Cross-market seize hook. Only callable by another listed market via the comptroller.
    function seize(address liquidator, address borrower, uint256 seizeShares) external nonReentrant {
        comptrollerContract.seizeAllowed(address(this), msg.sender);
        _seize(liquidator, borrower, seizeShares);
    }

    function _seize(address liquidator, address borrower, uint256 seizeShares) internal {
        require(borrower != liquidator, "self seize");
        // Liquidation incentive carved off into protocol reserves.
        uint256 incentive = comptrollerContract.liquidationIncentiveMantissa();
        uint256 protocolShareNumerator = (incentive - MANTISSA);
        uint256 protocolSeizeShares = (seizeShares * protocolShareNumerator) / (incentive * 10);
        uint256 liquidatorSeizeShares = seizeShares - protocolSeizeShares;

        uint256 rate = exchangeRateStored();
        uint256 protocolReservesIncrement = (protocolSeizeShares * rate) / MANTISSA;

        _burn(borrower, seizeShares);
        if (protocolSeizeShares > 0) {
            totalReserves += protocolReservesIncrement;
        }
        if (liquidatorSeizeShares > 0) {
            _mint(liquidator, liquidatorSeizeShares);
        }
    }

    // -------- admin --------

    function setComptroller(ILendingComptroller newComptroller) external onlyOwner {
        emit NewComptroller(address(comptrollerContract), address(newComptroller));
        comptrollerContract = newComptroller;
    }

    function setInterestRateModel(IInterestRateModel newModel) external onlyOwner {
        accrueInterest();
        emit NewInterestRateModel(address(rateModel), address(newModel));
        rateModel = newModel;
    }

    function setReserveFactor(uint256 newFactor) external onlyOwner {
        accrueInterest();
        require(newFactor <= RESERVE_FACTOR_MAX, "factor>1");
        emit NewReserveFactor(reserveFactorMantissa, newFactor);
        reserveFactorMantissa = newFactor;
    }

    function addReserves(uint256 addAmount) external nonReentrant {
        accrueInterest();
        uint256 before = underlyingToken.balanceOf(address(this));
        underlyingToken.safeTransferFrom(msg.sender, address(this), addAmount);
        uint256 received = underlyingToken.balanceOf(address(this)) - before;
        totalReserves += received;
        emit ReservesAdded(msg.sender, received, totalReserves);
    }

    function reduceReserves(uint256 reduceAmount) external onlyOwner {
        accrueInterest();
        require(reduceAmount <= totalReserves, "exceeds reserves");
        require(reduceAmount <= getCash(), "insufficient cash");
        totalReserves -= reduceAmount;
        underlyingToken.safeTransfer(msg.sender, reduceAmount);
        emit ReservesReduced(msg.sender, reduceAmount, totalReserves);
    }
}
