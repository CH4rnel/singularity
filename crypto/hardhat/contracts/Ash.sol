// SPDX-License-Identifier: GPL-3.0-or-later
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Burnable} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @title Ash — reward token of the Ritual DEX on Cyberia.
/// @notice Premint of exactly 1 ASH (10**18 wei) goes to the owner so that an
///         initial liquidity pair can be created. All further supply is minted
///         exclusively by the configured minter (the MasterChef contract).
/// @dev Pairs with `MasterChef.IMintableToken { mint(address,uint256) }`.
///      Owner-only `setMinter` lets us migrate emission to a new chef later.
contract Ash is ERC20, ERC20Burnable, Ownable {
    /// @notice Address allowed to mint new ASH (typically the MasterChef).
    address public minter;

    event MinterChanged(address indexed previousMinter, address indexed newMinter);

    error NotMinter();
    error ZeroAddress();

    modifier onlyMinter() {
        if (msg.sender != minter) revert NotMinter();
        _;
    }

    /// @param premintRecipient Address that receives the 1-ASH premint.
    constructor(address premintRecipient) ERC20("Ash", "ASH") Ownable() {
        if (premintRecipient == address(0)) revert ZeroAddress();
        _mint(premintRecipient, 1e18); // exactly 1 ASH for bootstrap liquidity
    }

    /// @notice Set the minter. Use this once to point at MasterChef.
    function setMinter(address newMinter) external onlyOwner {
        if (newMinter == address(0)) revert ZeroAddress();
        emit MinterChanged(minter, newMinter);
        minter = newMinter;
    }

    /// @notice Mint new ASH. Only the configured minter (MasterChef) may call.
    function mint(address to, uint256 amount) external onlyMinter {
        if (to == address(0)) revert ZeroAddress();
        _mint(to, amount);
    }
}
