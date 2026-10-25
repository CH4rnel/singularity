// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Test} from "forge-std/Test.sol";
import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {LainStakingVault} from "./LainStakingVault.sol";

contract MockLain is ERC20 {
    constructor() ERC20("Lain-OS.com", "LAIN") {}

    function mint(address to, uint256 amount) external {
        _mint(to, amount);
    }
}

contract LainStakingVaultTest is Test {
    MockLain lain;
    LainStakingVault vault;

    address owner = address(0x0FFE);
    address alice = address(0xA11CE);
    address bob = address(0xB0B);
    address carol = address(0xCA401);

    uint256 constant REWARDS = 7_500_000 ether;
    uint256 constant SIX_MONTHS = 182 days;
    uint256 constant CAP = 2_000_000 ether;

    function setUp() public {
        lain = new MockLain();
        vault = new LainStakingVault(lain, owner, CAP);

        vm.startPrank(owner);
        vault.addTier(7 days, 12_500);
        vault.addTier(30 days, 16_000);
        vault.addTier(90 days, 20_000);
        vm.stopPrank();

        lain.mint(owner, REWARDS);
        for (uint256 i = 0; i < 3; i++) {
            address who = [alice, bob, carol][i];
            lain.mint(who, 10_000_000 ether);
            vm.prank(who);
            lain.approve(address(vault), type(uint256).max);
        }
    }

    function _fund() internal {
        vm.prank(owner);
        lain.transfer(address(vault), REWARDS);
        vm.prank(owner);
        vault.schedule(SIX_MONTHS);
    }

    function _assets(address who, uint256 id) internal view returns (uint256 assets) {
        (,,, assets) = vault.position(who, id);
    }

    function test_streamsEverySecondAndCompoundsWithoutAClaim() public {
        vm.prank(alice);
        vault.deposit(0, 1_000_000 ether);
        _fund();

        uint256 rate = vault.rewardRate();
        assertEq(rate, REWARDS / SIX_MONTHS);

        vm.warp(block.timestamp + 1);
        assertApproxEqAbs(_assets(alice, 0), 1_000_000 ether + rate, 1e9);

        vm.warp(block.timestamp + 1 days - 1);
        assertApproxEqAbs(_assets(alice, 0), 1_000_000 ether + rate * 1 days, 1e9);

        vm.prank(alice);
        uint256 out = vault.withdraw(0);
        assertApproxEqAbs(out, 1_000_000 ether + rate * 1 days, 1e9);
        assertEq(lain.balanceOf(alice), 10_000_000 ether - 1_000_000 ether + out);
    }

    function test_boostSplitsTheStreamByWeight() public {
        vm.prank(alice);
        vault.deposit(0, 1_000_000 ether);
        vm.prank(bob);
        vault.deposit(3, 1_000_000 ether);
        _fund();

        vm.warp(block.timestamp + 1 days);
        uint256 a = _assets(alice, 0) - 1_000_000 ether;
        uint256 b = _assets(bob, 0) - 1_000_000 ether;
        assertApproxEqRel(b, 2 * a, 1e12);
        assertApproxEqAbs(a + b, vault.rewardRate() * 1 days, 1e9);
    }

    function test_lockIsEnforced() public {
        vm.prank(bob);
        vault.deposit(1, 1000 ether);

        vm.warp(block.timestamp + 7 days - 1);
        vm.prank(bob);
        vm.expectRevert(bytes("locked"));
        vault.withdraw(0);

        vm.warp(block.timestamp + 1);
        vm.prank(bob);
        vault.withdraw(0);
    }

    function test_expiredLockCanBeKickedToFlexibleButNotBefore() public {
        vm.prank(bob);
        vault.deposit(3, 1000 ether);

        vm.prank(carol);
        vm.expectRevert(bytes("locked"));
        vault.kick(bob, 0);

        vm.warp(block.timestamp + 90 days);
        vm.prank(carol);
        vault.kick(bob, 0);

        (uint256 tierId,,,) = vault.position(bob, 0);
        assertEq(tierId, 0);
        assertApproxEqAbs(_assets(bob, 0), 1000 ether, 1);

        vm.expectRevert(bytes("flexible"));
        vault.kick(bob, 0);
    }

    function test_restakeKeepsEarningsAndStartsANewLock() public {
        vm.prank(alice);
        vault.deposit(0, 1000 ether);
        _fund();
        vm.warp(block.timestamp + 1 days);
        uint256 before = _assets(alice, 0);

        vm.prank(alice);
        vault.restake(0, 2);
        (uint256 tierId, uint64 unlockAt,,) = vault.position(alice, 0);
        assertEq(tierId, 2);
        assertEq(unlockAt, block.timestamp + 30 days);
        assertApproxEqAbs(_assets(alice, 0), before, 1e9);

        vm.prank(alice);
        vm.expectRevert(bytes("locked"));
        vault.restake(0, 0);
    }

    function test_ownerCannotTakeTheReserveOrShortenTheSchedule() public {
        _fund();
        vm.prank(owner);
        vm.expectRevert(bytes("shorter"));
        vault.schedule(30 days);

        vm.prank(owner);
        vault.schedule(365 days);
        assertEq(vault.periodFinish(), block.timestamp + 365 days);

        vm.prank(alice);
        vm.expectRevert(bytes("Ownable: caller is not the owner"));
        vault.schedule(400 days);
    }

    function test_unstakedTimeStaysInTheReserve() public {
        _fund();
        vm.warp(block.timestamp + 10 days);
        assertEq(vault.reserve(), REWARDS);

        vm.prank(alice);
        vault.deposit(0, 1000 ether);
        vm.warp(block.timestamp + 1 days);
        assertApproxEqAbs(_assets(alice, 0) - 1000 ether, vault.rewardRate() * 1 days, 1e9);
    }

    function test_streamStopsAtTheEndAndNeverOverpays() public {
        vm.prank(alice);
        vault.deposit(0, 1000 ether);
        vm.prank(bob);
        vault.deposit(3, 5000 ether);
        _fund();

        vm.warp(block.timestamp + SIX_MONTHS + 30 days);
        vm.prank(alice);
        uint256 a = vault.withdraw(0);
        vm.prank(bob);
        uint256 b = vault.withdraw(0);

        assertLe(a + b, 6000 ether + REWARDS);
        assertApproxEqAbs(a + b, 6000 ether + vault.rewardRate() * SIX_MONTHS, 1e12);
        assertEq(lain.balanceOf(address(vault)), vault.reserve() + vault.totalStaked());
    }

    function test_smallFirstDepositCannotEatTheNextOne() public {
        vm.prank(alice);
        vm.expectRevert(bytes("amount"));
        vault.deposit(0, 1 ether - 1);

        vm.prank(alice);
        vault.deposit(0, 1 ether);
        _fund();
        vm.warp(block.timestamp + 1 days);

        vm.prank(bob);
        vault.deposit(0, 1000 ether);
        assertApproxEqAbs(_assets(bob, 0), 1000 ether, 1e9);
    }

    function test_capCountsPrincipalNotEarnings() public {
        vm.prank(alice);
        vault.deposit(0, 1_500_000 ether);
        vm.prank(alice);
        vm.expectRevert(bytes("cap"));
        vault.deposit(3, 500_001 ether);
        vm.prank(alice);
        vault.deposit(3, 500_000 ether);
        assertEq(vault.principalOf(alice), CAP);

        _fund();
        vm.warp(block.timestamp + 30 days);
        assertGt(_assets(alice, 0), 1_500_000 ether);

        vm.prank(alice);
        vault.withdraw(0);
        assertEq(vault.principalOf(alice), 500_000 ether);
        vm.prank(alice);
        vault.deposit(0, 1_500_000 ether);
    }

    function test_capSurvivesARestake() public {
        vm.prank(bob);
        vault.deposit(0, CAP);
        vm.prank(bob);
        vault.restake(0, 3);
        assertEq(vault.principalOf(bob), CAP);
        vm.prank(bob);
        vm.expectRevert(bytes("cap"));
        vault.deposit(0, 1 ether);
    }

    function test_tiersAreBounded() public {
        vm.startPrank(owner);
        vm.expectRevert(bytes("boost"));
        vault.addTier(1 days, 9_999);
        vm.expectRevert(bytes("lock"));
        vault.addTier(0, 10_000);
        vault.addTier(1 days, 10_500);
        vault.addTier(180 days, 25_000);
        vault.addTier(365 days, 30_000);
        vault.addTier(730 days, 40_000);
        vm.expectRevert(bytes("tiers"));
        vault.addTier(2 days, 11_000);
        vm.stopPrank();
    }
}
