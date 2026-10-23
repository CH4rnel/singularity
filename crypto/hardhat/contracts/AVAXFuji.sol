// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Burnable} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Permit.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @notice Test AVAX from Avalanche Fuji, kept separate from mainnet AVAX.
/// @dev No premint. The existing bridge mints against confirmed C-Chain deposits
/// and burns deposited wrappers only after confirming the destination payout.
contract AVAXFuji is ERC20, ERC20Burnable, ERC20Permit, Ownable {
    constructor(address relayer)
        ERC20("Avalanche Fuji Test AVAX", "AVAX.FUJI")
        ERC20Permit("Avalanche Fuji Test AVAX")
    {
        require(relayer != address(0), "zero relayer");
        _transferOwnership(relayer);
    }

    function mint(address to, uint256 amount) external onlyOwner {
        _mint(to, amount);
    }

    function burnFrom(address from, uint256 amount) public override {
        if (msg.sender == owner()) {
            require(from == msg.sender, "only deposited wrappers");
            _burn(from, amount);
        } else {
            super.burnFrom(from, amount);
        }
    }
}
