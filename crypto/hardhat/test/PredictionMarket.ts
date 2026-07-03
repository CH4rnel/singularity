import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { parseEther } from "viem";
import { network } from "hardhat";

/**
 * PredictionMarket: parimutuel YES/NO markets in native CYBER. Covers the
 * money paths — betting, oracle resolution with a losing-pool fee, pro-rata
 * winner payouts, no-winner and Invalid refunds, and the RESOLVE_WINDOW
 * dead-oracle refund that permanently disables late resolution.
 */
describe("PredictionMarket", async function () {
  const { viem } = await network.connect();
  const publicClient = await viem.getPublicClient();
  const testClient = await viem.getTestClient();
  const [deployer, alice, bob, carol] = await viem.getWalletClients();

  const DAY = 24n * 60n * 60n;
  const RESOLVE_WINDOW = 30n * DAY;
  // enum Outcome { None, Yes, No, Invalid }
  const YES = 1;
  const NO = 2;
  const INVALID = 3;

  async function now(): Promise<bigint> {
    const block = await publicClient.getBlock();
    return block.timestamp;
  }

  async function warpPast(ts: bigint) {
    await testClient.setNextBlockTimestamp({ timestamp: ts + 1n });
    await testClient.mine({ blocks: 1 });
  }

  // Deploy a fresh market book plus one open market closing in `closeIn` secs.
  async function deploy(closeIn = DAY) {
    const pm = await viem.deployContract("PredictionMarket", [
      deployer.account.address,
    ]);
    const closeTime = (await now()) + closeIn;
    await pm.write.createMarket(["Will it happen?", closeTime]);
    const as = (client: typeof alice) =>
      viem.getContractAt("PredictionMarket", pm.address, {
        client: { wallet: client },
      });
    return { pm, closeTime, as };
  }

  // Claim and return the caller's exact balance delta (payout minus gas).
  async function claimDelta(
    pm: { address: `0x${string}` },
    client: typeof alice,
    id: bigint,
  ): Promise<bigint> {
    const c = await viem.getContractAt("PredictionMarket", pm.address, {
      client: { wallet: client },
    });
    const before = await publicClient.getBalance({
      address: client.account.address,
    });
    const hash = await c.write.claim([id]);
    const receipt = await publicClient.waitForTransactionReceipt({ hash });
    const gas = receipt.gasUsed * receipt.effectiveGasPrice;
    const after = await publicClient.getBalance({
      address: client.account.address,
    });
    return after - before + gas;
  }

  it("creates markets with sane inputs and lists them", async function () {
    const { pm, closeTime } = await deploy();
    assert.equal(await pm.read.marketCount(), 1n);

    const m = await pm.read.getMarket([0n]);
    assert.equal(m.question, "Will it happen?");
    assert.equal(m.closeTime, closeTime);
    assert.equal(m.outcome, 0);
    assert.equal(m.yesPool, 0n);
    assert.equal(m.noPool, 0n);

    await assert.rejects(
      pm.write.createMarket(["", closeTime]),
      /bad question/,
    );
    await assert.rejects(
      pm.write.createMarket(["past", (await now()) - 1n]),
      /close in past/,
    );
    await assert.rejects(
      pm.write.createMarket(["far", (await now()) + 366n * DAY]),
      /too far out/,
    );

    const page = await pm.read.getMarkets([0n, 10n]);
    assert.equal(page.length, 1);
    assert.equal(page[0].id, 0n);
    assert.equal((await pm.read.getMarkets([5n, 10n])).length, 0);
  });

  it("lets anyone create for exactly createFee, forwarded to the treasury", async function () {
    const { pm, closeTime, as } = await deploy();
    const asAlice = await as(alice);
    const fee = await pm.read.createFee();
    assert.equal(fee, parseEther("1"));

    // Non-owners must pay exactly createFee; the oracle pays nothing.
    await assert.rejects(
      asAlice.write.createMarket(["free?", closeTime]),
      /wrong create fee/,
    );
    await assert.rejects(
      asAlice.write.createMarket(["overpaid?", closeTime], {
        value: fee + 1n,
      }),
      /wrong create fee/,
    );
    await assert.rejects(
      pm.write.createMarket(["oracle pays?", closeTime], { value: fee }),
      /wrong create fee/,
    );

    const treasuryBefore = await publicClient.getBalance({
      address: deployer.account.address,
    });
    await asAlice.write.createMarket(["Will alice's market work?", closeTime], {
      value: fee,
    });
    const treasuryAfter = await publicClient.getBalance({
      address: deployer.account.address,
    });
    assert.equal(treasuryAfter - treasuryBefore, fee);

    const m = await pm.read.getMarket([1n]);
    assert.equal(m.creator.toLowerCase(), alice.account.address.toLowerCase());
    assert.equal(m.question, "Will alice's market work?");

    // The fee is not part of any pot: the book holds nothing extra.
    assert.equal(await publicClient.getBalance({ address: pm.address }), 0n);

    // setCreateFee(0) makes creation free; only the owner may set it.
    await assert.rejects(
      asAlice.write.setCreateFee([0n]),
      /Ownable: caller is not the owner/,
    );
    await pm.write.setCreateFee([0n]);
    await asAlice.write.createMarket(["now free?", closeTime]);
    assert.equal(await pm.read.marketCount(), 3n);
  });

  it("accepts bets, tracks pools per side and per user", async function () {
    const { pm, as } = await deploy();
    const asAlice = await as(alice);
    const asBob = await as(bob);

    await asAlice.write.bet([0n, true], { value: parseEther("3") });
    await asAlice.write.bet([0n, true], { value: parseEther("1") });
    await asBob.write.bet([0n, false], { value: parseEther("2") });

    const m = await pm.read.getMarket([0n]);
    assert.equal(m.yesPool, parseEther("4"));
    assert.equal(m.noPool, parseEther("2"));
    assert.equal(
      await pm.read.yesBetOf([0n, alice.account.address]),
      parseEther("4"),
    );
    assert.equal(
      await pm.read.noBetOf([0n, bob.account.address]),
      parseEther("2"),
    );

    await assert.rejects(
      asAlice.write.bet([0n, true], { value: 1n }),
      /below min bet/,
    );
    await assert.rejects(
      asAlice.write.bet([7n, true], { value: parseEther("1") }),
      /no such market/,
    );
  });

  it("closes betting at closeTime and gates resolve correctly", async function () {
    const { pm, closeTime, as } = await deploy();
    const asAlice = await as(alice);
    await asAlice.write.bet([0n, true], { value: parseEther("1") });

    // Yes/No resolution needs the market closed; non-owners never resolve.
    await assert.rejects(pm.write.resolve([0n, YES]), /betting open/);
    await assert.rejects(
      asAlice.write.resolve([0n, YES]),
      /Ownable: caller is not the owner/,
    );

    await warpPast(closeTime);
    await assert.rejects(
      asAlice.write.bet([0n, true], { value: parseEther("1") }),
      /betting closed/,
    );

    await pm.write.resolve([0n, YES]);
    assert.equal((await pm.read.getMarket([0n])).outcome, YES);
    await assert.rejects(pm.write.resolve([0n, NO]), /resolved/);
  });

  it("pays winners pro-rata with a 2% fee off the losing pool", async function () {
    const { pm, closeTime, as } = await deploy();
    await (await as(alice)).write.bet([0n, true], { value: parseEther("3") });
    await (await as(carol)).write.bet([0n, true], { value: parseEther("1") });
    await (await as(bob)).write.bet([0n, false], { value: parseEther("4") });

    await warpPast(closeTime);

    const feeRecipientBefore = await publicClient.getBalance({
      address: deployer.account.address,
    });
    const hash = await pm.write.resolve([0n, YES]);
    const receipt = await publicClient.waitForTransactionReceipt({ hash });
    const gas = receipt.gasUsed * receipt.effectiveGasPrice;

    // fee = 2% of the losing (NO) pool = 0.08
    const fee = parseEther("0.08");
    assert.equal((await pm.read.getMarket([0n])).feePaid, fee);
    assert.equal(
      (await publicClient.getBalance({
        address: deployer.account.address,
      })) -
        feeRecipientBefore +
        gas,
      fee,
    );

    // Distributable losing pool: 4 - 0.08 = 3.92; alice holds 3/4 of YES.
    const aliceExpected = parseEther("3") + (parseEther("3.92") * 3n) / 4n;
    const carolExpected = parseEther("1") + parseEther("3.92") / 4n;
    assert.equal(
      await pm.read.claimableOf([0n, alice.account.address]),
      aliceExpected,
    );
    assert.equal(await pm.read.claimableOf([0n, bob.account.address]), 0n);

    assert.equal(await claimDelta(pm, alice, 0n), aliceExpected);
    assert.equal(await claimDelta(pm, carol, 0n), carolExpected);

    // Book is emptied to the wei and double/loser claims revert.
    assert.equal(await publicClient.getBalance({ address: pm.address }), 0n);
    await assert.rejects(
      (await as(alice)).write.claim([0n]),
      /already claimed/,
    );
    await assert.rejects(
      (await as(bob)).write.claim([0n]),
      /nothing to claim/,
    );
  });

  it("refunds the losing side in full when nobody bet the winner (no fee)", async function () {
    const { pm, closeTime, as } = await deploy();
    await (await as(bob)).write.bet([0n, false], { value: parseEther("5") });

    await warpPast(closeTime);
    await pm.write.resolve([0n, YES]);

    assert.equal((await pm.read.getMarket([0n])).feePaid, 0n);
    assert.equal(await claimDelta(pm, bob, 0n), parseEther("5"));
  });

  it("Invalid cancels any time and refunds both sides in full", async function () {
    const { pm, as } = await deploy();
    await (await as(alice)).write.bet([0n, true], { value: parseEther("2") });
    await (await as(alice)).write.bet([0n, false], { value: parseEther("1") });

    // No need to wait for closeTime: Invalid acts as a cancel.
    await pm.write.resolve([0n, INVALID]);
    assert.equal((await pm.read.getMarket([0n])).feePaid, 0n);
    assert.equal(await claimDelta(pm, alice, 0n), parseEther("3"));
  });

  it("opens refunds and disables resolve once RESOLVE_WINDOW lapses", async function () {
    const { pm, closeTime, as } = await deploy();
    await (await as(alice)).write.bet([0n, true], { value: parseEther("2") });

    await warpPast(closeTime);
    // Window still open: no refund yet.
    assert.equal(await pm.read.claimableOf([0n, alice.account.address]), 0n);
    await assert.rejects(
      (await as(alice)).write.claim([0n]),
      /nothing to claim/,
    );

    await warpPast(closeTime + RESOLVE_WINDOW);
    await assert.rejects(pm.write.resolve([0n, YES]), /refund window open/);
    assert.equal(await claimDelta(pm, alice, 0n), parseEther("2"));
  });

  it("reports user bets over a page via getUserBets", async function () {
    const { pm, closeTime, as } = await deploy();
    const closeTime2 = closeTime + DAY;
    await pm.write.createMarket(["Second?", closeTime2]);
    await (await as(alice)).write.bet([0n, true], { value: parseEther("1") });
    await (await as(alice)).write.bet([1n, false], { value: parseEther("2") });

    const [yes, no, claimedArr, claimable] = await pm.read.getUserBets([
      alice.account.address,
      0n,
      10n,
    ]);
    assert.deepEqual(yes, [parseEther("1"), 0n]);
    assert.deepEqual(no, [0n, parseEther("2")]);
    assert.deepEqual(claimedArr, [false, false]);
    assert.deepEqual(claimable, [0n, 0n]);
  });

  it("enforces admin setter bounds", async function () {
    const { pm, as } = await deploy();
    await assert.rejects(pm.write.setFeeBps([1001n]), /fee too high/);
    await pm.write.setFeeBps([0n]);
    assert.equal(await pm.read.feeBps(), 0n);
    await assert.rejects(pm.write.setMinBet([0n]), /zero min bet/);
    await assert.rejects(
      pm.write.setFeeRecipient(["0x0000000000000000000000000000000000000000"]),
      /zero recipient/,
    );
    await assert.rejects(
      (await as(alice)).write.setFeeBps([0n]),
      /Ownable: caller is not the owner/,
    );
  });
});
