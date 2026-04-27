// SPDX-License-Identifier: MIT
// Compatible with OpenZeppelin Contracts ^4.9.0
pragma solidity ^0.8.19;

import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";
import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/draft-ERC20Permit.sol";
import {ERC20Votes} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Votes.sol";

/// @title Per-chat Telegram reward token (ERC20 + Votes).
/// @notice Deployed by `TelegramTokenFactory`. Minting is restricted to the owner,
///         which is expected to be the bot's airdrop deployer account.
contract TelegramChatToken is ERC20, ERC20Permit, ERC20Votes, Ownable {
    /// @notice Telegram chat id this token belongs to. Stored only for on-chain
    /// traceability, the bot backend uses DB for bookkeeping.
    int64 public immutable chatId;

    constructor(
        string memory name_,
        string memory symbol_,
        address initialOwner,
        int64 chatId_
    ) ERC20(name_, symbol_) ERC20Permit(name_) {
        chatId = chatId_;
        _transferOwnership(initialOwner);
    }

    function mint(address to, uint256 amount) external onlyOwner {
        _mint(to, amount);
    }

    // The following functions are required overrides for ERC20Votes.

    function _afterTokenTransfer(address from, address to, uint256 amount)
        internal
        override(ERC20, ERC20Votes)
    {
        super._afterTokenTransfer(from, to, amount);
    }

    function _mint(address to, uint256 amount) internal override(ERC20, ERC20Votes) {
        super._mint(to, amount);
    }

    function _burn(address account, uint256 amount) internal override(ERC20, ERC20Votes) {
        super._burn(account, amount);
    }
}
