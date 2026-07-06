// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Burnable} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Permit.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @title ETH.BASE — bridged Base-native ETH representation on Cyberia EVM.
/// @notice 18-decimals ERC20 with owner-controlled mint/burn, driven by the
///         bridge relayer (the contract owner):
///           - bridge IN:  relayer mint()s the representation to the recipient.
///           - bridge OUT: relayer burnFrom()s the deposited tokens, keeping the
///             EVM supply backed 1:1 by the native ETH reserve on Base.
///         Deliberately SEPARATE from the generic ETH wrapper (0xFDa2…1986, a
///         DEX/lending asset) so per-source-chain bridge reserves never mix —
///         same rule as USDT (Solana) vs USDT.BNB (BSC).
/// @dev    Standard Cyberia bridged-asset convention (OZ ERC20 + Burnable +
///         Permit + Ownable), same pattern as ETH/BNB. ETH uses 18 decimals
///         natively, matching the ERC20 default, so no decimals() override.
contract ETHBase is ERC20, ERC20Burnable, ERC20Permit, Ownable {
    constructor(address initialOwner)
        ERC20("Ethereum (Base)", "ETH.BASE")
        ERC20Permit("Ethereum (Base)")
        Ownable()
    {
        if (initialOwner != address(0) && initialOwner != msg.sender) {
            _transferOwnership(initialOwner);
        }
    }

    /// @notice Mint ETH.BASE to `to`. Restricted to the bridge / relayer owner.
    function mint(address to, uint256 amount) external onlyOwner {
        _mint(to, amount);
    }

    /// @notice Burn ETH.BASE from `from` (used when bridging out).
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
