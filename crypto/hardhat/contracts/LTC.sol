// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Burnable} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Permit.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @title LTC — bridged Litecoin representation on Cyberia EVM.
/// @notice 8-decimals ERC20 with owner-controlled mint/burn, intended to be
///         driven by the Cyberia bridge / relayer to mirror canonical Litecoin.
contract LTC is ERC20, ERC20Burnable, ERC20Permit, Ownable {
    constructor(address initialOwner)
        ERC20("Litecoin", "LTC")
        ERC20Permit("Litecoin")
        Ownable()
    {
        if (initialOwner != address(0) && initialOwner != msg.sender) {
            _transferOwnership(initialOwner);
        }
    }

    /// @dev Litecoin uses 8 decimals natively (litoshis), matching Bitcoin's
    ///      precision rather than the ERC20 default of 18.
    function decimals() public pure override returns (uint8) {
        return 8;
    }

    /// @notice Mint LTC to `to`. Restricted to the bridge / relayer owner.
    function mint(address to, uint256 amount) external onlyOwner {
        _mint(to, amount);
    }

    /// @notice Burn LTC from `from` (used when bridging out).
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
