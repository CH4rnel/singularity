// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Burnable} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Permit.sol";

/// @title OPENCLAW — OpenClaw AI token on Cyberia EVM.
/// @notice Fixed-supply 18-decimals ERC20. 1,000,000 OPENCLAW are minted to
///         the initial holder at deployment; no further minting is possible.
contract OPENCLAW is ERC20, ERC20Burnable, ERC20Permit {
    uint256 public constant INITIAL_SUPPLY = 1_000_000 * 10 ** 18;

    constructor(address initialHolder)
        ERC20("OpenClaw", "OPENCLAW")
        ERC20Permit("OpenClaw")
    {
        address recipient = initialHolder == address(0) ? msg.sender : initialHolder;
        _mint(recipient, INITIAL_SUPPLY);
    }
}
