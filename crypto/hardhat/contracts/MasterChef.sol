// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {SafeERC20} from "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";

/// @title MasterChef — Uniswap V2 style farming with CYBER rewards.
/// @dev Simplified MasterChef V1 pattern. Owner adds pools, users stake LP tokens,
///      earn CYBER proportional to allocPoint share.
interface IMintableToken is IERC20 {
    function mint(address to, uint256 amount) external;
}

contract MasterChef is Ownable {
    using SafeERC20 for IERC20;

    struct UserInfo {
        uint256 amount;     // LP tokens staked
        uint256 rewardDebt; // Reward debt for proper reward accounting
    }

    struct PoolInfo {
        IERC20 lpToken;           // LP token contract
        uint256 allocPoint;       // Allocation points for this pool
        uint256 lastRewardBlock;  // Last block rewards were calculated
        uint256 accRewardPerShare; // Accumulated rewards per share (×1e12)
    }

    IMintableToken public rewardToken;
    uint256 public rewardPerBlock;

    PoolInfo[] public poolInfo;
    mapping(uint256 => mapping(address => UserInfo)) public userInfo;
    uint256 public totalAllocPoint;
    uint256 public startBlock;

    event Deposit(address indexed user, uint256 indexed pid, uint256 amount);
    event Withdraw(address indexed user, uint256 indexed pid, uint256 amount);
    event EmergencyWithdraw(address indexed user, uint256 indexed pid, uint256 amount);

    constructor(
        IMintableToken _rewardToken,
        uint256 _rewardPerBlock,
        uint256 _startBlock
    ) Ownable() {
        rewardToken = _rewardToken;
        rewardPerBlock = _rewardPerBlock;
        startBlock = _startBlock;
    }

    function poolLength() external view returns (uint256) {
        return poolInfo.length;
    }

    /// @notice Add a new LP pool. Can only be called by owner.
    function add(uint256 _allocPoint, IERC20 _lpToken, bool _withUpdate) public onlyOwner {
        if (_withUpdate) {
            massUpdatePools();
        }
        uint256 lastRewardBlock = block.number > startBlock ? block.number : startBlock;
        totalAllocPoint += _allocPoint;
        poolInfo.push(PoolInfo({
            lpToken: _lpToken,
            allocPoint: _allocPoint,
            lastRewardBlock: lastRewardBlock,
            accRewardPerShare: 0
        }));
    }

    /// @notice Update allocation points for a pool.
    function set(uint256 _pid, uint256 _allocPoint, bool _withUpdate) public onlyOwner {
        if (_withUpdate) {
            massUpdatePools();
        }
        totalAllocPoint = totalAllocPoint - poolInfo[_pid].allocPoint + _allocPoint;
        poolInfo[_pid].allocPoint = _allocPoint;
    }

    /// @notice View pending rewards for a user.
    function pendingReward(uint256 _pid, address _user) external view returns (uint256) {
        PoolInfo storage pool = poolInfo[_pid];
        UserInfo storage user = userInfo[_pid][_user];
        uint256 accRewardPerShare = pool.accRewardPerShare;
        uint256 lpSupply = pool.lpToken.balanceOf(address(this));
        if (block.number > pool.lastRewardBlock && lpSupply != 0) {
            uint256 blocks = block.number - pool.lastRewardBlock;
            uint256 reward = blocks * rewardPerBlock * pool.allocPoint / totalAllocPoint;
            accRewardPerShare += reward * 1e12 / lpSupply;
        }
        return user.amount * accRewardPerShare / 1e12 - user.rewardDebt;
    }

    function massUpdatePools() public {
        for (uint256 pid = 0; pid < poolInfo.length; ++pid) {
            updatePool(pid);
        }
    }

    function updatePool(uint256 _pid) public {
        PoolInfo storage pool = poolInfo[_pid];
        if (block.number <= pool.lastRewardBlock) return;
        uint256 lpSupply = pool.lpToken.balanceOf(address(this));
        if (lpSupply == 0) {
            pool.lastRewardBlock = block.number;
            return;
        }
        uint256 blocks = block.number - pool.lastRewardBlock;
        uint256 reward = blocks * rewardPerBlock * pool.allocPoint / totalAllocPoint;
        rewardToken.mint(address(this), reward);
        pool.accRewardPerShare += reward * 1e12 / lpSupply;
        pool.lastRewardBlock = block.number;
    }

    /// @notice Deposit LP tokens to earn rewards. Use amount=0 to harvest only.
    function deposit(uint256 _pid, uint256 _amount) public {
        PoolInfo storage pool = poolInfo[_pid];
        UserInfo storage user = userInfo[_pid][msg.sender];
        updatePool(_pid);
        if (user.amount > 0) {
            uint256 pending = user.amount * pool.accRewardPerShare / 1e12 - user.rewardDebt;
            if (pending > 0) {
                safeRewardTransfer(msg.sender, pending);
            }
        }
        if (_amount > 0) {
            pool.lpToken.safeTransferFrom(msg.sender, address(this), _amount);
            user.amount += _amount;
        }
        user.rewardDebt = user.amount * pool.accRewardPerShare / 1e12;
        emit Deposit(msg.sender, _pid, _amount);
    }

    /// @notice Withdraw LP tokens and harvest rewards.
    function withdraw(uint256 _pid, uint256 _amount) public {
        PoolInfo storage pool = poolInfo[_pid];
        UserInfo storage user = userInfo[_pid][msg.sender];
        require(user.amount >= _amount, "MasterChef: insufficient balance");
        updatePool(_pid);
        uint256 pending = user.amount * pool.accRewardPerShare / 1e12 - user.rewardDebt;
        if (pending > 0) {
            safeRewardTransfer(msg.sender, pending);
        }
        if (_amount > 0) {
            user.amount -= _amount;
            pool.lpToken.safeTransfer(msg.sender, _amount);
        }
        user.rewardDebt = user.amount * pool.accRewardPerShare / 1e12;
        emit Withdraw(msg.sender, _pid, _amount);
    }

    /// @notice Emergency withdraw without caring about rewards.
    function emergencyWithdraw(uint256 _pid) public {
        PoolInfo storage pool = poolInfo[_pid];
        UserInfo storage user = userInfo[_pid][msg.sender];
        uint256 amount = user.amount;
        user.amount = 0;
        user.rewardDebt = 0;
        pool.lpToken.safeTransfer(msg.sender, amount);
        emit EmergencyWithdraw(msg.sender, _pid, amount);
    }

    /// @notice Update reward emission rate. Only owner.
    function setRewardPerBlock(uint256 _rewardPerBlock) external onlyOwner {
        massUpdatePools();
        rewardPerBlock = _rewardPerBlock;
    }

    function safeRewardTransfer(address _to, uint256 _amount) internal {
        uint256 bal = rewardToken.balanceOf(address(this));
        if (_amount > bal) {
            rewardToken.transfer(_to, bal);
        } else {
            rewardToken.transfer(_to, _amount);
        }
    }
}
