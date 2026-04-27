// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";
import {TelegramChatToken} from "./TelegramChatToken.sol";

/// @title Factory for per-chat Telegram reward tokens.
/// @notice A single deployment; the airdrop bot's deployer account calls
///         `createToken` for each Telegram chat that runs `/create_token`.
///         The factory owns nothing once the token is created — the freshly
///         deployed `TelegramChatToken` is owned by the address passed in.
contract TelegramTokenFactory is Ownable {
    event TokenCreated(
        int64 indexed chatId,
        address indexed token,
        address indexed owner,
        string name,
        string symbol
    );

    /// @notice chat_id => token address. Only the latest token per chat is kept.
    mapping(int64 => address) public tokenOfChat;

    /// @notice All tokens ever created, for off-chain indexing.
    address[] public allTokens;

    constructor(address initialOwner) {
        _transferOwnership(initialOwner);
    }

    function allTokensLength() external view returns (uint256) {
        return allTokens.length;
    }

    /// @notice Deploy a new TelegramChatToken for the given Telegram chat.
    /// @param name_  ERC20 name (usually the chat title / user-provided).
    /// @param symbol_ ERC20 symbol.
    /// @param chatId Telegram chat id (negative for groups / supergroups).
    /// @param tokenOwner Owner (minter) of the freshly created token. The bot
    ///        airdrop key is expected here.
    function createToken(
        string calldata name_,
        string calldata symbol_,
        int64 chatId,
        address tokenOwner
    ) external onlyOwner returns (address token) {
        require(tokenOfChat[chatId] == address(0), "TelegramTokenFactory: token already exists");
        require(tokenOwner != address(0), "TelegramTokenFactory: zero owner");

        TelegramChatToken t = new TelegramChatToken(name_, symbol_, tokenOwner, chatId);
        token = address(t);

        tokenOfChat[chatId] = token;
        allTokens.push(token);

        emit TokenCreated(chatId, token, tokenOwner, name_, symbol_);
    }
}
