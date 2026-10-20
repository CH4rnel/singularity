import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { concat, encodeAbiParameters, keccak256, parseEther, zeroAddress, type Hex } from "viem";
import { network } from "hardhat";

/**
 * CyberiaContributorRewards: the claim half of the open-source reward engine.
 *
 * The tree is built here exactly as OpenZeppelin's StandardMerkleTree builds
 * it (sorted leaves, a complete binary tree in an array, sorted pairs), which
 * is also what backend/laravel's App\Services\Contributions\MerkleTree does.
 * The pinned root below appears in both test suites: if either side drifts,
 * one of them fails before a published root does.
 */
const leaf = (epoch: bigint, account: Hex, amount: bigint): Hex =>
  keccak256(keccak256(encodeAbiParameters([{ type: "uint256" }, { type: "address" }, { type: "uint256" }], [epoch, account, amount])));

const hashPair = (a: Hex, b: Hex): Hex => (a.toLowerCase() < b.toLowerCase() ? keccak256(concat([a, b])) : keccak256(concat([b, a])));

function tree(leaves: Hex[]) {
  const sorted = [...leaves].sort((a, b) => (a.toLowerCase() < b.toLowerCase() ? -1 : 1));
  const t: Hex[] = new Array(2 * sorted.length - 1);
  sorted.forEach((l, i) => (t[t.length - 1 - i] = l));
  for (let i = t.length - 1 - sorted.length; i >= 0; i--) t[i] = hashPair(t[2 * i + 1], t[2 * i + 2]);
  const proof = (l: Hex) => {
    let i = t.findIndex((x) => x === l);
    const p: Hex[] = [];
    while (i > 0) {
      p.push(t[i % 2 === 1 ? i + 1 : i - 1]);
      i = Math.floor((i - 1) / 2);
    }
    return p;
  };
  return { root: t[0], proof };
}

