// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {SafeERC20} from "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";
import {ReentrancyGuard} from "@openzeppelin/contracts/security/ReentrancyGuard.sol";

/// @title CyberSolSwap
/// @notice Fixed-rate redeemer that converts bridged CYBER.sol into native CYBER
///         at a hard 1000 : 1 ratio (1000 CYBER.sol -> 1 CYBER).
/// @dev    Both CYBER.sol and native CYBER use 18 decimals, so the payout in wei
///         is simply amountIn / RATE. Payouts come from this contract's own native
///         balance, so it must be funded with CYBER before any swap can succeed.
contract CyberSolSwap is Ownable, ReentrancyGuard {
    using SafeERC20 for IERC20;

    /// @notice CYBER.sol required per 1 native CYBER.
    uint256 public constant RATE = 1000;

    /// @notice The bridged CYBER.sol ERC20 accepted as input.
    IERC20 public immutable cyberSol;

    event Swapped(address indexed user, uint256 amountIn, uint256 amountOut);
    event NativeFunded(address indexed from, uint256 amount);
    event TokensWithdrawn(address indexed to, uint256 amount);
    event NativeWithdrawn(address indexed to, uint256 amount);

    constructor(address cyberSol_) Ownable() {
        require(cyberSol_ != address(0), "cyberSol=0");
        cyberSol = IERC20(cyberSol_);
    }

    /// @notice Swap `amountIn` CYBER.sol for `amountIn / RATE` native CYBER.
    /// @dev    Caller must approve this contract for `amountIn` beforehand.
    function swap(uint256 amountIn) external nonReentrant {
        require(amountIn % RATE == 0, "amount not multiple of RATE");
        uint256 amountOut = amountIn / RATE;
        require(amountOut > 0, "amount too small");
        require(address(this).balance >= amountOut, "insufficient CYBER liquidity");

        cyberSol.safeTransferFrom(msg.sender, address(this), amountIn);

        (bool ok, ) = msg.sender.call{value: amountOut}("");
        require(ok, "native transfer failed");

        emit Swapped(msg.sender, amountIn, amountOut);
    }

    /// @notice How much native CYBER would be paid out for `amountIn` CYBER.sol.
    function quote(uint256 amountIn) external pure returns (uint256) {
        return amountIn / RATE;
    }

    /// @notice Fund the contract with native CYBER liquidity.
    receive() external payable {
        emit NativeFunded(msg.sender, msg.value);
    }

    /// @notice Owner rescue of collected CYBER.sol tokens.
    function withdrawTokens(address to, uint256 amount) external onlyOwner {
        cyberSol.safeTransfer(to, amount);
        emit TokensWithdrawn(to, amount);
    }

    /// @notice Owner withdrawal / removal of native CYBER liquidity.
    function withdrawNative(address to, uint256 amount) external onlyOwner {
        (bool ok, ) = to.call{value: amount}("");
        require(ok, "native transfer failed");
        emit NativeWithdrawn(to, amount);
    }
}
