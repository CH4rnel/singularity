// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {Test} from "forge-std/Test.sol";
import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ServiceHub, IWrappedNative, ILaunchLocker} from "./ServiceHub.sol";

contract MockWrapped is ERC20 {
    constructor() ERC20("Wrapped CYBER", "WCYBER") {}

    function deposit() external payable {
        _mint(msg.sender, msg.value);
    }

    function withdraw(uint256 amount) external {
        _burn(msg.sender, amount);
        (bool ok, ) = msg.sender.call{value: amount}("");
        require(ok, "SEND");
    }
}

/// Stands in for LaunchToken: `distribute` books whatever WCYBER arrived.
contract MockServiceToken is ERC20 {
    MockWrapped immutable wrapped;
    uint256 public distributed;

    constructor(MockWrapped wrapped_) ERC20("Service", "SVC") {
        wrapped = wrapped_;
        _mint(msg.sender, 1_000_000 ether);
    }

    function distribute() external returns (uint256 credited) {
        credited = wrapped.balanceOf(address(this)) - distributed;
        distributed += credited;
    }
}

/// Stands in for LaunchLocker: pays a preset creator share to the hub.
contract MockLocker {
    MockWrapped immutable wrapped;
    MockServiceToken immutable token;
    address public creator;
    uint256 public wrappedOwed;
    uint256 public tokenOwed;

    constructor(MockWrapped wrapped_, MockServiceToken token_) {
        wrapped = wrapped_;
        token = token_;
    }

    function owe(address creator_, uint256 wrappedAmount, uint256 tokenAmount) external {
        creator = creator_;
        wrappedOwed = wrappedAmount;
        tokenOwed = tokenAmount;
    }

    function setCreator(uint256, address to) external {
        require(msg.sender == creator, "NOT_CREATOR");
        creator = to;
    }

    function collect(uint256) external returns (uint256, uint256, uint256, uint256, uint256, uint256) {
        wrapped.transfer(creator, wrappedOwed);
        token.transfer(creator, tokenOwed);
        (uint256 w, uint256 t) = (wrappedOwed, tokenOwed);
        wrappedOwed = 0;
        tokenOwed = 0;
        return (t, w, 0, 0, 0, 0);
    }
}

contract ServiceHubTest is Test {
    address constant FEES = 0x54BB8a86Fc001d5BB3e6C7ad2e2f153E5Fa6d7f6;
    address constant OPERATOR = address(0xA11CE);
    address constant PAYER = address(0xB0B);
    address constant BURN = 0x000000000000000000000000000000000000dEaD;

    MockWrapped wrapped;
    MockServiceToken token;
    MockLocker locker;
    ServiceHub hub;

    function setUp() public {
        wrapped = new MockWrapped();
        token = new MockServiceToken(wrapped);
        locker = new MockLocker(wrapped, token);
        hub = new ServiceHub(IWrappedNative(address(wrapped)), ILaunchLocker(address(locker)), FEES, 100);
        hub.listService(address(token), OPERATOR, 7, 5_000);
        vm.deal(PAYER, 100 ether);
    }

    function test_payment_splits_fee_holders_and_treasury() public {
        vm.prank(PAYER);
        hub.pay{value: 10 ether}(address(token), bytes32("order-1"));

        // 1% to the fee address, then half of the rest to holders, half to the treasury
        assertEq(wrapped.balanceOf(FEES), 0.1 ether);
        assertEq(wrapped.balanceOf(address(token)), 4.95 ether);
        assertEq(token.distributed(), 4.95 ether);
        (, , , , , , uint256 treasury) = hub.services(address(token));
        assertEq(treasury, 4.95 ether);
        assertEq(hub.accounted(), 4.95 ether);
    }

    function test_pool_fees_pay_no_protocol_cut_and_burn_the_token_side() public {
        wrapped.deposit{value: 2 ether}();
        wrapped.transfer(address(locker), 2 ether);
        token.transfer(address(locker), 500 ether);
        locker.owe(address(hub), 2 ether, 500 ether);

        (uint256 w, uint256 b) = hub.collectLaunchFees(address(token));

        assertEq(w, 2 ether);
        assertEq(b, 500 ether);
        assertEq(token.balanceOf(BURN), 500 ether);
        assertEq(wrapped.balanceOf(FEES), 0);
        assertEq(token.distributed(), 1 ether);
    }

    function test_payment_in_token_is_burned() public {
        token.transfer(PAYER, 100 ether);
        vm.startPrank(PAYER);
        token.approve(address(hub), 100 ether);
        hub.payInToken(address(token), 100 ether, bytes32("order-2"));
        vm.stopPrank();
        assertEq(token.balanceOf(BURN), 100 ether);
    }

    function test_only_operator_spends_treasury_and_never_more_than_it_holds() public {
        vm.prank(PAYER);
        hub.pay{value: 10 ether}(address(token), bytes32(0));

        vm.prank(PAYER);
        vm.expectRevert(ServiceHub.NotOperator.selector);
        hub.withdrawTreasury(address(token), PAYER, 1 ether, false);

        vm.startPrank(OPERATOR);
        vm.expectRevert(ServiceHub.Insufficient.selector);
        hub.withdrawTreasury(address(token), OPERATOR, 5 ether, false);

        hub.withdrawTreasury(address(token), OPERATOR, 4.95 ether, true);
        vm.stopPrank();
        assertEq(OPERATOR.balance, 4.95 ether);
        assertEq(hub.accounted(), 0);
    }

    function test_bounds_hold_against_the_owner() public {
        vm.expectRevert(ServiceHub.Bounds.selector);
        hub.setProtocolFee(101);

        vm.expectRevert(ServiceHub.Bounds.selector);
        hub.setService(address(token), 7, 5_000, 5_001, 0);
    }

    function test_discount_needs_the_holding() public {
        vm.prank(OPERATOR);
        hub.setService(address(token), 7, 5_000, 2_000, 1_000 ether);

        assertEq(hub.discountOf(address(token), PAYER), 0);
        token.transfer(PAYER, 1_000 ether);
        assertEq(hub.discountOf(address(token), PAYER), 2_000);
    }

    function test_unattributed_cannot_double_count_a_treasury() public {
        vm.prank(PAYER);
        hub.pay{value: 10 ether}(address(token), bytes32(0));
        wrapped.deposit{value: 1 ether}();
        wrapped.transfer(address(hub), 1 ether);

        assertEq(hub.unattributed(), 1 ether);
        vm.expectRevert(ServiceHub.Insufficient.selector);
        hub.creditUnattributed(address(token), 1 ether + 1);
        hub.creditUnattributed(address(token), 1 ether);
        assertEq(hub.unattributed(), 0);
    }

    function test_only_the_owner_can_hand_the_lock_on() public {
        locker.owe(address(hub), 0, 0);

        vm.prank(OPERATOR);
        vm.expectRevert(ServiceHub.NotOwner.selector);
        hub.releaseLock(address(token), OPERATOR);

        hub.releaseLock(address(token), OPERATOR);
        assertEq(locker.creator(), OPERATOR);
    }

    function test_unlisted_token_is_refused() public {
        vm.prank(PAYER);
        vm.expectRevert(ServiceHub.Unlisted.selector);
        hub.pay{value: 1 ether}(address(0x1234), bytes32(0));
    }

    receive() external payable {}
}
