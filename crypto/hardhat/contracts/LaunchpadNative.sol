// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {IUniswapV2Router02} from "./quickswap-periphery/interfaces/IUniswapV2Router02.sol";
import {IUniswapV2Factory, LaunchpadToken} from "./Launchpad.sol";

/// @title Native-CYBER fair launch.
/// @notice Successor of `Launchpad` (which charged 10k CYBER.sol): mints a
///         brand-new ERC20 and pairs 100% of its supply with the native CYBER
///         sent along (min 10 CYBER) on QuickSwap, then sends the LP tokens to
///         the burn address. The caller's CYBER is burned into permanently
///         locked liquidity — it can never be pulled back out.
contract LaunchpadNative {
    address public constant BURN = 0x000000000000000000000000000000000000dEaD;

    IUniswapV2Router02 public immutable router;
    IUniswapV2Factory public immutable factory;
    address public immutable wcyber;
    uint256 public immutable minLiquidity;
    address public owner;

    address[] public allTokens;
    /// @notice Canonical WCYBER pair for every listed token (launched or registered).
    mapping(address => address) public pairOf;

    // Same field types as the old Launchpad event, so indexers/frontends keep
    // matching on one topic; the liquidity amount is native CYBER wei here.
    event TokenLaunched(
        address indexed token,
        address indexed creator,
        address pair,
        string name,
        string symbol,
        uint256 tokenSupply,
        uint256 cyberLiquidity,
        uint256 lpBurned
    );
    event TokenRegistered(address indexed token, address pair);

    modifier onlyOwner() {
        require(msg.sender == owner, "Launchpad: not owner");
        _;
    }

    constructor(address router_, uint256 minLiquidity_) {
        require(router_ != address(0), "Launchpad: zero");
        router = IUniswapV2Router02(router_);
        factory = IUniswapV2Factory(IUniswapV2Router02(router_).factory());
        wcyber = IUniswapV2Router02(router_).WETH();
        minLiquidity = minLiquidity_;
        owner = msg.sender;
    }

    function allTokensLength() external view returns (uint256) {
        return allTokens.length;
    }

    /// @notice Launch a new token: lock 100% of its supply against the native
    ///         CYBER sent with the call and burn the resulting LP tokens.
    /// @param  name_        Token name shown to users.
    /// @param  symbol_      Token symbol.
    /// @param  totalSupply_ Full supply minted to the LP (caller keeps 0).
    function launch(
        string calldata name_,
        string calldata symbol_,
        uint256 totalSupply_
    ) external payable returns (address token, address pair, uint256 liquidity) {
        require(bytes(name_).length > 0 && bytes(symbol_).length > 0, "Launchpad: meta");
        require(totalSupply_ > 0, "Launchpad: supply=0");
        require(msg.value >= minLiquidity, "Launchpad: cyber<min");

        LaunchpadToken t = new LaunchpadToken(name_, symbol_, totalSupply_, address(this));
        token = address(t);
        allTokens.push(token);

        IERC20(token).approve(address(router), totalSupply_);

        (, , liquidity) = router.addLiquidityETH{value: msg.value}(
            token,
            totalSupply_,
            totalSupply_,
            msg.value,
            BURN,
            block.timestamp + 600
        );

        pair = factory.getPair(token, wcyber);
        pairOf[token] = pair;
        emit TokenLaunched(
            token,
            msg.sender,
            pair,
            name_,
            symbol_,
            totalSupply_,
            msg.value,
            liquidity
        );
    }

    /// @notice List a token that already exists (e.g. LAIN, MINE) together
    ///         with its canonical WCYBER pair so the launchpad UI shows it.
    function registerToken(address token, address pair) external onlyOwner {
        require(token != address(0) && pair != address(0), "Launchpad: zero");
        require(pairOf[token] == address(0), "Launchpad: listed");
        allTokens.push(token);
        pairOf[token] = pair;
        emit TokenRegistered(token, pair);
    }

    function setOwner(address newOwner) external onlyOwner {
        require(newOwner != address(0), "Launchpad: zero");
        owner = newOwner;
    }
}
