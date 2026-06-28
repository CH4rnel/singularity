import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { getAddress } from "viem";
import { network } from "hardhat";

/**
 * WiredForge: the anti-cheat backbone.
 *  - B (entry): a run only starts with a Ticket signed by the trusted server.
 *  - C (achievement): the artifact is minted only by winning the on-chain duel,
 *    one move per transaction — completion is provable, not claimed.
 */
describe("WiredForge", async function () {
  const { viem } = await network.connect();
  const publicClient = await viem.getPublicClient();
  const [server, player, mallory] = await viem.getWalletClients();

  const TICKET_TYPES = {
    Ticket: [
      { name: "player", type: "address" },
      { name: "tier", type: "uint8" },
      { name: "seed", type: "bytes32" },
      { name: "nonce", type: "uint256" },
      { name: "deadline", type: "uint256" },
    ],
  } as const;

  const SEED = `0x${"ab".repeat(32)}` as `0x${string}`;

  async function deploy() {
    return viem.deployContract("WiredForge", [server.account.address]);
  }

  async function makeTicket(
    wf: { address: `0x${string}` },
    opts: {
      player: `0x${string}`;
      nonce: bigint;
      tier?: number;
      seed?: `0x${string}`;
      deadline?: bigint;
      signWith?: (typeof server);
    },
  ) {
    const ticket = {
      player: opts.player,
      tier: opts.tier ?? 0,
      seed: opts.seed ?? SEED,
      nonce: opts.nonce,
      deadline: opts.deadline ?? 4_000_000_000n,
    };
    const wallet = opts.signWith ?? server;
    const chainId = await publicClient.getChainId();
    const sig = await wallet.signTypedData({
      account: wallet.account,
      domain: { name: "WiredForge", version: "1", chainId, verifyingContract: wf.address },
      types: TICKET_TYPES,
      primaryType: "Ticket",
      message: ticket,
    });
    return { ticket, sig };
  }

  // Public mapping getter may decode as object or tuple depending on viem; read defensively.
  function activeOf(run: any): boolean {
    return Boolean(run.active ?? run[0]);
  }

  it("mints the artifact when the player wins the duel (always overload)", async function () {
    const wf = await deploy();
    const addr = player.account.address;
    const { ticket, sig } = await makeTicket(wf, { player: addr, nonce: 1n });

    await wf.write.startRun([ticket, sig], { account: player.account });

    for (let i = 0; i < 8; i++) {
      const run = await wf.read.runs([addr]);
      if (!activeOf(run)) break;
      await wf.write.act([2], { account: player.account }); // overload
    }

    assert.equal(await wf.read.balanceOf([addr]), 1n);
    assert.equal(await wf.read.ownerOf([1n]), getAddress(addr));
  });

  it("does NOT mint when the player loses (guards every turn, never damages ICE)", async function () {
    const wf = await deploy();
    const addr = player.account.address;
    const { ticket, sig } = await makeTicket(wf, { player: addr, nonce: 2n });

    await wf.write.startRun([ticket, sig], { account: player.account });

    for (let i = 0; i < 10; i++) {
      const run = await wf.read.runs([addr]);
      if (!activeOf(run)) break;
      await wf.write.act([1], { account: player.account }); // guard: 0 damage to ICE
    }

    assert.equal(await wf.read.balanceOf([addr]), 0n);
  });

  it("rejects a ticket not signed by the trusted server", async function () {
    const wf = await deploy();
    const addr = player.account.address;
    const { ticket, sig } = await makeTicket(wf, { player: addr, nonce: 3n, signWith: mallory });

    await assert.rejects(wf.write.startRun([ticket, sig], { account: player.account }));
    assert.equal(await wf.read.balanceOf([addr]), 0n);
  });

  it("rejects an expired ticket", async function () {
    const wf = await deploy();
    const addr = player.account.address;
    const { ticket, sig } = await makeTicket(wf, { player: addr, nonce: 4n, deadline: 1n });

    await assert.rejects(wf.write.startRun([ticket, sig], { account: player.account }));
  });

  it("rejects a reused ticket nonce", async function () {
    const wf = await deploy();
    const addr = player.account.address;
    const { ticket, sig } = await makeTicket(wf, { player: addr, nonce: 5n });

    await wf.write.startRun([ticket, sig], { account: player.account });
    for (let i = 0; i < 8; i++) {
      const run = await wf.read.runs([addr]);
      if (!activeOf(run)) break;
      await wf.write.act([2], { account: player.account });
    }
    assert.equal(await wf.read.balanceOf([addr]), 1n); // won

    // Same nonce again must be refused.
    await assert.rejects(wf.write.startRun([ticket, sig], { account: player.account }));
  });

  it("rejects acting without an active run (no faking moves)", async function () {
    const wf = await deploy();
    await assert.rejects(wf.write.act([2], { account: mallory.account }));
  });
});
