// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {SafeERC20} from "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import {IUniswapV2Router02} from "./quickswap-periphery/interfaces/IUniswapV2Router02.sol";

interface IUniswapV2Factory {
    function getPair(address tokenA, address tokenB) external view returns (address);
}

/// @title Fixed-supply ERC20 minted in full to a single recipient (the Launchpad).
contract LaunchpadToken is ERC20 {
    uint8 private immutable _decimals;

    constructor(
        string memory name_,
        string memory symbol_,
        uint256 supply_,
        address to
    ) ERC20(name_, symbol_) {
        _decimals = 18;
        _mint(to, supply_);
    }

    function decimals() public view virtual override returns (uint8) {
        return _decimals;
    }
}

/// @title CYBER.sol-paired fair launch.
/// @notice Mints a brand-new ERC20, pairs 100% of its supply with the caller's
///         CYBER.sol on QuickSwap, then sends the LP tokens to the burn address
///         so the liquidity can never be pulled.
contract Launchpad {
    using SafeERC20 for IERC20;

    address public constant BURN = 0x000000000000000000000000000000000000dEaD;

    IERC20 public immutable cyberSol;
    IUniswapV2Router02 public immutable router;
    IUniswapV2Factory public immutable factory;
    uint256 public immutable minLiquidity;

    address[] public allTokens;

    event TokenLaunched(
        address indexed token,
        address indexed creator,
        address pair,
        string name,
        string symbol,
        uint256 tokenSupply,
        uint256 cyberSolLiquidity,
        uint256 lpBurned
    );

    constructor(address cyberSol_, address router_, uint256 minLiquidity_) {
        require(cyberSol_ != address(0) && router_ != address(0), "Launchpad: zero");
        cyberSol = IERC20(cyberSol_);
        router = IUniswapV2Router02(router_);
        factory = IUniswapV2Factory(IUniswapV2Router02(router_).factory());
        minLiquidity = minLiquidity_;
    }

    function allTokensLength() external view returns (uint256) {
        return allTokens.length;
    }

    /// @notice Launch a new token and lock 100% of its supply against `cyberSolAmount`
    ///         of CYBER.sol on QuickSwap. The resulting LP tokens are burned.
    /// @param  name_           Token name shown to users.
    /// @param  symbol_         Token symbol.
    /// @param  totalSupply_    Full supply minted to the LP (caller keeps 0).
    /// @param  cyberSolAmount  Amount of CYBER.sol the caller commits as liquidity.
    function launch(
        string calldata name_,
        string calldata symbol_,
        uint256 totalSupply_,
        uint256 cyberSolAmount
    ) external returns (address token, address pair, uint256 liquidity) {
        require(bytes(name_).length > 0 && bytes(symbol_).length > 0, "Launchpad: meta");
        require(totalSupply_ > 0, "Launchpad: supply=0");
        require(cyberSolAmount >= minLiquidity, "Launchpad: liq<min");

        cyberSol.safeTransferFrom(msg.sender, address(this), cyberSolAmount);

        LaunchpadToken t = new LaunchpadToken(name_, symbol_, totalSupply_, address(this));
        token = address(t);
        allTokens.push(token);

        IERC20(token).approve(address(router), totalSupply_);
        cyberSol.approve(address(router), cyberSolAmount);

        (, , liquidity) = router.addLiquidity(
            token,
            address(cyberSol),
            totalSupply_,
            cyberSolAmount,
            totalSupply_,
            cyberSolAmount,
            BURN,
            block.timestamp + 600
        );

        pair = factory.getPair(token, address(cyberSol));
        emit TokenLaunched(
            token,
            msg.sender,
            pair,
            name_,
            symbol_,
            totalSupply_,
            cyberSolAmount,
            liquidity
        );
    }
}
