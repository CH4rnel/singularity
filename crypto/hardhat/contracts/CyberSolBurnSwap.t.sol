// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Test} from "forge-std/Test.sol";
import {CyberSolBurnSwap} from "./CyberSolBurnSwap.sol";
import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";

/// @dev Minimal ERC20 to simulate bridged CYBER.sol.
contract MockCyberSol is ERC20 {
    constructor() ERC20("CYBER.sol", "CYBER.sol") {
        _mint(msg.sender, 1_000_000 ether);
    }
}

contract CyberSolBurnSwapTest is Test {
    address constant DEAD = 0x000000000000000000000000000000000000dEaD;

    CyberSolBurnSwap swap;
    MockCyberSol cyberSol;

    address user = address(0xCAFE);

    function setUp() public {
        cyberSol = new MockCyberSol();
        swap = new CyberSolBurnSwap(address(cyberSol));

        // Fund the swap with native CYBER payout liquidity and the user with input.
        vm.deal(address(swap), 100 ether);
        cyberSol.transfer(user, 10_000 ether);
    }

    function test_swap_burnsInputToDeadAndPaysNative() public {
        vm.startPrank(user);
        cyberSol.approve(address(swap), 1000 ether);
        swap.swap(1000 ether);
        vm.stopPrank();

        // 1000 CYBER.sol -> 1 CYBER, input forwarded to the dead address.
        assertEq(cyberSol.balanceOf(DEAD), 1000 ether);
        assertEq(cyberSol.balanceOf(address(swap)), 0);
        assertEq(cyberSol.balanceOf(user), 9_000 ether);
        assertEq(user.balance, 1 ether);
        assertEq(swap.totalBurned(), 1000 ether);
    }

    function test_swap_revertsOnNonMultipleOfRate() public {
        vm.startPrank(user);
        cyberSol.approve(address(swap), 1500 ether);
        vm.expectRevert("amount not multiple of RATE");
        swap.swap(1500); // 1500 wei, not a multiple of 1000
        vm.stopPrank();
    }

    function test_swap_revertsWhenAmountTooSmall() public {
        vm.startPrank(user);
        cyberSol.approve(address(swap), 1000 ether);
        vm.expectRevert("amount too small");
        swap.swap(0);
        vm.stopPrank();
    }

    function test_swap_revertsOnInsufficientLiquidity() public {
        // Drain native liquidity so the payout cannot be covered.
        vm.prank(address(this));
        swap.withdrawNative(address(this), 100 ether);

        vm.startPrank(user);
        cyberSol.approve(address(swap), 1000 ether);
        vm.expectRevert("insufficient CYBER liquidity");
        swap.swap(1000 ether);
        vm.stopPrank();
    }

    function test_quote() public view {
        assertEq(swap.quote(1000 ether), 1 ether);
        assertEq(swap.quote(5000 ether), 5 ether);
    }

    /// @dev Allow this test contract to receive native via withdrawNative.
    receive() external payable {}
}
