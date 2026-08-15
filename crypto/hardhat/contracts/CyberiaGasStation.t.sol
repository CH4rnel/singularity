// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Test} from "forge-std/Test.sol";
import {CyberiaGasStation} from "./CyberiaGasStation.sol";

/// A recipient that tries to claim again from inside the transfer it is being
/// paid by. Used to prove the cooldown is written before the coin moves.
contract ReentrantRecipient {
    CyberiaGasStation immutable station;
    bool public reentered;
    bool public reentryReverted;

    constructor(CyberiaGasStation station_) {
        station = station_;
    }

    receive() external payable {
        if (reentered) {
            return;
        }

        reentered = true;

        try station.claim(payable(address(this))) {
            reentryReverted = false;
        } catch {
            reentryReverted = true;
        }
    }
}

/// A recipient that refuses payment, so `claim` fails rather than silently
/// counting a drip nobody received.
contract RejectingRecipient {
    receive() external payable {
        revert("no thanks");
    }
}

contract CyberiaGasStationTest is Test {
    CyberiaGasStation station;

    address owner = address(0x0FFE);
    address operator = address(0x09E4);
    address alice = address(0xA11CE);
    address bob = address(0xB0B);

    function setUp() public {
        vm.prank(owner);
        station = new CyberiaGasStation(owner);

        vm.deal(address(station), 100 ether);

        vm.prank(owner);
        station.setOperator(operator, true);
    }

    function test_defaults() public view {
        assertEq(station.owner(), owner);
        assertTrue(station.isOperator(owner));
        assertEq(station.dripAmount(), 0.01 ether);
        assertEq(station.balanceCeiling(), 0.001 ether);
        assertEq(station.cooldown(), 6 hours);
        assertEq(station.dailyCap(), 5 ether);
        assertFalse(station.paused());
    }

    function test_claim_paysTheDrip() public {
        vm.prank(operator);
        uint256 amount = station.claim(payable(alice));

        assertEq(amount, 0.01 ether);
        assertEq(alice.balance, 0.01 ether);
        assertEq(station.claimCount(alice), 1);
        assertEq(station.sponsoredCount(), 1);
        assertEq(station.totalSponsored(), 0.01 ether);
        assertEq(station.lastClaimAt(alice), block.timestamp);
    }

    function test_claim_onlyOperator() public {
        vm.prank(alice);
        vm.expectRevert("not operator");
        station.claim(payable(alice));
    }

    /// The whole containment argument: an address already able to pay a fee is
    /// not sponsored, so the station cannot be turned into an income stream.
    function test_claim_refusesAnAddressThatHasGas() public {
        vm.deal(bob, 0.001 ether);

        (bool ok, string memory reason) = station.canClaim(bob);
        assertFalse(ok);
        assertEq(reason, "has gas");

        vm.prank(operator);
        vm.expectRevert("has gas");
        station.claim(payable(bob));
    }

    function test_claim_allowsAnAddressJustUnderTheCeiling() public {
        vm.deal(bob, 0.001 ether - 1);

        (bool ok, ) = station.canClaim(bob);
        assertTrue(ok);
    }

    function test_claim_cooldown() public {
        vm.prank(operator);
        station.claim(payable(alice));

        // Spend it, so only the cooldown stands between alice and a second drip.
        vm.deal(alice, 0);

        (bool ok, string memory reason) = station.canClaim(alice);
        assertFalse(ok);
        assertEq(reason, "cooling down");
        assertEq(station.cooldownRemaining(alice), 6 hours);

        vm.warp(block.timestamp + 6 hours);

        assertEq(station.cooldownRemaining(alice), 0);
        vm.prank(operator);
        station.claim(payable(alice));
        assertEq(station.claimCount(alice), 2);
    }

    function test_claim_dailyCap() public {
        vm.prank(owner);
        station.setPolicy(1 ether, 0.5 ether, 0, 2 ether);

        vm.startPrank(operator);
        station.claim(payable(address(0xAA1)));
        station.claim(payable(address(0xAA2)));

        (bool ok, string memory reason) = station.canClaim(address(0xAA3));
        assertFalse(ok);
        assertEq(reason, "daily cap reached");

        vm.expectRevert("daily cap reached");
        station.claim(payable(address(0xAA3)));
        vm.stopPrank();

        assertEq(station.remainingToday(), 0);

        // A day is a fixed UTC bucket, so nothing has to be called to reset it.
        vm.warp(block.timestamp + 1 days);
        assertEq(station.remainingToday(), 2 ether);

        vm.prank(operator);
        station.claim(payable(address(0xAA3)));
        assertEq(address(0xAA3).balance, 1 ether);
    }

    function test_claim_emptyStation() public {
        vm.prank(owner);
        station.withdraw(payable(owner), address(station).balance);

        (bool ok, string memory reason) = station.canClaim(alice);
        assertFalse(ok);
        assertEq(reason, "station empty");
    }

    function test_claim_paused() public {
        vm.prank(owner);
        station.setPaused(true);

        (bool ok, string memory reason) = station.canClaim(alice);
        assertFalse(ok);
        assertEq(reason, "paused");

        vm.prank(operator);
        vm.expectRevert("paused");
        station.claim(payable(alice));
    }

    function test_claim_rejectsZeroRecipient() public {
        vm.prank(operator);
        vm.expectRevert("zero recipient");
        station.claim(payable(address(0)));
    }

    /// A recipient that reverts on payment must not leave a drip counted.
    function test_claim_revertsWhenTheRecipientRefuses() public {
        RejectingRecipient recipient = new RejectingRecipient();

        vm.prank(operator);
        vm.expectRevert("transfer failed");
        station.claim(payable(address(recipient)));

        assertEq(station.sponsoredCount(), 0);
        assertEq(station.totalSponsored(), 0);
    }

    /// Effects are written before the coin moves, so re-entering claims nothing.
    function test_claim_reentrancyIsStoppedByTheCooldown() public {
        vm.prank(owner);
        station.setOperator(address(this), true);

        ReentrantRecipient recipient = new ReentrantRecipient(station);
        vm.prank(owner);
        station.setOperator(address(recipient), true);

        station.claim(payable(address(recipient)));

        assertTrue(recipient.reentered());
        assertTrue(recipient.reentryReverted());
        assertEq(address(recipient).balance, 0.01 ether);
        assertEq(station.claimCount(address(recipient)), 1);
    }

    function test_fundingIsOpenToAnyone() public {
        vm.deal(alice, 1 ether);

        vm.prank(alice);
        (bool sent, ) = address(station).call{value: 1 ether}("");

        assertTrue(sent);
        assertEq(address(station).balance, 101 ether);
    }

    function test_dripsLeft() public view {
        assertEq(station.dripsLeft(), 100 ether / 0.01 ether);
    }

    function test_setPolicy_ownerOnly() public {
        vm.prank(alice);
        vm.expectRevert("not owner");
        station.setPolicy(1 ether, 1 ether, 1 hours, 10 ether);

        vm.startPrank(owner);
        vm.expectRevert("zero drip");
        station.setPolicy(0, 1 ether, 1 hours, 10 ether);

        vm.expectRevert("cap below drip");
        station.setPolicy(2 ether, 1 ether, 1 hours, 1 ether);

        station.setPolicy(0.02 ether, 0.002 ether, 12 hours, 10 ether);
        vm.stopPrank();

        assertEq(station.dripAmount(), 0.02 ether);
        assertEq(station.balanceCeiling(), 0.002 ether);
        assertEq(station.cooldown(), 12 hours);
        assertEq(station.dailyCap(), 10 ether);
    }

    function test_setOperator_ownerOnlyAndRevocable() public {
        vm.prank(alice);
        vm.expectRevert("not owner");
        station.setOperator(alice, true);

        vm.prank(owner);
        station.setOperator(operator, false);

        vm.prank(operator);
        vm.expectRevert("not operator");
        station.claim(payable(alice));
    }

    function test_withdraw_ownerOnly() public {
        vm.prank(alice);
        vm.expectRevert("not owner");
        station.withdraw(payable(alice), 1 ether);

        vm.prank(owner);
        vm.expectRevert("insufficient tank");
        station.withdraw(payable(owner), 101 ether);

        vm.prank(owner);
        station.withdraw(payable(owner), 40 ether);
        assertEq(owner.balance, 40 ether);
        assertEq(address(station).balance, 60 ether);
    }

    function test_transferOwnership() public {
        vm.prank(owner);
        station.transferOwnership(alice);

        assertEq(station.owner(), alice);

        vm.prank(owner);
        vm.expectRevert("not owner");
        station.setPaused(true);
    }

    function test_summary() public {
        vm.prank(operator);
        station.claim(payable(alice));

        (
            uint256 tank,
            uint256 drip,
            uint256 ceiling,
            uint256 wait,
            uint256 cap,
            uint256 remaining,
            uint256 servedTotal,
            uint256 spentTotal,
            bool isPaused
        ) = station.summary();

        assertEq(tank, 100 ether - 0.01 ether);
        assertEq(drip, 0.01 ether);
        assertEq(ceiling, 0.001 ether);
        assertEq(wait, 6 hours);
        assertEq(cap, 5 ether);
        assertEq(remaining, 5 ether - 0.01 ether);
        assertEq(servedTotal, 1);
        assertEq(spentTotal, 0.01 ether);
        assertFalse(isPaused);
    }
}
