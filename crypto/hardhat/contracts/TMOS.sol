// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Burnable} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Permit.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @title TMOS — bridged representation of the MOEX TMOS ETF (T-Capital
///        Russian Stocks) on Cyberia EVM.
/// @notice 2-decimals ERC20 with owner-controlled mint/burn, intended to be
///         driven by the Cyberia bridge / relayer to mirror MOEX share units.
contract TMOS is ERC20, ERC20Burnable, ERC20Permit, Ownable {
    constructor(address initialOwner)
        ERC20("T-Capital Russian Stocks", "TMOS")
        ERC20Permit("T-Capital Russian Stocks")
        Ownable()
    {
        if (initialOwner != address(0) && initialOwner != msg.sender) {
            _transferOwnership(initialOwner);
        }
    }

    /// @dev MOEX quotes TMOS in roubles with kopeck precision — 2 decimals
    ///      keeps on-chain units 1:1 with exchange share units.
    function decimals() public pure override returns (uint8) {
        return 2;
    }

    /// @notice Mint TMOS to `to`. Restricted to the bridge / relayer owner.
    function mint(address to, uint256 amount) external onlyOwner {
        _mint(to, amount);
    }

    /// @notice Burn TMOS from `from` (used when bridging out).
    /// @dev Owner-only path so the relayer can burn without needing allowance.
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
