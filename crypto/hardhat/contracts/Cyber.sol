// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Burnable} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Permit.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @title Cyber — bridged native CYBER on external EVM chains.
/// @notice The canonical CYBER is the Cyberia L1 gas coin. This contract is
///         its bridged form on other EVM chains (first deployment: Robinhood
///         Chain, id 4663). No premint: supply exists only against CYBER
///         actually deposited on Cyberia, driven by the bridge relayer (the
///         contract owner):
///           - evm_to_robinhood (bridge OUT of Cyberia): user deposits native
///             CYBER to the relayer EOA on Cyberia, relayer mint()s here.
///           - robinhood_to_evm (bridge IN): user transfers CYBER to the
///             relayer EOA here, relayer burnFrom()s it and pays native CYBER
///             on Cyberia — supply stays 1:1 with the Cyberia-side reserve.
/// @dev    Mirrors the Hatcher/Orbserv wrapper pattern (Ownable + mint +
///         owner-gated burnFrom). 18 decimals to match the native coin.
contract Cyber is ERC20, ERC20Burnable, ERC20Permit, Ownable {
    constructor(address initialOwner)
        ERC20("Cyber", "CYBER")
        ERC20Permit("Cyber")
        Ownable()
    {
        if (initialOwner != address(0) && initialOwner != msg.sender) {
            _transferOwnership(initialOwner);
        }
    }

    /// @notice Mint CYBER to `to`. Restricted to the bridge / relayer owner —
    ///         called on bridge-IN once the Cyberia deposit is verified.
    function mint(address to, uint256 amount) external onlyOwner {
        _mint(to, amount);
    }

    /// @notice Burn CYBER from `from` (used on bridge-OUT back to Cyberia).
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
