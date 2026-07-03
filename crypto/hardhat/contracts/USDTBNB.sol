// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Burnable} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Permit.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @title USDT.BNB — bridged Tether USD (BNB Chain reserve) on Cyberia EVM.
/// @notice 18-decimals ERC20 with owner-controlled mint/burn, driven by the
///         bridge relayer. Deliberately SEPARATE from the existing USDT
///         wrapper (Solana reserve, 6 decimals) so the two reserves never mix.
///         BSC-USDT uses 18 decimals; this wrapper matches it 1:1.
/// @dev    Standard Cyberia bridged-asset convention (OZ ERC20 + Burnable +
///         Permit + Ownable), same pattern as ETH/BNB.
contract USDTBNB is ERC20, ERC20Burnable, ERC20Permit, Ownable {
    constructor(address initialOwner)
        ERC20("Tether USD (BNB Chain)", "USDT.BNB")
        ERC20Permit("Tether USD (BNB Chain)")
        Ownable()
    {
        if (initialOwner != address(0) && initialOwner != msg.sender) {
            _transferOwnership(initialOwner);
        }
    }

    /// @notice Mint USDT.BNB to `to`. Restricted to the bridge / relayer owner.
    function mint(address to, uint256 amount) external onlyOwner {
        _mint(to, amount);
    }

    /// @notice Burn USDT.BNB from `from` (used when bridging out).
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
