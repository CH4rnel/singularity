import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { keccak256, parseEther, toHex } from "viem";
import { network } from "hardhat";

// The provider agent's restatement of the accrual arithmetic.
import { owed, runway } from "../../../services/compute-agent/src/lease.mjs";

/**
 * CyberiaCompute + ServiceTreasury: a container paid for by the second.
 * Covers the money paths — accrual that is exact to the second and capped by
 * the prepaid balance, the protocol fee split, pause/resume, closing from
 * either side with a pulled refund, provider bonds (unbond delay, slashing),
 * and a service treasury that refills its own leases within the DAO's policy
 * while its guardian can stop paying but never move money out.
 */
describe("CyberiaCompute", async function () {
  const { viem } = await network.connect();
  const publicClient = await viem.getPublicClient();
  const testClient = await viem.getTestClient();
  const [dao, provider, payer, watcher, stranger, feeSink] = await viem.getWalletClients();

  const HOUR = 3600n;
  const DAY = 24n * HOUR;
  const RATE = 1_000_000_000n; // 1 gwei-CYBER per second ≈ 0.0864 CYBER a day
  const FEE_BPS = 200; // 2%
  const MIN_BOND = parseEther("10");
  const SPEC = keccak256(toHex("cyberia-dns@sha256:abc"));

  async function now(): Promise<bigint> {
    return (await publicClient.getBlock()).timestamp;
  }

  /** Make the next transaction land exactly `secs` after the last block. */
  async function at(ts: bigint) {
    await testClient.setNextBlockTimestamp({ timestamp: ts });
  }

  async function deploy() {
    const compute = await viem.deployContract("CyberiaCompute", [
      dao.account.address,
      feeSink.account.address,
      FEE_BPS,
      MIN_BOND,
    ]);
    const as = (client: typeof dao) =>
      viem.getContractAt("CyberiaCompute", compute.address, { client: { wallet: client } });
    await (await as(provider)).write.registerProvider(["https://agent.example"], { value: MIN_BOND });
    await compute.write.setProviderApproved([provider.account.address, true]);
    return { compute, as };
  }

  async function openLease(as: Awaited<ReturnType<typeof deploy>>["as"], deposit = RATE * DAY) {
    const c = await as(payer);
    await c.write.openLease([provider.account.address, RATE, watcher.account.address, SPEC, "ipfs://spec"], {
      value: deposit,
    });
    return 1n;
  }

  it("refuses a provider without a bond or approval, and a lease without runway", async function () {
    const compute = await viem.deployContract("CyberiaCompute", [dao.account.address, feeSink.account.address, FEE_BPS, MIN_BOND]);
    const asProvider = await viem.getContractAt("CyberiaCompute", compute.address, { client: { wallet: provider } });
    const asPayer = await viem.getContractAt("CyberiaCompute", compute.address, { client: { wallet: payer } });

    await assert.rejects(asProvider.write.registerProvider(["x"], { value: MIN_BOND - 1n }), /bond too small/);
    await asProvider.write.registerProvider(["x"], { value: MIN_BOND });
    await assert.rejects(
      asPayer.write.openLease([provider.account.address, RATE, watcher.account.address, SPEC, ""], { value: RATE * HOUR }),
      /provider unavailable/,
    );
    await compute.write.setProviderApproved([provider.account.address, true]);
    await assert.rejects(
      asPayer.write.openLease([provider.account.address, RATE, watcher.account.address, SPEC, ""], { value: RATE * HOUR - 1n }),
      /prepay too small/,
    );
    await asPayer.write.openLease([provider.account.address, RATE, watcher.account.address, SPEC, ""], { value: RATE * HOUR });
    assert.equal(await compute.read.leaseCount(), 1n);
  });

  it("accrues exactly rate × seconds and splits the protocol fee on settlement", async function () {
    const { compute, as } = await deploy();
    await openLease(as);
    const opened = (await compute.read.lease([1n])).settledAt;

    await at(opened + 1000n);
    await compute.write.settle([1n]);

    const owed = RATE * 1000n;
    const fee = (owed * BigInt(FEE_BPS)) / 10_000n;
    assert.equal(await compute.read.claimable([provider.account.address]), owed - fee);
    assert.equal(await compute.read.claimable([feeSink.account.address]), fee);
    assert.equal(await compute.read.remaining([1n]), RATE * DAY - owed);

    const before = await publicClient.getBalance({ address: provider.account.address });
    const hash = await (await as(provider)).write.withdraw();
    const receipt = await publicClient.waitForTransactionReceipt({ hash });
    const after = await publicClient.getBalance({ address: provider.account.address });
    assert.equal(after - before + receipt.gasUsed * receipt.effectiveGasPrice, owed - fee);
  });

  it("never accrues past the prepaid balance, and a top-up does not pay for the dry spell", async function () {
    const { compute, as } = await deploy();
    await openLease(as, RATE * HOUR);
    const opened = (await compute.read.lease([1n])).settledAt;

    await testClient.setNextBlockTimestamp({ timestamp: opened + 3n * HOUR });
    await testClient.mine({ blocks: 1 });
    assert.equal(await compute.read.accrued([1n]), RATE * HOUR);
    assert.equal(await compute.read.runway([1n]), 0n);

    await at(opened + 3n * HOUR + 10n);
    await (await as(stranger)).write.topUp([1n], { value: RATE * HOUR });
    // The whole first hour settled; nothing for the two dry hours; a fresh hour of runway.
    assert.equal(await compute.read.accrued([1n]), 0n);
    assert.equal(await compute.read.runway([1n]), HOUR);
  });

  it("stops accrual while paused, and only the payer or its manager can pause", async function () {
    const { compute, as } = await deploy();
    await openLease(as);
    const opened = (await compute.read.lease([1n])).settledAt;

    await assert.rejects((await as(stranger)).write.pause([1n]), /not payer/);
    await at(opened + 100n);
    await (await as(watcher)).write.pause([1n]);

    await testClient.setNextBlockTimestamp({ timestamp: opened + 5000n });
    await testClient.mine({ blocks: 1 });
    assert.equal(await compute.read.accrued([1n]), 0n); // settled at pause
    assert.equal(await compute.read.remaining([1n]), RATE * DAY - RATE * 100n);

    await at(opened + 6000n);
    await (await as(watcher)).write.resume([1n]);
    await testClient.setNextBlockTimestamp({ timestamp: opened + 6050n });
    await testClient.mine({ blocks: 1 });
    assert.equal(await compute.read.accrued([1n]), RATE * 50n);
  });

  it("closes from either side: the provider keeps what accrued, the payer pulls the rest", async function () {
    const { compute, as } = await deploy();
    await openLease(as);
    const opened = (await compute.read.lease([1n])).settledAt;

    await assert.rejects((await as(stranger)).write.close([1n]), /not a party/);
    await at(opened + 600n);
    await (await as(provider)).write.close([1n]);

    const owed = RATE * 600n;
    const fee = (owed * BigInt(FEE_BPS)) / 10_000n;
    assert.equal(await compute.read.claimable([provider.account.address]), owed - fee);
    assert.equal(await compute.read.claimable([payer.account.address]), RATE * DAY - owed);
    assert.equal((await compute.read.providers([provider.account.address]))[2], 0); // activeLeases
    await assert.rejects((await as(payer)).write.topUp([1n], { value: 1n }), /closed/);
  });

  it("holds a bond through the unbond delay and while leases are open; only the DAO slashes", async function () {
    const { compute, as } = await deploy();
    await openLease(as);
    const p = await as(provider);

    await p.write.requestUnbond();
    await assert.rejects(
      (await as(payer)).write.openLease([provider.account.address, RATE, watcher.account.address, SPEC, ""], { value: RATE * HOUR }),
      /provider unavailable/,
    );
    const unbondAt = (await compute.read.providers([provider.account.address]))[1];
    await testClient.setNextBlockTimestamp({ timestamp: unbondAt });
    await testClient.mine({ blocks: 1 });
    await assert.rejects(p.write.withdrawBond(), /leases open/);

    await assert.rejects((await as(stranger)).write.slash([provider.account.address, 1n, stranger.account.address, "x"]), /not owner/);
    await compute.write.slash([provider.account.address, parseEther("4"), payer.account.address, "served the wrong image"]);

    await (await as(payer)).write.close([1n]);
    const before = await publicClient.getBalance({ address: provider.account.address });
    const hash = await p.write.withdrawBond();
    const receipt = await publicClient.waitForTransactionReceipt({ hash });
    const after = await publicClient.getBalance({ address: provider.account.address });
    assert.equal(after - before + receipt.gasUsed * receipt.effectiveGasPrice, MIN_BOND - parseEther("4"));
  });

  it("agrees, second for second, with the agent's copy of the arithmetic", async function () {
    const { compute, as } = await deploy();
    await openLease(as, RATE * 2n * HOUR);
    const opened = (await compute.read.lease([1n])).settledAt;
    const check = async () => {
      const ts = await now();
      const l = await compute.read.lease([1n]);
      assert.equal(owed(l, ts), await compute.read.accrued([1n]));
      assert.equal(runway(l, ts), await compute.read.runway([1n]));
    };
    for (const offset of [17n, HOUR, 2n * HOUR + 5n]) {
      await testClient.setNextBlockTimestamp({ timestamp: opened + offset });
      await testClient.mine({ blocks: 1 });
      await check();
    }
    await (await as(stranger)).write.topUp([1n], { value: RATE * HOUR });
    await (await as(watcher)).write.pause([1n]);
    await testClient.setNextBlockTimestamp({ timestamp: (await now()) + 999n });
    await testClient.mine({ blocks: 1 });
    await check();
  });

  describe("ServiceTreasury", function () {
    async function deployTreasury(runwayTarget = 7n * DAY, maxPerDay = RATE * 3n * DAY, reserve = 0n) {
      const { compute, as } = await deploy();
      const treasury = await viem.deployContract("ServiceTreasury", [
        compute.address,
        "cyberia-dns",
        dao.account.address,
        watcher.account.address,
        runwayTarget,
        maxPerDay,
        reserve,
      ]);
      const asT = (client: typeof dao) =>
        viem.getContractAt("ServiceTreasury", treasury.address, { client: { wallet: client } });
      // The service's fees arrive as plain transfers.
      await stranger.sendTransaction({ to: treasury.address, value: parseEther("1") });
      return { compute, as, treasury, asT };
    }

    it("lets only the DAO hire a provider; the guardian becomes the lease's manager", async function () {
      const { compute, treasury, asT } = await deployTreasury();
      await assert.rejects(
        (await asT(watcher)).write.openLease([provider.account.address, RATE, SPEC, "ipfs://spec", RATE * DAY]),
        /not owner/,
      );
      await treasury.write.openLease([provider.account.address, RATE, SPEC, "ipfs://spec", RATE * DAY]);
      const l = await compute.read.lease([1n]);
      assert.equal(l.payer.toLowerCase(), treasury.address.toLowerCase());
      assert.equal(l.manager.toLowerCase(), watcher.account.address.toLowerCase());
    });

    it("refills to the runway target for anybody, within the daily limit and above the reserve", async function () {
      const reserve = parseEther("1") - RATE * DAY - RATE * 2n * DAY; // leaves room for two days of refill
      const { compute, treasury, asT } = await deployTreasury(7n * DAY, RATE * 3n * DAY, reserve);
      await treasury.write.openLease([provider.account.address, RATE, SPEC, "", RATE * DAY]);

      await (await asT(stranger)).write.refill();
      // Wanted six more days; the reserve allowed two.
      assert.equal(await compute.read.runway([1n]), 3n * DAY - 1n);

      // Lowering the limit below what today already moved stops refills for the day…
      await treasury.write.setPolicy([7n * DAY, RATE * HOUR, 0n]);
      await (await asT(stranger)).write.refill();
      assert.ok((await compute.read.runway([1n])) < 3n * DAY);

      // …and the next UTC day allows exactly one more hour.
      const nextDay = ((await now()) / DAY + 1n) * DAY;
      await at(nextDay);
      await (await asT(stranger)).write.refill();
      const l = await compute.read.lease([1n]);
      assert.equal(l.settledAt, nextDay);
      assert.equal(await treasury.read.refilledOnDay([nextDay / DAY]), RATE * HOUR);
    });

    it("lets the guardian stop paying and bring the money home, but not take it", async function () {
      const { compute, treasury, asT } = await deployTreasury();
      await treasury.write.openLease([provider.account.address, RATE, SPEC, "", RATE * DAY]);
      const g = await asT(watcher);

      await assert.rejects(g.write.withdraw([watcher.account.address, 1n]), /not owner/);
      await g.write.pauseLease([1n]);
      await g.write.updateSpec([1n, keccak256(toHex("v2")), "ipfs://v2"]);
      await g.write.closeLease([1n]);

      assert.equal((await compute.read.lease([1n])).closed, true);
      assert.equal(await compute.read.claimable([treasury.address]), 0n);
      assert.deepEqual(await treasury.read.leases(), []);
      const balance = await publicClient.getBalance({ address: treasury.address });
      assert.ok(balance > parseEther("1") - RATE * 10n, "refund came back to the treasury");
    });

    it("drops a lease its provider closed and reclaims the refund on the next refill", async function () {
      const { compute, as, treasury, asT } = await deployTreasury();
      await treasury.write.openLease([provider.account.address, RATE, SPEC, "", RATE * DAY]);
      await (await as(provider)).write.close([1n]);
      assert.ok((await compute.read.claimable([treasury.address])) > 0n);

      await (await asT(stranger)).write.refill();
      assert.deepEqual(await treasury.read.leases(), []);
      assert.equal(await compute.read.claimable([treasury.address]), 0n);
    });

    it("reports how long the whole service can run on what it has", async function () {
      const { treasury } = await deployTreasury();
      await treasury.write.openLease([provider.account.address, RATE, SPEC, "", RATE * DAY]);
      const runway = await treasury.read.totalRunway();
      // 1 CYBER total at 1 gwei/s ≈ 11.57 days, less the seconds already accrued.
      assert.ok(runway > 11n * DAY && runway <= parseEther("1") / RATE, `runway ${runway}`);
    });
  });
});
