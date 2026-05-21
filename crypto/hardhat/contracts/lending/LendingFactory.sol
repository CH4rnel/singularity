// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";
import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {IERC20Metadata} from "@openzeppelin/contracts/token/ERC20/extensions/IERC20Metadata.sol";

import {LendingMarket} from "./LendingMarket.sol";
import {LendingComptroller} from "./LendingComptroller.sol";
import {ILendingComptroller} from "./interfaces/ILendingComptroller.sol";
import {IInterestRateModel} from "./interfaces/IInterestRateModel.sol";

/// @notice Convenience deployer that creates a market for an ERC20 and lists it
/// in the configured comptroller. Owner of the resulting market is the factory
/// owner so admin actions stay accessible from one EOA/multisig.
contract LendingFactory is Ownable {
    LendingComptroller public immutable comptroller;
    IInterestRateModel public defaultRateModel;

    mapping(address => address) public marketOf; // underlying => market
    address[] public allMarkets;

    event MarketCreated(address indexed underlying, address indexed market);
    event DefaultRateModelChanged(address oldModel, address newModel);

    constructor(LendingComptroller comptroller_, IInterestRateModel defaultRateModel_) {
        comptroller = comptroller_;
        defaultRateModel = defaultRateModel_;
    }

    function setDefaultRateModel(IInterestRateModel newModel) external onlyOwner {
        emit DefaultRateModelChanged(address(defaultRateModel), address(newModel));
        defaultRateModel = newModel;
    }

    function createMarket(
        IERC20 underlying,
        IInterestRateModel rateModel,
        uint256 reserveFactorMantissa,
        uint256 collateralFactorMantissa,
        string memory name,
        string memory symbol
    ) external onlyOwner returns (LendingMarket market) {
        require(marketOf[address(underlying)] == address(0), "market exists");
        IInterestRateModel model = address(rateModel) == address(0) ? defaultRateModel : rateModel;
        require(address(model) != address(0), "no rate model");

        uint8 dec = IERC20Metadata(address(underlying)).decimals();
        market = new LendingMarket(
            underlying,
            ILendingComptroller(address(comptroller)),
            model,
            reserveFactorMantissa,
            name,
            symbol,
            dec,
            owner()
        );

        comptroller.supportMarket(address(market));
        if (collateralFactorMantissa > 0) {
            comptroller.setCollateralFactor(address(market), collateralFactorMantissa);
        }

        marketOf[address(underlying)] = address(market);
        allMarkets.push(address(market));
        emit MarketCreated(address(underlying), address(market));
    }

    function getAllMarkets() external view returns (address[] memory) { return allMarkets; }
}
