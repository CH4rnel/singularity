import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { parseEther, zeroAddress } from "viem";
import { network } from "hardhat";

/**
 * CyberiaOTC: fixed-price escrow offers with no platform fee. Covers the money
 * paths — the maker is paid in full, coin and token on either side, partial
 * fills at the stated price rounded in the maker's favour, minFill, private
 * offers, reprice behind the taker's maxPay, top-up and reduce, cancel and
 * reclaim (also while paused) — and that the owner can do nothing but pause.
 */
describe("CyberiaOTC", async function () {
  const { viem } = await network.connect();
  const publicClient = await viem.getPublicClient();
  const testClient = await viem.getTestClient();
  const [owner, maker, taker, stranger] = await viem.getWalletClients();

  const MILLION = parseEther("1000000");

  async function deploy() {
    const otc = await viem.deployContract("CyberiaOTC", [owner.account.address]);
    const token = await viem.deployContract("MockZoneToken", ["Service", "LEND", MILLION, maker.account.address]);
    const usd = await viem.deployContract("MockZoneToken", ["Dollar", "USDC", MILLION, taker.account.address]);
    const as = (client: typeof maker) =>
      viem.getContractAt("CyberiaOTC", otc.address, { client: { wallet: client } });
    const tokenAs = (address: `0x${string}`, client: typeof maker) =>
      viem.getContractAt("MockZoneToken", address, { client: { wallet: client } });
    return { otc, token, usd, as, tokenAs };
  }

  // Coin balance change of an address that does not send the transaction itself.
  async function balanceDelta(client: typeof maker, run: () => Promise<`0x${string}`>) {
    const before = await publicClient.getBalance({ address: client.account.address });
    await publicClient.waitForTransactionReceipt({ hash: await run() });
    return (await publicClient.getBalance({ address: client.account.address })) - before;
  }

  // 100k LEND for 1000 CYBER (0.01 each), partial fills from 1000 LEND.
  async function tokenForCoin(opts: { partial?: boolean; minFill?: bigint; expiry?: bigint; taker?: `0x${string}` } = {}) {
    const ctx = await deploy();
    await (await ctx.tokenAs(ctx.token.address, maker)).write.approve([ctx.otc.address, parseEther("100000")]);
    await (await ctx.as(maker)).write.create([
      ctx.token.address, parseEther("100000"), zeroAddress, parseEther("1000"),
      opts.taker ?? zeroAddress, opts.expiry ?? 0n, opts.partial ?? true, opts.minFill ?? 0n,
    ]);
    return ctx;
  }

  it("pays the maker in full and keeps nothing", async function () {
    const { otc, token, as } = await tokenForCoin();
    const pay = await otc.read.quote([0n, parseEther("25000")]);
    assert.equal(pay, parseEther("250"));

    const proceeds = await balanceDelta(maker, async () =>
      (await as(taker)).write.fill([0n, parseEther("25000"), pay], { value: pay }),
    );
    assert.equal(proceeds, pay);
    assert.equal(await token.read.balanceOf([taker.account.address]), parseEther("25000"));
    assert.equal((await otc.read.getOrder([0n])).remaining, parseEther("75000"));
    assert.equal(await publicClient.getBalance({ address: otc.address }), 0n);
  });

  it("sells the coin for a token, paid straight to the maker, rounding up", async function () {
    const { otc, usd, as, tokenAs } = await deploy();
    // 3 wei of coin for 10 wei of token: every partial fill must round up.
    await (await as(maker)).write.create([zeroAddress, 3n, usd.address, 10n, zeroAddress, 0n, true, 0n], { value: 3n });
    assert.equal(await otc.read.quote([0n, 1n]), 4n);

    await (await tokenAs(usd.address, taker)).write.approve([otc.address, 4n]);
    await (await as(taker)).write.fill([0n, 1n, 4n]);
    assert.equal(await usd.read.balanceOf([maker.account.address]), 4n);
    assert.equal(await usd.read.balanceOf([otc.address]), 0n);
    assert.equal((await otc.read.getOrder([0n])).remaining, 2n);
  });

  it("enforces all-or-nothing, minFill and the taker's maxPay", async function () {
    const whole = await tokenForCoin({ partial: false });
    const t = await whole.as(taker);
    await assert.rejects(t.write.fill([0n, parseEther("50"), parseEther("1")], { value: parseEther("0.5") }), /fill all/);

    const { as } = await tokenForCoin({ minFill: parseEther("1000") });
    const t2 = await as(taker);
    await assert.rejects(t2.write.fill([0n, parseEther("999"), parseEther("10")], { value: parseEther("9.99") }), /below minFill/);
    await assert.rejects(t2.write.fill([0n, parseEther("1000"), parseEther("9")], { value: parseEther("10") }), /price moved/);
    await assert.rejects(t2.write.fill([0n, parseEther("1000"), parseEther("10")], { value: parseEther("9") }), /value != pay/);
    await t2.write.fill([0n, parseEther("1000"), parseEther("10")], { value: parseEther("10") });
  });

  it("lets only the named taker fill a private offer", async function () {
    const { as } = await tokenForCoin({ taker: taker.account.address });
    await assert.rejects((await as(stranger)).write.fill([0n, parseEther("1000"), parseEther("10")], { value: parseEther("10") }), /private/);
    await (await as(taker)).write.fill([0n, parseEther("1000"), parseEther("10")], { value: parseEther("10") });
  });

  it("reprices for later fills and a stale maxPay reverts", async function () {
    const { otc, as } = await tokenForCoin();
    await assert.rejects((await as(stranger)).write.reprice([0n, 1n, 1n]), /not maker/);
    // Now 0.02 CYBER per LEND.
    await (await as(maker)).write.reprice([0n, parseEther("100000"), parseEther("2000")]);
    await assert.rejects(
      (await as(taker)).write.fill([0n, parseEther("1000"), parseEther("10")], { value: parseEther("10") }),
      /price moved/,
    );
    assert.equal(await otc.read.quote([0n, parseEther("1000")]), parseEther("20"));
  });

  it("tops up and reduces at the same price", async function () {
    const { otc, token, as, tokenAs } = await tokenForCoin();
    await (await tokenAs(token.address, maker)).write.approve([otc.address, parseEther("50000")]);
    await (await as(maker)).write.topUp([0n, parseEther("50000")]);
    assert.equal((await otc.read.getOrder([0n])).remaining, parseEther("150000"));

    await (await as(maker)).write.reduce([0n, parseEther("120000")]);
    assert.equal((await otc.read.getOrder([0n])).remaining, parseEther("30000"));
    assert.equal(await token.read.balanceOf([maker.account.address]), MILLION - parseEther("30000"));
    assert.equal(await otc.read.quote([0n, parseEther("1000")]), parseEther("10"));
    await assert.rejects((await as(maker)).write.reduce([0n, parseEther("30000")]), /use cancel/);
  });

  it("refuses fills after expiry; reclaim pays the maker whoever calls it", async function () {
    const now = (await publicClient.getBlock()).timestamp;
    const { otc, token, as } = await tokenForCoin({ expiry: now + 3600n });
    await assert.rejects((await as(stranger)).write.reclaim([0n]), /not expired/);

    await testClient.increaseTime({ seconds: 3601 });
    await testClient.mine({ blocks: 1 });
    await assert.rejects((await as(taker)).write.fill([0n, parseEther("1000"), parseEther("10")], { value: parseEther("10") }), /closed/);

    await (await as(stranger)).write.reclaim([0n]);
    assert.equal(await token.read.balanceOf([maker.account.address]), MILLION);
    assert.equal(await token.read.balanceOf([stranger.account.address]), 0n);
    assert.equal((await otc.read.getOrder([0n])).closed, true);
    await assert.rejects((await as(stranger)).write.reclaim([0n]), /closed/);
  });

  it("cancels and reduces while paused; the owner can only pause", async function () {
    const { otc, token, as } = await tokenForCoin();
    await assert.rejects((await as(stranger)).write.setPaused([true]), /Ownable/);
    await otc.write.setPaused([true]);
    await assert.rejects((await as(taker)).write.fill([0n, parseEther("1000"), parseEther("10")], { value: parseEther("10") }), /paused/);

    await (await as(maker)).write.reduce([0n, parseEther("1")]);
    await assert.rejects((await as(stranger)).write.cancel([0n]), /not maker/);
    await (await as(maker)).write.cancel([0n]);
    assert.equal(await token.read.balanceOf([maker.account.address]), MILLION);
    await assert.rejects((await as(maker)).write.cancel([0n]), /closed/);
  });

  it("refuses a zero owner, a coin mismatch, an offer to oneself and minFill above the amount", async function () {
    await assert.rejects(viem.deployContract("CyberiaOTC", [zeroAddress]), /zero owner/);
    const { usd, as } = await deploy();
    const m = await as(maker);
    await assert.rejects(m.write.create([zeroAddress, 2n, zeroAddress, 1n, zeroAddress, 0n, true, 0n], { value: 2n }), /same asset/);
    await assert.rejects(m.write.create([zeroAddress, 2n, usd.address, 1n, zeroAddress, 0n, true, 0n], { value: 1n }), /value != amount/);
    await assert.rejects(
      m.write.create([zeroAddress, 2n, usd.address, 1n, maker.account.address, 0n, true, 0n], { value: 2n }),
      /taker is maker/,
    );
    await assert.rejects(m.write.create([zeroAddress, 2n, usd.address, 1n, zeroAddress, 0n, true, 3n], { value: 2n }), /minFill > amount/);
  });

  it("pages offers newest first", async function () {
    const { otc, usd, as } = await deploy();
    for (let i = 1n; i <= 3n; i++) {
      await (await as(maker)).write.create([zeroAddress, i, usd.address, i, zeroAddress, 0n, true, 0n], { value: i });
    }
    const [ids, page] = await otc.read.getOrders([0n, 2n]);
    assert.deepEqual(ids, [2n, 1n]);
    assert.equal(page[0].remaining, 3n);
    const [rest] = await otc.read.getOrders([2n, 10n]);
    assert.deepEqual(rest, [0n]);
  });
});
