// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Burnable} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Permit.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @title USDC.BASE — bridged USD Coin (Base reserve) on Cyberia EVM.
/// @notice 6-decimals ERC20 with owner-controlled mint/burn, driven by the
///         bridge relayer. Deliberately SEPARATE from the existing USDC wrapper
///         (Solana reserve) so the two reserves never mix — even though both
///         are Circle-issued USDC, per-source-chain reserves stay isolated
///         (same rule as USDT vs USDT.BNB). Circle USDC on Base uses 6 decimals;
///         this wrapper matches it 1:1.
/// @dev    Standard Cyberia bridged-asset convention (OZ ERC20 + Burnable +
///         Permit + Ownable), same pattern as USDC/USDT.BNB.
contract USDCBase is ERC20, ERC20Burnable, ERC20Permit, Ownable {
    constructor(address initialOwner)
        ERC20("USD Coin (Base)", "USDC.BASE")
        ERC20Permit("USD Coin (Base)")
        Ownable()
    {
        if (initialOwner != address(0) && initialOwner != msg.sender) {
            _transferOwnership(initialOwner);
        }
    }

    /// @dev Canonical USDC uses 6 decimals, not the ERC20 default of 18.
    function decimals() public pure override returns (uint8) {
        return 6;
    }

    /// @notice Mint USDC.BASE to `to`. Restricted to the bridge / relayer owner.
    function mint(address to, uint256 amount) external onlyOwner {
        _mint(to, amount);
    }

    /// @notice Burn USDC.BASE from `from` (used when bridging out).
    /// @dev Owner-only fast path so the relayer can burn the tokens a user
    ///      transferred to it without needing an allowance; everyone else goes
    ///      through the standard allowance-checked ERC20Burnable.burnFrom.
    function burnFrom(address from, uint256 amount)
        public
        override
    {
        if (msg.sender == owner()) {
            _burn(from, amount);
            return;
        }
        super.burnFrom(from, amount);
    }
}
