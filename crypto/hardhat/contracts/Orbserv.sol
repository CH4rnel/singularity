// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Burnable} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Permit.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @title Orbserv — bridged ORBV token on Cyberia EVM.
/// @notice Solana-native token (pump.fun mint HQJwmK24WN3e87aQzNHnUVK4SaNgYfrR3tDkGgWTpump)
///         bridged to Cyberia. The canonical supply lives on Solana; this EVM
///         side is a pure mint/burn wrapper with no pre-mint. Supply is driven
///         entirely by the bridge relayer (the contract owner):
///           - sol_to_evm (bridge IN):  relayer mint()s to the recipient.
///           - evm_to_sol (bridge OUT): relayer burnFrom()s the deposited tokens,
///             keeping EVM supply backed 1:1 by the Solana hot-wallet reserve.
/// @dev    Mirrors the Hatcher wrapper pattern (Ownable + mint + owner-gated
///         burnFrom). Decimals are 6 to match the canonical Solana SPL mint.
contract Orbserv is ERC20, ERC20Burnable, ERC20Permit, Ownable {
    constructor(address initialOwner)
        ERC20("Orbserv", "ORBV")
        ERC20Permit("Orbserv")
        Ownable()
    {
        if (initialOwner != address(0) && initialOwner != msg.sender) {
            _transferOwnership(initialOwner);
        }
    }

    /// @dev Match the Solana SPL mint's decimals (6), not the ERC20 default of 18.
    function decimals() public pure override returns (uint8) {
        return 6;
    }

    /// @notice Mint ORBV to `to`. Restricted to the bridge / relayer owner.
    ///         Called on bridge-IN (sol_to_evm) once the Solana deposit is verified.
    function mint(address to, uint256 amount) external onlyOwner {
        _mint(to, amount);
    }

    /// @notice Burn ORBV from `from` (used on bridge-OUT, evm_to_sol).
    /// @dev    Owner-only fast path so the relayer can burn the tokens a user
    ///         transferred to it without needing an allowance. Non-owners fall
    ///         back to the standard allowance-checked ERC20Burnable.burnFrom.
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
