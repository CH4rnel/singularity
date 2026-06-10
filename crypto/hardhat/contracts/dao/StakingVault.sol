// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {SafeERC20} from "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/draft-ERC20Permit.sol";
import {ERC20Votes} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Votes.sol";

/// @notice Wraps any ERC20 into an ERC20Votes token for DAO participation.
/// Staking locks underlying tokens and grants voting power 1:1.
contract StakingVault is ERC20, ERC20Permit, ERC20Votes {
    using SafeERC20 for IERC20;

    IERC20 public immutable underlying;

    event Staked(address indexed user, uint256 amount);
    event Unstaked(address indexed user, uint256 amount);

    constructor(IERC20 _underlying, string memory _name, string memory _symbol)
        ERC20(_name, _symbol)
        ERC20Permit(_name)
    {
        underlying = _underlying;
    }

    /// @notice Lock tokens and receive voting power. Auto-delegates to self on first stake.
    function stake(uint256 amount) external {
        underlying.safeTransferFrom(msg.sender, address(this), amount);
        _mint(msg.sender, amount);
        if (delegates(msg.sender) == address(0)) {
            _delegate(msg.sender, msg.sender);
        }
        emit Staked(msg.sender, amount);
    }

    /// @notice Burn voting shares and receive underlying tokens back.
    function unstake(uint256 amount) external {
        _burn(msg.sender, amount);
        underlying.safeTransfer(msg.sender, amount);
        emit Unstaked(msg.sender, amount);
    }

    function _afterTokenTransfer(address from, address to, uint256 amount)
        internal override(ERC20, ERC20Votes)
    {
        super._afterTokenTransfer(from, to, amount);
    }

    function _mint(address to, uint256 amount)
        internal override(ERC20, ERC20Votes)
    {
        super._mint(to, amount);
    }

    function _burn(address account, uint256 amount)
        internal override(ERC20, ERC20Votes)
    {
        super._burn(account, amount);
    }
}
