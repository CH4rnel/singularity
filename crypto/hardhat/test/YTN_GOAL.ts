import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { network } from "hardhat";

const CASES = [
  { contract: "YTN", name: "Yenten", symbol: "YTN" },
  { contract: "GOAL", name: "Goal Bear Coin", symbol: "GOAL" },
];

describe("YTN & GOAL tokens", async function () {
  const { viem } = await network.connect();
  const [deployer, alice] = await viem.getWalletClients();
  const ONE = 10n ** 18n;

  for (const c of CASES) {
    it(`${c.contract}: metadata, 18 decimals, owner mint/burn`, async function () {
      const t = await viem.deployContract(c.contract, [deployer.account.address]);
      assert.equal(await t.read.name(), c.name);
      assert.equal(await t.read.symbol(), c.symbol);
      assert.equal(await t.read.decimals(), 18);
      assert.equal(await t.read.totalSupply(), 0n);
      assert.equal(
        (await t.read.owner()).toLowerCase(),
        deployer.account.address.toLowerCase(),
      );

      // owner mints, non-owner cannot
      await t.write.mint([alice.account.address, 5n * ONE]);
      assert.equal(await t.read.balanceOf([alice.account.address]), 5n * ONE);
      await assert.rejects(
        t.write.mint([alice.account.address, ONE], { account: alice.account }),
        /Ownable: caller is not the owner/,
      );

      // owner burnFrom without allowance
      await t.write.burnFrom([alice.account.address, 2n * ONE]);
      assert.equal(await t.read.balanceOf([alice.account.address]), 3n * ONE);
    });
  }
});
