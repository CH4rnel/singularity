// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC721} from "@openzeppelin/contracts/token/ERC721/ERC721.sol";
import {Ownable} from "@openzeppelin/contracts/access/Ownable.sol";
import {Strings} from "@openzeppelin/contracts/utils/Strings.sol";

/// @title MinecraftLand — transferable ownership of Minecraft chunks.
/// @notice Each ERC-721 represents one (worldId, chunkX, chunkZ) tuple. A
///         server plugin can read parcelAt() and ownerOf() without trusting an
///         off-chain ownership database.
contract MinecraftLand is ERC721, Ownable {
    struct Parcel {
        bytes32 worldId;
        int32 chunkX;
        int32 chunkZ;
    }

    uint256 public nextId;
    uint256 public mintPrice;
    string private _baseTokenURI;

    mapping(bytes32 => uint256) private _parcelToken;
    mapping(uint256 => Parcel) public parcels;
    mapping(address => mapping(bytes32 => uint256)) public commitments;

    event ParcelClaimed(
        uint256 indexed tokenId,
        bytes32 indexed worldId,
        int32 chunkX,
        int32 chunkZ,
        address indexed owner
    );
    event ClaimCommitted(address indexed claimant, bytes32 indexed commitment, uint256 blockNumber);
    event MintPriceChanged(uint256 oldPrice, uint256 newPrice);
    event BaseURIChanged(string oldURI, string newURI);

    constructor(uint256 mintPrice_, string memory baseTokenURI_) ERC721("Cyberia Minecraft Land", "CMLAND") {
        mintPrice = mintPrice_;
        _baseTokenURI = baseTokenURI_;
    }

    /// @notice Stable identifier helper. The Paper plugin uses the same UTF-8
    ///         keccak256 calculation for its configured world-key.
    function worldId(string calldata worldKey) external pure returns (bytes32) {
        return keccak256(bytes(worldKey));
    }

    /// @return tokenId The parcel token, or zero when the chunk is unclaimed.
    function parcelAt(bytes32 worldId_, int32 chunkX, int32 chunkZ) public view returns (uint256 tokenId) {
        return _parcelToken[_parcelKey(worldId_, chunkX, chunkZ)];
    }

    /// @notice Commit before revealing coordinates so another account cannot
    ///         copy a pending claim transaction and front-run it.
    function commit(bytes32 commitment) external {
        require(commitment != bytes32(0), "MinecraftLand: zero commitment");
        commitments[msg.sender][commitment] = block.number;
        emit ClaimCommitted(msg.sender, commitment, block.number);
    }

    function commitmentFor(
        address claimant,
        bytes32 worldId_,
        int32 chunkX,
        int32 chunkZ,
        bytes32 salt
    ) public view returns (bytes32) {
        return keccak256(abi.encode(address(this), block.chainid, claimant, worldId_, chunkX, chunkZ, salt));
    }

    function claim(
        bytes32 worldId_,
        int32 chunkX,
        int32 chunkZ,
        bytes32 salt
    ) external payable returns (uint256 tokenId) {
        require(msg.value == mintPrice, "MinecraftLand: wrong price");

        bytes32 commitment = commitmentFor(msg.sender, worldId_, chunkX, chunkZ, salt);
        uint256 commitBlock = commitments[msg.sender][commitment];
        require(commitBlock != 0, "MinecraftLand: missing commitment");
        require(block.number > commitBlock, "MinecraftLand: reveal too soon");
        require(block.number <= commitBlock + 256, "MinecraftLand: commitment expired");

        bytes32 key = _parcelKey(worldId_, chunkX, chunkZ);
        require(_parcelToken[key] == 0, "MinecraftLand: already claimed");

        delete commitments[msg.sender][commitment];
        tokenId = ++nextId;
        _parcelToken[key] = tokenId;
        parcels[tokenId] = Parcel({worldId: worldId_, chunkX: chunkX, chunkZ: chunkZ});
        _safeMint(msg.sender, tokenId);

        emit ParcelClaimed(tokenId, worldId_, chunkX, chunkZ, msg.sender);
    }

    function setMintPrice(uint256 newPrice) external onlyOwner {
        emit MintPriceChanged(mintPrice, newPrice);
        mintPrice = newPrice;
    }

    function setBaseURI(string calldata newBaseURI) external onlyOwner {
        emit BaseURIChanged(_baseTokenURI, newBaseURI);
        _baseTokenURI = newBaseURI;
    }

    function withdraw(address payable recipient) external onlyOwner {
        require(recipient != address(0), "MinecraftLand: zero recipient");
        (bool sent, ) = recipient.call{value: address(this).balance}("");
        require(sent, "MinecraftLand: withdraw failed");
    }

    function tokenURI(uint256 tokenId) public view override returns (string memory) {
        _requireMinted(tokenId);
        if (bytes(_baseTokenURI).length == 0) {
            return "";
        }
        return string.concat(_baseTokenURI, Strings.toString(tokenId));
    }

    function _parcelKey(bytes32 worldId_, int32 chunkX, int32 chunkZ) private pure returns (bytes32) {
        return keccak256(abi.encode(worldId_, chunkX, chunkZ));
    }
}
