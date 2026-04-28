import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { network } from "hardhat";

/**
 * Tests for the new emission scheme:
 *   - Ash: premint=1, mint() restricted to minter, setMinter is owner-only.
 *   - MasterChef: rewards split by allocPoints, mints exactly rewardPerBlock
 *     in total per block (after totalAllocPoint > 0).
 */
describe("Ash + MasterChef emission", async function () {
  const { viem } = await network.connect();
  const publicClient = await viem.getPublicClient();
  const [deployer, alice, bob] = await viem.getWalletClients();

  // 437 ASH / 86400 s (block ≈ 1 s)
  const REWARD_PER_BLOCK = (437n * 10n ** 18n) / 86_400n;

  it("Ash premint is exactly 1 ASH and goes to recipient", async function () {
    const ash = await viem.deployContract("Ash", [deployer.account.address]);
    const total = await ash.read.totalSupply();
    const bal = await ash.read.balanceOf([deployer.account.address]);
    assert.equal(total, 10n ** 18n);
    assert.equal(bal, 10n ** 18n);
  });

  it("only owner can setMinter; only minter can mint", async function () {
    const ash = await viem.deployContract("Ash", [deployer.account.address]);

    // alice cannot setMinter
    await assert.rejects(
      ash.write.setMinter([alice.account.address], { account: alice.account }),
    );

    // mint is rejected before minter is configured
    await assert.rejects(
      ash.write.mint([deployer.account.address, 1n]),
    );

    await ash.write.setMinter([alice.account.address]);
    assert.equal(
      (await ash.read.minter()).toLowerCase(),
      alice.account.address.toLowerCase(),
    );

    // bob cannot mint
    await assert.rejects(
      ash.write.mint([bob.account.address, 5n], { account: bob.account }),
    );

    // alice can mint
    await ash.write.mint([bob.account.address, 5n], { account: alice.account });
    assert.equal(await ash.read.balanceOf([bob.account.address]), 5n);
  });

  it("MasterChef mints rewardPerBlock × allocPoints into staked pools", async function () {
    const ash = await viem.deployContract("Ash", [deployer.account.address]);

    // Use ASH itself as a stand-in LP token for this single-pool test.
    const start = await publicClient.getBlockNumber();
    const chef = await viem.deployContract("MasterChef", [
      ash.address,
      REWARD_PER_BLOCK,
      start + 1n,
    ]);
    await ash.write.setMinter([chef.address]);

    // Add the only pool with allocPoint=100.
    await chef.write.add([100n, ash.address, false]);

    // Alice gets some ASH from deployer (the 1 premint) and stakes it.
    await ash.write.transfer([alice.account.address, 10n ** 18n]);
    await ash.write.approve([chef.address, 10n ** 18n], { account: alice.account });
    await chef.write.deposit([0n, 10n ** 18n], { account: alice.account });

    const blockA = await publicClient.getBlockNumber();

    // Mine ~10 blocks (in hardhat each tx = 1 block by default).
    for (let i = 0; i < 10; i++) {
      // a no-op tx to advance the block: send 0 ETH between accounts
      await deployer.sendTransaction({
        to: bob.account.address,
        value: 0n,
      });
    }

    const blockB = await publicClient.getBlockNumber();
    const elapsed = blockB - blockA;

    // pendingReward should be approx rewardPerBlock × elapsed (single pool).
    const pending = (await chef.read.pendingReward([0n, alice.account.address])) as bigint;
    const expected = REWARD_PER_BLOCK * elapsed;

    // Allow a tolerance of ±1 reward-per-block (rounding via accRewardPerShare/1e12).
    const diff = pending > expected ? pending - expected : expected - pending;
    assert.ok(
      diff <= REWARD_PER_BLOCK,
      `pending ${pending} vs expected ${expected} (Δ=${diff})`,
    );
  });

  it("two pools split emission proportionally to allocPoints", async function () {
    const ash = await viem.deployContract("Ash", [deployer.account.address]);

    // dummy ERC20 acting as 'LP' — reuse OZ ERC20 via Ash itself for simplicity.
    const lp = await viem.deployContract("Ash", [deployer.account.address]);

    const start = await publicClient.getBlockNumber();
    const chef = await viem.deployContract("MasterChef", [
      ash.address,
      REWARD_PER_BLOCK,
      start + 1n,
    ]);
    await ash.write.setMinter([chef.address]);

    // pool 0: lp,  alloc=80
    // pool 1: ash, alloc=20
    await chef.write.add([80n, lp.address, false]);
    await chef.write.add([20n, ash.address, false]);

    // Stake equal amounts in both pools from alice.
    await lp.write.transfer([alice.account.address, 10n ** 18n]);
    await ash.write.transfer([alice.account.address, 10n ** 18n]);
    await lp.write.approve([chef.address, 10n ** 18n], { account: alice.account });
    await ash.write.approve([chef.address, 10n ** 18n], { account: alice.account });

    await chef.write.deposit([0n, 10n ** 18n], { account: alice.account });
    await chef.write.deposit([1n, 10n ** 18n], { account: alice.account });

    // Advance 10 blocks.
    for (let i = 0; i < 10; i++) {
      await deployer.sendTransaction({ to: bob.account.address, value: 0n });
    }

    const pendLp = (await chef.read.pendingReward([0n, alice.account.address])) as bigint;
    const pendAsh = (await chef.read.pendingReward([1n, alice.account.address])) as bigint;

    // Ratio LP:ASH should be 80:20 = 4:1
    // Allow small slack due to per-block rounding & one extra block on lp deposit.
    const total = pendLp + pendAsh;
    assert.ok(total > 0n, "no rewards accrued");
    // pendLp / total ≈ 0.8
    const lpPctTimes100 = (pendLp * 100n) / total;
    assert.ok(
      lpPctTimes100 >= 75n && lpPctTimes100 <= 85n,
      `LP share ${lpPctTimes100}% not in [75,85]`,
    );
  });
});
