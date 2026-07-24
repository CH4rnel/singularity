// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Burnable} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Permit.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @title Ash (bridged) — canonical ASH bridged onto satellite chains.
/// @notice ASH is minted only on Cyberia (by the Cyberia MasterChef). This is
///         its bridged form on satellite chains (first: Robinhood): a
///         relayer-owned mint/burn wrapper with no premint. The farm-funding
///         keeper mints it here against ASH it has accrued and locked on
///         Cyberia (the satellite share harvested from a channel pool), so the
///         wrapped supply stays backed 1:1 by that Cyberia reserve. Burned on
///         the way back.
/// @dev    Same pattern as the Cyber/Hatcher wrappers (Ownable + owner-gated
///         mint/burnFrom). 18 decimals to match the canonical Cyberia ASH.
contract AshBridged is ERC20, ERC20Burnable, ERC20Permit, Ownable {
    constructor(address initialOwner)
        ERC20("Ash", "ASH")
        ERC20Permit("Ash")
        Ownable()
    {
        if (initialOwner != address(0) && initialOwner != msg.sender) {
            _transferOwnership(initialOwner);
        }
    }

    /// @notice Mint bridged ASH to `to`. Relayer/owner only — used by the
    ///         keeper to fund satellite farms against the Cyberia reserve.
    function mint(address to, uint256 amount) external onlyOwner {
        _mint(to, amount);
    }

    /// @notice Burn bridged ASH (on the way back to Cyberia).
    /// @dev    Owner fast-path so the relayer can burn without an allowance;
    ///         non-owners use the standard allowance-checked burnFrom.
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
