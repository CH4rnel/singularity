import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { getAddress } from "viem";
import { network } from "hardhat";

/**
 * PixelBattle: a 64×64 on-chain canvas, one byte (palette index 0–15) per
 * pixel, free to paint. Verifies paint() updates state, getCanvas() reflects it
 * row-major, totalPaints counts, and bounds/color are validated.
 */
describe("PixelBattle", async function () {
  const { viem } = await network.connect();
  const [deployer, alice] = await viem.getWalletClients();

  const WIDTH = 64;

  it("starts blank (all palette index 0)", async function () {
    const pb = await viem.deployContract("PixelBattle");
    assert.equal(await pb.read.totalPaints(), 0n);
    const canvas = (await pb.read.getCanvas()) as `0x${string}`;
    // 4096 bytes -> 8192 hex chars + "0x"
    assert.equal(canvas.length, 2 + 4096 * 2);
    assert.equal(BigInt(canvas), 0n); // all zero
  });

  it("paints a pixel and reflects it in pixel()/getCanvas()/totalPaints", async function () {
    const pb = await viem.deployContract("PixelBattle");
    await pb.write.paint([3, 2, 7]); // x=3, y=2, color=7
    assert.equal(await pb.read.pixel([3, 2]), 7);
    assert.equal(await pb.read.totalPaints(), 1n);

    const idx = 2 * WIDTH + 3; // row-major
    const canvas = (await pb.read.getCanvas()) as `0x${string}`;
    const byte = canvas.slice(2 + idx * 2, 2 + idx * 2 + 2);
    assert.equal(byte, "07");
  });

  it("overwrites a pixel and keeps neighbours intact (same word)", async function () {
    const pb = await viem.deployContract("PixelBattle");
    await pb.write.paint([0, 0, 5]);
    await pb.write.paint([1, 0, 9]); // neighbour, same 32-byte word
    await pb.write.paint([0, 0, 12]); // overwrite the first
    assert.equal(await pb.read.pixel([0, 0]), 12);
    assert.equal(await pb.read.pixel([1, 0]), 9);
    assert.equal(await pb.read.totalPaints(), 3n);
  });

  it("emits Painted with the painter", async function () {
    const pb = await viem.deployContract("PixelBattle");
    await viem.assertions.emitWithArgs(
      pb.write.paint([10, 11, 4]),
      pb,
      "Painted",
      [10, 11, 4, getAddress(deployer.account.address)],
    );
  });

  it("anyone can paint (no owner / cooldown)", async function () {
    const pb = await viem.deployContract("PixelBattle");
    const asAlice = await viem.getContractAt("PixelBattle", pb.address, {
      client: { wallet: alice },
    });
    await asAlice.write.paint([63, 63, 15]);
    assert.equal(await pb.read.pixel([63, 63]), 15);
  });

  it("reverts out-of-bounds and bad color", async function () {
    const pb = await viem.deployContract("PixelBattle");
    await assert.rejects(pb.write.paint([64, 0, 1]), /out of bounds/);
    await assert.rejects(pb.write.paint([0, 64, 1]), /out of bounds/);
    await assert.rejects(pb.write.paint([0, 0, 16]), /bad color/);
  });
});
