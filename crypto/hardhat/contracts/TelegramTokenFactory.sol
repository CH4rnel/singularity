// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";
import {TelegramChatToken} from "./TelegramChatToken.sol";

/// @title Factory for Telegram reward tokens.
/// @notice A single deployment; the airdrop bot's deployer account calls
///         `createToken` for each Telegram chat that runs `/create_token`.
///         The factory owns nothing once the token is created — the freshly
///         deployed `TelegramChatToken` is owned by the address passed in.
contract TelegramTokenFactory is Ownable {
    event TokenCreated(
        address indexed token,
        address indexed owner,
        string name,
        string symbol
    );

    /// @notice All tokens ever created, for off-chain indexing.
    address[] public allTokens;

    constructor(address initialOwner) {
        _transferOwnership(initialOwner);
    }

    function allTokensLength() external view returns (uint256) {
        return allTokens.length;
    }

    /// @notice Deploy a new TelegramChatToken.
    /// @param name_  ERC20 name (usually the chat title / user-provided).
    /// @param symbol_ ERC20 symbol.
    /// @param tokenOwner Owner (minter) of the freshly created token. The bot
    ///        airdrop key is expected here.
    function createToken(
        string calldata name_,
        string calldata symbol_,
        address tokenOwner
    ) external onlyOwner returns (address token) {
        require(tokenOwner != address(0), "TelegramTokenFactory: zero owner");

        TelegramChatToken t = new TelegramChatToken(name_, symbol_, tokenOwner);
        token = address(t);

        allTokens.push(token);

        emit TokenCreated(token, tokenOwner, name_, symbol_);
    }
}
