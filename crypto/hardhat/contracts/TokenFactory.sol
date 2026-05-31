// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";
import {VotingToken} from "./VotingToken.sol";

/// @title Factory for generic voting ERC20 tokens.
/// @notice Deploys `VotingToken` instances that support OpenZeppelin voting
///         and have owner-only minting.
contract TokenFactory is Ownable {
    event TokenCreated(
        address indexed token,
        address indexed owner,
        string name,
        string symbol
    );

    address[] public allTokens;

    constructor(address initialOwner) {
        require(initialOwner != address(0), "TokenFactory: zero owner");
        _transferOwnership(initialOwner);
    }

    function allTokensLength() external view returns (uint256) {
        return allTokens.length;
    }

    function createToken(
        string calldata name_,
        string calldata symbol_,
        address tokenOwner
    ) external onlyOwner returns (address token) {
        require(tokenOwner != address(0), "TokenFactory: zero owner");

        VotingToken t = new VotingToken(name_, symbol_, tokenOwner);
        token = address(t);

        allTokens.push(token);
        emit TokenCreated(token, tokenOwner, name_, symbol_);
    }
}
