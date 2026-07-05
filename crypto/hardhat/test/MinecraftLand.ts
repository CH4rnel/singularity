import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { getAddress, keccak256, parseEther, stringToHex } from "viem";
import { network } from "hardhat";

describe("MinecraftLand", async function () {
  const { viem } = await network.connect();
  const [owner, alice, bob] = await viem.getWalletClients();
  const worldId = keccak256(stringToHex("cyberia-survival-v1"));
  const price = parseEther("10");
  const salt = keccak256(stringToHex("test secret salt"));

  async function deploy() {
    return viem.deployContract("MinecraftLand", [price, "ipfs://land/"]);
  }

  async function claim(
    land: Awaited<ReturnType<typeof deploy>>,
    wallet: typeof alice,
    chunkX: number,
    chunkZ: number,
    claimWorld = worldId,
  ) {
    const connected = await viem.getContractAt("MinecraftLand", land.address, {
      client: { wallet },
    });
    const commitment = await land.read.commitmentFor([
      wallet.account.address,
      claimWorld,
      chunkX,
      chunkZ,
      salt,
    ]);
    await connected.write.commit([commitment]);
    await connected.write.claim([claimWorld, chunkX, chunkZ, salt], { value: price });
  }

  it("claims a positive or negative chunk and exposes its owner", async function () {
    const land = await deploy();
    await claim(land, alice, -12, 34);

    assert.equal(await land.read.parcelAt([worldId, -12, 34]), 1n);
    assert.equal(await land.read.ownerOf([1n]), getAddress(alice.account.address));
    assert.deepEqual(await land.read.parcels([1n]), [worldId, -12, 34]);
    assert.equal(await land.read.tokenURI([1n]), "ipfs://land/1");
  });

  it("rejects duplicate claims and leaves other worlds available", async function () {
    const land = await deploy();
    await claim(land, owner, 2, 3);
    const duplicate = await land.read.commitmentFor([owner.account.address, worldId, 2, 3, salt]);
    await land.write.commit([duplicate]);
    await assert.rejects(land.write.claim([worldId, 2, 3, salt], { value: price }), /already claimed/);

    const otherWorld = keccak256(stringToHex("cyberia-nether-v1"));
    assert.equal(await land.read.parcelAt([otherWorld, 2, 3]), 0n);
  });

  it("requires the exact configured mint price", async function () {
    const land = await deploy();
    const commitment = await land.read.commitmentFor([owner.account.address, worldId, 0, 0, salt]);
    await land.write.commit([commitment]);
    await assert.rejects(land.write.claim([worldId, 0, 0, salt], { value: price - 1n }), /wrong price/);
    await assert.rejects(land.write.claim([worldId, 0, 0, salt], { value: price + 1n }), /wrong price/);
  });

  it("transfers Minecraft ownership with the NFT", async function () {
    const land = await deploy();
    await claim(land, alice, 8, -9);
    const asAlice = await viem.getContractAt("MinecraftLand", land.address, {
      client: { wallet: alice },
    });
    await asAlice.write.transferFrom([alice.account.address, bob.account.address, 1n]);
    assert.equal(await land.read.ownerOf([1n]), getAddress(bob.account.address));
  });

  it("does not let another wallet reveal a copied claim", async function () {
    const land = await deploy();
    const aliceCommitment = await land.read.commitmentFor([alice.account.address, worldId, 5, 6, salt]);
    const asAlice = await viem.getContractAt("MinecraftLand", land.address, { client: { wallet: alice } });
    const asBob = await viem.getContractAt("MinecraftLand", land.address, { client: { wallet: bob } });
    await asAlice.write.commit([aliceCommitment]);

    await assert.rejects(asBob.write.claim([worldId, 5, 6, salt], { value: price }), /missing commitment/);
    await asAlice.write.claim([worldId, 5, 6, salt], { value: price });
    assert.equal(await land.read.ownerOf([1n]), getAddress(alice.account.address));
  });

  it("restricts price and metadata administration to the contract owner", async function () {
    const land = await deploy();
    const asAlice = await viem.getContractAt("MinecraftLand", land.address, {
      client: { wallet: alice },
    });

    await assert.rejects(asAlice.write.setMintPrice([1n]));
    await assert.rejects(asAlice.write.setBaseURI(["https://evil.invalid/"]));

    await land.write.setMintPrice([1n], { account: owner.account });
    await land.write.setBaseURI(["https://land.cyberia.church/metadata/"], { account: owner.account });
    assert.equal(await land.read.mintPrice(), 1n);
  });
});
