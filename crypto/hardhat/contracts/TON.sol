// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Burnable} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Permit.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @title TON — bridged Toncoin representation on Cyberia EVM.
/// @notice 18-decimals ERC20 with owner-controlled mint/burn, intended to be
///         driven by the bridge relayer (the contract owner):
///           - bridge IN:  relayer mint()s the representation to the recipient.
///           - bridge OUT: relayer burnFrom()s the deposited tokens, keeping the
///             EVM supply backed 1:1 by the native TON reserve in the bridge's
///             TON hot wallet.
/// @dev    Follows the standard Cyberia bridged-asset convention (OZ ERC20 +
///         Burnable + Permit + Ownable), same pattern as BNB/ETH/BTC. Native
///         Toncoin uses 9 decimals; the ERC20 keeps the 18-decimals default and
///         the bridge scales each side independently (like CYBER.sol 18/6).
contract TON is ERC20, ERC20Burnable, ERC20Permit, Ownable {
    constructor(address initialOwner)
        ERC20("Toncoin", "TON")
        ERC20Permit("Toncoin")
        Ownable()
    {
        if (initialOwner != address(0) && initialOwner != msg.sender) {
            _transferOwnership(initialOwner);
        }
    }

    /// @notice Mint TON to `to`. Restricted to the bridge / relayer owner.
    function mint(address to, uint256 amount) external onlyOwner {
        _mint(to, amount);
    }

    /// @notice Burn TON from `from` (used when bridging out).
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
