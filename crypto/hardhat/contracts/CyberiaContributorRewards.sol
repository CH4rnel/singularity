// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import "@openzeppelin/contracts/utils/cryptography/MerkleProof.sol";
import "@openzeppelin/contracts/security/ReentrancyGuard.sol";

/// @title CyberiaContributorRewards — where open-source work is paid.
/// @notice GitHub shows the work, Cyberia settles the reward. Laravel's reward
///         engine (backend/laravel, config/contributions.php) reads merged pull
///         requests and accepted issues, refuses what its anti-sybil rules
///         refuse, splits one epoch's pool by points and writes the result as
///         a Merkle tree. The owner publishes that tree's root here, and from
///         then on anybody can claim a leaf with its proof — the contributor
///         from their own wallet, or anybody else on their behalf, since a
///         leaf pays only the address inside it.
///
///         One asset per deployment: native CYBER when `asset` is zero, an
///         ERC-20 otherwise. What the reward is paid in is a decision for the
///         project, and this keeps it a deployment parameter rather than code.
///
///         The invariant that makes a published root worth something: a root
///         is accepted only when the contract already holds enough to pay
///         every root published before it plus this one in full. So a leaf
///         that verifies is a leaf that pays — there is no race to claim
///         before the tank runs dry, and nothing the owner does afterwards
///         can take a published reward back except letting it expire, once,
///         after a claim window that is fixed at deployment.
/// @dev Leaves are OpenZeppelin StandardMerkleTree leaves:
///      keccak256(bytes.concat(keccak256(abi.encode(epoch, account, amount)))).
///      The epoch inside the leaf means a proof is valid for one root only.
contract CyberiaContributorRewards is ReentrancyGuard {
    using SafeERC20 for IERC20;

    struct Epoch {
        bytes32 root;
        uint256 total;
        uint256 claimed;
        uint64 publishedAt;
        bool expired;
    }

    /// @notice Zero for native CYBER, otherwise the ERC-20 paid out.
    address public immutable asset;

    /// @notice Seconds after publication before unclaimed rewards may be
    ///         reclaimed by the owner. Zero means never.
    uint256 public immutable claimWindow;

    address public owner;
    address public pendingOwner;

    /// @notice Sum of everything published and not yet claimed or expired.
    uint256 public outstanding;

    mapping(uint256 => Epoch) public epochs;
    mapping(uint256 => mapping(address => bool)) public claimed;

    event EpochPublished(uint256 indexed epoch, bytes32 root, uint256 total);
    event Claimed(uint256 indexed epoch, address indexed account, uint256 amount);
    event EpochExpired(uint256 indexed epoch, uint256 released);
    event SurplusWithdrawn(address indexed to, uint256 amount);
    event OwnershipTransferStarted(address indexed from, address indexed to);
    event OwnershipTransferred(address indexed from, address indexed to);

    modifier onlyOwner() {
        require(msg.sender == owner, "not owner");
        _;
    }

    constructor(address asset_, uint256 claimWindow_) {
        asset = asset_;
        claimWindow = claimWindow_;
        owner = msg.sender;
        emit OwnershipTransferred(address(0), msg.sender);
    }

    /// @notice Native CYBER funds the pool by plain transfer.
    receive() external payable {
        require(asset == address(0), "pool is an ERC-20");
    }

    function balance() public view returns (uint256) {
        return asset == address(0) ? address(this).balance : IERC20(asset).balanceOf(address(this));
    }

    /// @notice What the contract holds beyond every published promise.
    function surplus() public view returns (uint256) {
        uint256 held = balance();
        return held > outstanding ? held - outstanding : 0;
    }

    /// @notice Publish an epoch's root. Once, and only if it is already paid for.
    function publish(uint256 epoch, bytes32 root, uint256 total) external onlyOwner {
        require(root != bytes32(0), "empty root");
        require(total > 0, "empty epoch");
        require(epochs[epoch].root == bytes32(0), "epoch published");
        require(surplus() >= total, "underfunded");

        epochs[epoch] = Epoch({root: root, total: total, claimed: 0, publishedAt: uint64(block.timestamp), expired: false});
        outstanding += total;

        emit EpochPublished(epoch, root, total);
    }

    function verify(uint256 epoch, address account, uint256 amount, bytes32[] calldata proof) public view returns (bool) {
        bytes32 root = epochs[epoch].root;
        if (root == bytes32(0)) return false;
        bytes32 leaf = keccak256(bytes.concat(keccak256(abi.encode(epoch, account, amount))));
        return MerkleProof.verifyCalldata(proof, root, leaf);
    }

    /// @notice Pay one leaf to the address inside it. Callable by anybody.
    function claim(uint256 epoch, address account, uint256 amount, bytes32[] calldata proof) public nonReentrant {
        Epoch storage e = epochs[epoch];
        require(e.root != bytes32(0), "epoch not published");
        require(!e.expired, "epoch expired");
        require(!claimed[epoch][account], "already claimed");
        require(verify(epoch, account, amount, proof), "invalid proof");
        // Belt and braces: a root whose leaves add up to more than its total
        // could otherwise reach into another epoch's money.
        require(e.claimed + amount <= e.total, "over epoch total");

        claimed[epoch][account] = true;
        e.claimed += amount;
        outstanding -= amount;

        _pay(account, amount);
        emit Claimed(epoch, account, amount);
    }

    /// @notice After the claim window, return what nobody claimed to surplus.
    function expire(uint256 epoch) external onlyOwner {
        Epoch storage e = epochs[epoch];
        require(e.root != bytes32(0), "epoch not published");
        require(!e.expired, "epoch expired");
        require(claimWindow > 0 && block.timestamp >= e.publishedAt + claimWindow, "claim window open");

        uint256 released = e.total - e.claimed;
        e.expired = true;
        outstanding -= released;

        emit EpochExpired(epoch, released);
    }

    /// @notice Withdraw only what no published root has promised.
    function withdrawSurplus(address to, uint256 amount) external onlyOwner nonReentrant {
        require(amount <= surplus(), "exceeds surplus");
        _pay(to, amount);
        emit SurplusWithdrawn(to, amount);
    }

    /// @notice Two-step, so a typo cannot hand the publishing key to nobody.
    ///         The intended second owner is the DAO.
    function transferOwnership(address to) external onlyOwner {
        pendingOwner = to;
        emit OwnershipTransferStarted(owner, to);
    }

    function acceptOwnership() external {
        require(msg.sender == pendingOwner, "not pending owner");
        emit OwnershipTransferred(owner, msg.sender);
        owner = msg.sender;
        pendingOwner = address(0);
    }

    function _pay(address to, uint256 amount) private {
        if (asset == address(0)) {
            (bool ok, ) = to.call{value: amount}("");
            require(ok, "transfer failed");
        } else {
            IERC20(asset).safeTransfer(to, amount);
        }
    }
}
