// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

import {ERC721} from "@openzeppelin/contracts/token/ERC721/ERC721.sol";
import {ERC721URIStorage} from "@openzeppelin/contracts/token/ERC721/extensions/ERC721URIStorage.sol";

/// @title Shared Cyberia NFT collection.
/// @notice One ERC-721 anyone can mint into; the tokenURI is provided at mint
///         time (typically an `ipfs://CID` pointing at standard ERC-721 JSON
///         metadata).
contract CyberiaNFT is ERC721URIStorage {
    uint256 public nextId;

    event Minted(uint256 indexed tokenId, address indexed creator, string uri);

    constructor() ERC721("Cyberia NFT", "CYBNFT") {}

    function mint(string calldata uri) external returns (uint256 tokenId) {
        require(bytes(uri).length > 0, "CyberiaNFT: empty uri");
        tokenId = ++nextId;
        _safeMint(msg.sender, tokenId);
        _setTokenURI(tokenId, uri);
        emit Minted(tokenId, msg.sender, uri);
    }
}
