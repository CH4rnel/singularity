// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Burnable} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Permit.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @title WrappedCyber — bridged native CYBER on external EVM chains.
/// @notice The canonical CYBER is the Cyberia L1 gas coin. This wrapper is its
///         bridged form on other EVM chains (first deployment: Robinhood
///         Chain, id 4663). Supply is driven by the bridge relayer (the
///         contract owner), which premints a pooled payout inventory to
///         itself:
///           - evm_to_robinhood (bridge OUT of Cyberia): user deposits native
///             CYBER to the relayer EOA on Cyberia, relayer transfers wrapper
///             from its inventory here.
///           - robinhood_to_evm (bridge IN): user transfers wrapper to the
///             relayer EOA here (back into inventory), relayer pays native
///             CYBER on Cyberia.
/// @dev    Mirrors the Hatcher/Orbserv wrapper pattern (Ownable + mint +
///         owner-gated burnFrom). 18 decimals to match the native coin.
contract WrappedCyber is ERC20, ERC20Burnable, ERC20Permit, Ownable {
    constructor(address initialOwner)
        ERC20("Wrapped Cyber", "CYBER")
        ERC20Permit("Wrapped Cyber")
        Ownable()
    {
        if (initialOwner != address(0) && initialOwner != msg.sender) {
            _transferOwnership(initialOwner);
        }
    }

    /// @notice Mint CYBER to `to`. Restricted to the bridge / relayer owner —
    ///         used to (re)stock the pooled payout inventory.
    function mint(address to, uint256 amount) external onlyOwner {
        _mint(to, amount);
    }

    /// @notice Burn CYBER from `from`.
    /// @dev    Owner-only fast path so the relayer can retire inventory it
    ///         already holds without an allowance. Non-owners fall back to the
    ///         standard allowance-checked ERC20Burnable.burnFrom.
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
