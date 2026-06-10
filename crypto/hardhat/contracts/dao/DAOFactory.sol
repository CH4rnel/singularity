// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {IVotes} from "@openzeppelin/contracts/governance/utils/IVotes.sol";
import {TimelockController} from "@openzeppelin/contracts/governance/TimelockController.sol";
import {StakingVault} from "./StakingVault.sol";
import {DAOGovernor} from "./DAOGovernor.sol";

/// @notice Deploys a full DAO (StakingVault + TimelockController + DAOGovernor)
/// for any ERC20 token. Anyone can create a DAO for any token.
contract DAOFactory {
    struct DAOInfo {
        address vault;
        address timelock;
        address governor;
    }

    struct DAOParams {
        IERC20  token;
        string  name;
        uint256 votingDelay;       // blocks before voting opens
        uint256 votingPeriod;      // blocks the vote stays open
        uint256 proposalThreshold; // min voting power to create a proposal
        uint256 quorumNumerator;   // required quorum as % of staked supply (1-100)
        uint256 timelockDelay;     // seconds between passing and execution
    }

    /// @notice All DAOs created for a given token address.
    mapping(address => DAOInfo[]) public tokenDAOs;

    event DAOCreated(
        address indexed token,
        address vault,
        address timelock,
        address governor,
        address indexed creator
    );

    function createDAO(DAOParams calldata p)
        external
        returns (address vault, address timelock, address governor)
    {
        // 1. Staking vault — wraps token into ERC20Votes
        StakingVault vault_ = new StakingVault(
            p.token,
            string(abi.encodePacked("Staked ", p.name)),
            string(abi.encodePacked("s", p.name))
        );

        // 2. Timelock — factory is temporary admin, renounced below
        address[] memory proposers = new address[](0);
        address[] memory executors = new address[](1);
        executors[0] = address(0); // anyone can execute passed proposals
        TimelockController timelock_ = new TimelockController(
            p.timelockDelay, proposers, executors, address(this)
        );

        // 3. Governor
        DAOGovernor governor_ = new DAOGovernor(
            p.name,
            IVotes(address(vault_)),
            timelock_,
            p.votingDelay,
            p.votingPeriod,
            p.proposalThreshold,
            p.quorumNumerator
        );

        // Grant governor PROPOSER + CANCELLER roles, then drop factory admin
        timelock_.grantRole(timelock_.PROPOSER_ROLE(),  address(governor_));
        timelock_.grantRole(timelock_.CANCELLER_ROLE(), address(governor_));
        timelock_.renounceRole(timelock_.TIMELOCK_ADMIN_ROLE(), address(this));

        vault    = address(vault_);
        timelock = address(timelock_);
        governor = address(governor_);

        tokenDAOs[address(p.token)].push(DAOInfo(vault, timelock, governor));
        emit DAOCreated(address(p.token), vault, timelock, governor, msg.sender);
    }

    /// @notice Returns all DAOs created for a given token.
    function getDAOs(address token) external view returns (DAOInfo[] memory) {
        return tokenDAOs[token];
    }

    /// @notice Returns the number of DAOs created for a given token.
    function daoCount(address token) external view returns (uint256) {
        return tokenDAOs[token].length;
    }
}
