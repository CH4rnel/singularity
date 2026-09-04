import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { network } from "hardhat";

/**
 * Where the protocol's share of swap fees goes.
 *
 * The contract itself is deliberately dumb -- it splits by weights and nothing else -- so what is
 * worth testing is the parts that would quietly lose money: the rounding remainder, a recipient
 * that cannot accept the coin, and whether the weights can be set to something that does not add up.
 */
describe("FeeSplitter", async function () {
  const { viem } = await network.connect();
  const publicClient = await viem.getPublicClient();
  const [deployer, treasury, buyback, gasStation, outsider] = await viem.getWalletClients();

  const ONE = 10n ** 18n;

  async function deploy(
    shares: Array<{ recipient: `0x${string}`; weight: number }> = [
      { recipient: treasury.account.address, weight: 5000 },
      { recipient: buyback.account.address, weight: 3000 },
      { recipient: gasStation.account.address, weight: 2000 },
    ],
  ) {
    const weth = await viem.deployContract("WCYBER");
    const splitter = await viem.deployContract("FeeSplitter", [weth.address, shares]);
    const token = await viem.deployContract("ETH", [deployer.account.address]);
    return { splitter, token, weth };
  }

  it("splits an ERC20 balance by the configured weights", async function () {
    const { splitter, token } = await deploy();
    await token.write.mint([splitter.address, 1000n * ONE]);

    await splitter.write.distribute([token.address], { account: outsider.account });

    assert.equal(await token.read.balanceOf([treasury.account.address]), 500n * ONE);
    assert.equal(await token.read.balanceOf([buyback.account.address]), 300n * ONE);
    assert.equal(await token.read.balanceOf([gasStation.account.address]), 200n * ONE);
    assert.equal(await token.read.balanceOf([splitter.address]), 0n);
  });

  it("leaves no dust behind on an amount that does not divide evenly", async function () {
    const { splitter, token } = await deploy();
    await token.write.mint([splitter.address, 1001n]); // 1001 / 10000 does not divide

    await splitter.write.distribute([token.address]);

    const paid =
      (await token.read.balanceOf([treasury.account.address])) +
      (await token.read.balanceOf([buyback.account.address])) +
      (await token.read.balanceOf([gasStation.account.address]));
    assert.equal(paid, 1001n, "every wei must land somewhere");
    assert.equal(await token.read.balanceOf([splitter.address]), 0n);
  });

  it("splits the coin, and unwraps it first when asked", async function () {
    const { splitter, weth } = await deploy();

    await weth.write.deposit({ value: 10n * ONE });
    await weth.write.transfer([splitter.address, 10n * ONE]);

    const before = await publicClient.getBalance({ address: gasStation.account.address });
    await splitter.write.unwrapAndDistributeNative({ account: outsider.account });
    const after = await publicClient.getBalance({ address: gasStation.account.address });

    assert.equal(after - before, 2n * ONE);
    assert.equal(await publicClient.getBalance({ address: splitter.address }), 0n);
  });

  it("one recipient that cannot take the coin does not stop the others", async function () {
    const broken = await viem.deployContract("RejectsNative");
    const { splitter } = await deploy([
      { recipient: treasury.account.address, weight: 5000 },
      { recipient: broken.address, weight: 5000 },
    ]);

    await deployer.sendTransaction({ to: splitter.address, value: 10n * ONE });

    const before = await publicClient.getBalance({ address: treasury.account.address });
    await splitter.write.distributeNative({ account: outsider.account });

    assert.equal(
      (await publicClient.getBalance({ address: treasury.account.address })) - before,
      5n * ONE,
      "the recipient that can be paid is paid",
    );
    assert.equal(
      await splitter.read.owedNative([broken.address]),
      5n * ONE,
      "the one that cannot is credited, not lost",
    );
    // and what is owed is not handed out again by the next distribution
    await deployer.sendTransaction({ to: splitter.address, value: 2n * ONE });
    const treasuryBefore = await publicClient.getBalance({ address: treasury.account.address });
    await splitter.write.distributeNative();
    assert.equal(
      (await publicClient.getBalance({ address: treasury.account.address })) - treasuryBefore,
      1n * ONE,
    );
  });

  it("refuses weights that do not add up to the whole", async function () {
    await assert.rejects(
      deploy([
        { recipient: treasury.account.address, weight: 5000 },
        { recipient: buyback.account.address, weight: 4000 },
      ]),
    );
    await assert.rejects(deploy([]));
    await assert.rejects(
      deploy([{ recipient: "0x0000000000000000000000000000000000000000", weight: 10000 }]),
    );
  });

  it("only the owner retunes the split, and it takes effect at once", async function () {
    const { splitter, token } = await deploy();

    await assert.rejects(
      splitter.write.setShares([[{ recipient: outsider.account.address, weight: 10000 }]], {
        account: outsider.account,
      }),
    );

    await splitter.write.setShares([
      [
        { recipient: treasury.account.address, weight: 1000 },
        { recipient: gasStation.account.address, weight: 9000 },
      ],
    ]);
    assert.equal(await splitter.read.shareCount(), 2n);

    await token.write.mint([splitter.address, 100n * ONE]);
    await splitter.write.distribute([token.address]);
    assert.equal(await token.read.balanceOf([gasStation.account.address]), 90n * ONE);
    assert.equal(await token.read.balanceOf([buyback.account.address]), 0n);
  });

  it("says so rather than doing nothing when there is nothing to split", async function () {
    const { splitter, token } = await deploy();
    await assert.rejects(splitter.write.distribute([token.address]));
    await assert.rejects(splitter.write.distributeNative());
    await assert.rejects(splitter.write.unwrapAndDistributeNative());
  });
});
