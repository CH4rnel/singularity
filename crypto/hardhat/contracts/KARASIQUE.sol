// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Burnable} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Permit.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @title KARASIQUE (KRSQ) — Cyberia EVM token.
/// @notice 18-decimals ERC20 with owner-controlled mint/burn, following the
///         standard Cyberia token convention (OZ ERC20 + Burnable + Permit).
contract KARASIQUE is ERC20, ERC20Burnable, ERC20Permit, Ownable {
    constructor(address initialOwner)
        ERC20("KARASIQUE", "KRSQ")
        ERC20Permit("KARASIQUE")
        Ownable()
    {
        if (initialOwner != address(0) && initialOwner != msg.sender) {
            _transferOwnership(initialOwner);
        }
    }

    /// @notice Mint KRSQ to `to`. Restricted to the owner.
    function mint(address to, uint256 amount) external onlyOwner {
        _mint(to, amount);
    }

    /// @notice Burn KRSQ from `from`.
    /// @dev Owner-only path so the owner can burn without needing allowance;
    ///      everyone else goes through the standard allowance-checked burnFrom.
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