describe("CyberiaContributorRewards", async function () {
  const { viem } = await network.connect();
  const publicClient = await viem.getPublicClient();
  const testClient = await viem.getTestClient();
  const [owner, alice, bob, carol] = await viem.getWalletClients();

  async function deploy(asset: Hex = zeroAddress, window = 0n) {
    const rewards = await viem.deployContract("CyberiaContributorRewards", [asset, window]);
    const as = (client: typeof alice) =>
      viem.getContractAt("CyberiaContributorRewards", rewards.address, { client: { wallet: client } });
    return { rewards, as };
  }

  function epochOne() {
    const shares: [Hex, bigint][] = [
      [alice.account.address, parseEther("60")],
      [bob.account.address, parseEther("30")],
      [carol.account.address, parseEther("10")],
    ];
    const leaves = shares.map(([a, v]) => leaf(1n, a, v));
    return { shares, leaves, ...tree(leaves), total: parseEther("100") };
  }

  it("builds the same root as the Laravel tree for the pinned vector", function () {
    const vector: [Hex, bigint][] = [
      ["0x1111111111111111111111111111111111111111", 600n],
      ["0x2222222222222222222222222222222222222222", 300n],
      ["0x3333333333333333333333333333333333333333", 100n],
    ];
    const { root } = tree(vector.map(([a, v]) => leaf(7n, a, v)));
    assert.equal(root, "0x083bfce98a998d787c2c1d823f255756eef2807cf8dac1e9624dd37198e41a5d");
  });

  it("refuses a root it cannot pay in full", async function () {
    const { rewards } = await deploy();
    const e = epochOne();
    await assert.rejects(rewards.write.publish([1n, e.root, e.total]), /underfunded/);

    await owner.sendTransaction({ to: rewards.address, value: parseEther("99") });
    await assert.rejects(rewards.write.publish([1n, e.root, e.total]), /underfunded/);

    await owner.sendTransaction({ to: rewards.address, value: parseEther("1") });
    await rewards.write.publish([1n, e.root, e.total]);
    assert.equal(await rewards.read.outstanding(), e.total);
    assert.equal(await rewards.read.surplus(), 0n);

    // A second root needs its own money: the first one's is spoken for.
    await assert.rejects(rewards.write.publish([2n, e.root, 1n]), /underfunded/);
    await assert.rejects(rewards.write.publish([1n, e.root, 1n]), /epoch published/);
  });

  it("pays each leaf once, to the address inside it, whoever sends the claim", async function () {
    const { rewards, as } = await deploy();
    const e = epochOne();
    await owner.sendTransaction({ to: rewards.address, value: e.total });
    await rewards.write.publish([1n, e.root, e.total]);

    const [bobAddress, bobAmount] = e.shares[1];
    const before = await publicClient.getBalance({ address: bobAddress });
    // Carol pays the gas; Bob gets the reward.
    await (await as(carol)).write.claim([1n, bobAddress, bobAmount, e.proof(e.leaves[1])]);
    assert.equal((await publicClient.getBalance({ address: bobAddress })) - before, bobAmount);
    assert.equal(await rewards.read.claimed([1n, bobAddress]), true);

    await assert.rejects((await as(bob)).write.claim([1n, bobAddress, bobAmount, e.proof(e.leaves[1])]), /already claimed/);
  });

  it("refuses a wrong amount, a wrong account and another epoch's proof", async function () {
    const { rewards, as } = await deploy();
    const e = epochOne();
    await owner.sendTransaction({ to: rewards.address, value: parseEther("200") });
    await rewards.write.publish([1n, e.root, e.total]);
    await rewards.write.publish([2n, e.root, e.total]);

    const [a, v] = e.shares[0];
    const p = e.proof(e.leaves[0]);
    const client = await as(alice);
    await assert.rejects(client.write.claim([1n, a, v + 1n, p]), /invalid proof/);
    await assert.rejects(client.write.claim([1n, bob.account.address, v, p]), /invalid proof/);
    // Same root, other epoch number: the leaf carries its epoch, so it fails.
    await assert.rejects(client.write.claim([2n, a, v, p]), /invalid proof/);
    await client.write.claim([1n, a, v, p]);
  });

  it("lets the owner take back only what no root has promised", async function () {
    const { rewards } = await deploy();
    const e = epochOne();
    await owner.sendTransaction({ to: rewards.address, value: parseEther("150") });
    await rewards.write.publish([1n, e.root, e.total]);

    await assert.rejects(rewards.write.withdrawSurplus([owner.account.address, parseEther("51")]), /exceeds surplus/);
    await rewards.write.withdrawSurplus([owner.account.address, parseEther("50")]);
    assert.equal(await rewards.read.balance(), e.total);
  });

  it("expires an epoch only after the window fixed at deployment", async function () {
    const window = 3600n;
    const { rewards, as } = await deploy(zeroAddress, window);
    const e = epochOne();
    await owner.sendTransaction({ to: rewards.address, value: e.total });
    await rewards.write.publish([1n, e.root, e.total]);
    await (await as(alice)).write.claim([1n, e.shares[0][0], e.shares[0][1], e.proof(e.leaves[0])]);

    await assert.rejects(rewards.write.expire([1n]), /claim window open/);
    await testClient.increaseTime({ seconds: Number(window) });
    await testClient.mine({ blocks: 1 });
    await rewards.write.expire([1n]);

    assert.equal(await rewards.read.outstanding(), 0n);
    assert.equal(await rewards.read.surplus(), parseEther("40"));
    await assert.rejects(
      (await as(bob)).write.claim([1n, e.shares[1][0], e.shares[1][1], e.proof(e.leaves[1])]),
      /epoch expired/,
    );

    const { rewards: forever } = await deploy(zeroAddress, 0n);
    await owner.sendTransaction({ to: forever.address, value: e.total });
    await forever.write.publish([1n, e.root, e.total]);
    await assert.rejects(forever.write.expire([1n]), /claim window open/);
  });

  it("pays an ERC-20 pool and refuses native funding for it", async function () {
    const token = await viem.deployContract("MockZoneToken", ["Reward", "RWD", parseEther("1000"), owner.account.address]);
    const { rewards, as } = await deploy(token.address);
    await assert.rejects(owner.sendTransaction({ to: rewards.address, value: 1n }), /pool is an ERC-20/);

    const e = epochOne();
    await token.write.transfer([rewards.address, e.total]);
    await rewards.write.publish([1n, e.root, e.total]);
    await (await as(carol)).write.claim([1n, e.shares[2][0], e.shares[2][1], e.proof(e.leaves[2])]);
    assert.equal(await token.read.balanceOf([carol.account.address]), e.shares[2][1]);
  });

  it("is published only by the owner, and hands ownership over in two steps", async function () {
    const { rewards, as } = await deploy();
    const e = epochOne();
    await owner.sendTransaction({ to: rewards.address, value: e.total });
    await assert.rejects((await as(alice)).write.publish([1n, e.root, e.total]), /not owner/);

    await rewards.write.transferOwnership([alice.account.address]);
    assert.equal((await rewards.read.owner()).toLowerCase(), owner.account.address.toLowerCase());
    await assert.rejects((await as(bob)).write.acceptOwnership(), /not pending owner/);
    await (await as(alice)).write.acceptOwnership();
    await (await as(alice)).write.publish([1n, e.root, e.total]);
  });
});
