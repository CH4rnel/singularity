// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Test} from "forge-std/Test.sol";
import {CyberiaProfile} from "./CyberiaProfile.sol";

contract CyberiaProfileTest is Test {
    CyberiaProfile profile;

    address alice = address(0xA11CE);
    address bob = address(0xB0B);

    function setUp() public {
        profile = new CyberiaProfile();
    }

    function test_setNickname_selfService() public {
        vm.prank(alice);
        profile.setNickname("lain_iwakura");

        assertEq(profile.nicknameOf(alice), "lain_iwakura");
        assertEq(profile.nicknameOwner(keccak256("lain_iwakura")), alice);
    }

    function test_setNickname_uniqueAcrossUsers() public {
        vm.prank(alice);
        profile.setNickname("navi");

        vm.prank(bob);
        vm.expectRevert("nickname taken");
        profile.setNickname("navi");
    }

    function test_setNickname_changeFreesPrevious() public {
        vm.startPrank(alice);
        profile.setNickname("navi");
        profile.setNickname("wired");
        vm.stopPrank();

        assertEq(profile.nicknameOf(alice), "wired");
        assertEq(profile.nicknameOwner(keccak256("navi")), address(0));

        vm.prank(bob);
        profile.setNickname("navi");
        assertEq(profile.nicknameOf(bob), "navi");
    }

    function test_setNickname_rejectsBadInput() public {
        vm.startPrank(alice);

        vm.expectRevert("nickname length");
        profile.setNickname("ab");

        vm.expectRevert("nickname length");
        profile.setNickname("abcdefghijklmnopqrstu"); // 21 chars

        vm.expectRevert("nickname charset");
        profile.setNickname("Lain"); // uppercase

        vm.expectRevert("nickname charset");
        profile.setNickname("la in"); // space

        vm.stopPrank();
    }

    function test_setNicknameFor_ownerOnly() public {
        profile.setNicknameFor(alice, "web2_user");
        assertEq(profile.nicknameOf(alice), "web2_user");

        vm.prank(bob);
        vm.expectRevert("Ownable: caller is not the owner");
        profile.setNicknameFor(alice, "hax");
    }

    function test_award_recordsAndIsIdempotent() public {
        profile.award(alice, 1);
        uint64 firstAt = profile.achievedAt(alice, 1);
        assertGt(firstAt, 0);
        assertTrue(profile.hasAchievement(alice, 1));

        // Re-award is a silent no-op that keeps the original timestamp.
        vm.warp(block.timestamp + 100);
        profile.award(alice, 1);
        assertEq(profile.achievedAt(alice, 1), firstAt);

        uint256[] memory ids = profile.achievementsOf(alice);
        assertEq(ids.length, 1);
        assertEq(ids[0], 1);
    }

    function test_award_ownerOnly() public {
        vm.prank(bob);
        vm.expectRevert("Ownable: caller is not the owner");
        profile.award(alice, 1);
    }

    function test_awardBatch() public {
        address[] memory users = new address[](3);
        uint256[] memory ids = new uint256[](3);
        users[0] = alice;
        ids[0] = 1;
        users[1] = alice;
        ids[1] = 3;
        users[2] = bob;
        ids[2] = 2;

        profile.awardBatch(users, ids);

        assertEq(profile.achievementsOf(alice).length, 2);
        assertTrue(profile.hasAchievement(alice, 1));
        assertTrue(profile.hasAchievement(alice, 3));
        assertTrue(profile.hasAchievement(bob, 2));
    }
}
