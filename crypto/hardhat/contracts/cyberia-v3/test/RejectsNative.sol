// SPDX-License-Identifier: GPL-2.0-or-later
pragma solidity ^0.8.19;

/// @dev A recipient that cannot accept a plain native transfer. Exists so the splitter's behaviour
/// when one of its recipients is a contract like this is tested rather than assumed.
contract RejectsNative {
    error No();

    receive() external payable {
        revert No();
    }
}
