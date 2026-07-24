// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";

/// @title EmissionChannel — a non-tradeable placeholder staked into the Cyberia
///        MasterChef to carve out a satellite chain's share of ASH emission.
/// @notice One EmissionChannel pool per satellite chain sits on the Cyberia
///         chef with allocPoint = (that chain's farm count × 100). The relayer
///         is the sole holder and staker, so 100% of that pool's ASH emission
///         accrues to the relayer, which the farm-funding keeper harvests and
///         bridges to fund the satellite's FundedFarm. The fixed supply is
///         minted once to the deployer and never distributed, so no one else
///         can dilute the pool. Hidden from the /farm and /staking UIs by
///         address.
contract EmissionChannel is ERC20 {
    constructor(string memory chainLabel)
        ERC20(string.concat("ASH Emission Channel: ", chainLabel), "ACHANNEL")
    {
        // A small fixed supply is all that's needed — the pool's reward share
        // depends only on allocPoint, not on the staked amount, once lpSupply>0.
        _mint(msg.sender, 1e18);
    }
}
