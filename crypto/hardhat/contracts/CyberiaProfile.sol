// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @title CyberiaProfile — on-chain nicknames and service achievements.
/// @notice One registry for both halves of a Cyberia identity:
///           - nicknames: globally unique, lowercase [a-z0-9_], 3–20 chars.
///             Users set their own via setNickname(); the owner (the site
///             relayer) can set one on a user's behalf via setNicknameFor()
///             so web2-onboarded users without gas still get on-chain names.
///           - achievements: numeric badge ids awarded by the owner when the
///             backend detects qualifying activity (first swap, first bridge,
///             liquidity farming, conversion, ...). Ids are dumb numbers on
///             purpose — meaning/artwork live off-chain, new badges need no
///             contract change. Awards are permanent and idempotent.
contract CyberiaProfile is Ownable {
    /// @notice Current nickname of a user ("" when unset).
    mapping(address => string) public nicknameOf;

    /// @notice Owner of a nickname, keyed by keccak256(bytes(nickname)).
    mapping(bytes32 => address) public nicknameOwner;

    /// @notice Unlock timestamp per user per achievement id (0 = not earned).
    mapping(address => mapping(uint256 => uint64)) public achievedAt;

    mapping(address => uint256[]) private _achievements;

    event NicknameSet(address indexed user, string nickname);
    event AchievementUnlocked(address indexed user, uint256 indexed id, uint64 at);

    /// @notice Set your own nickname (frees your previous one).
    function setNickname(string calldata nickname) external {
        _setNickname(msg.sender, nickname);
    }

    /// @notice Relayer-assisted nickname set for users without gas.
    function setNicknameFor(address user, string calldata nickname) external onlyOwner {
        require(user != address(0), "zero user");
        _setNickname(user, nickname);
    }

    /// @notice Award an achievement. No-op if already earned.
    function award(address user, uint256 id) public onlyOwner {
        require(user != address(0), "zero user");

        if (achievedAt[user][id] != 0) {
            return;
        }

        achievedAt[user][id] = uint64(block.timestamp);
        _achievements[user].push(id);
        emit AchievementUnlocked(user, id, uint64(block.timestamp));
    }

    /// @notice Award several achievements in one transaction.
    function awardBatch(address[] calldata users, uint256[] calldata ids) external onlyOwner {
        require(users.length == ids.length, "length mismatch");

        for (uint256 i = 0; i < users.length; i++) {
            award(users[i], ids[i]);
        }
    }

    /// @notice All achievement ids a user has earned, in unlock order.
    function achievementsOf(address user) external view returns (uint256[] memory) {
        return _achievements[user];
    }

    function hasAchievement(address user, uint256 id) external view returns (bool) {
        return achievedAt[user][id] != 0;
    }

    function _setNickname(address user, string calldata nickname) internal {
        bytes memory b = bytes(nickname);
        require(b.length >= 3 && b.length <= 20, "nickname length");

        // Lowercase-only charset makes uniqueness case-insensitive by
        // construction — no on-chain normalization needed.
        for (uint256 i = 0; i < b.length; i++) {
            bytes1 c = b[i];
            require(
                (c >= 0x61 && c <= 0x7A) || (c >= 0x30 && c <= 0x39) || c == 0x5F,
                "nickname charset"
            );
        }

        bytes32 key = keccak256(b);
        address current = nicknameOwner[key];
        require(current == address(0) || current == user, "nickname taken");

        bytes memory previous = bytes(nicknameOf[user]);

        if (previous.length != 0) {
            delete nicknameOwner[keccak256(previous)];
        }

        nicknameOwner[key] = user;
        nicknameOf[user] = nickname;
        emit NicknameSet(user, nickname);
    }
}
