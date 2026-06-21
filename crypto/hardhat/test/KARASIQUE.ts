import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { network } from "hardhat";

describe("KARASIQUE (KRSQ)", async function () {
  const { viem } = await network.connect();
  const [deployer, alice] = await viem.getWalletClients();
  const ONE = 10n ** 18n;

  it("has correct metadata and 18 decimals", async function () {
    const t = await viem.deployContract("KARASIQUE", [deployer.account.address]);
    assert.equal(await t.read.name(), "KARASIQUE");
    assert.equal(await t.read.symbol(), "KRSQ");
    assert.equal(await t.read.decimals(), 18);
    assert.equal(await t.read.totalSupply(), 0n);
    assert.equal(
      (await t.read.owner()).toLowerCase(),
      deployer.account.address.toLowerCase(),
    );
  });

  it("owner can mint; non-owner cannot", async function () {
    const t = await viem.deployContract("KARASIQUE", [deployer.account.address]);
    await t.write.mint([alice.account.address, 5n * ONE]);
    assert.equal(await t.read.balanceOf([alice.account.address]), 5n * ONE);
    assert.equal(await t.read.totalSupply(), 5n * ONE);

    await assert.rejects(
      t.write.mint([alice.account.address, ONE], { account: alice.account }),
      /Ownable: caller is not the owner/,
    );
  });

  it("owner can burnFrom without allowance; holder burns own with allowance path", async function () {
    const t = await viem.deployContract("KARASIQUE", [deployer.account.address]);
    await t.write.mint([alice.account.address, 5n * ONE]);

    // Owner burns from alice without any approval.
    await t.write.burnFrom([alice.account.address, 2n * ONE]);
    assert.equal(await t.read.balanceOf([alice.account.address]), 3n * ONE);

    // A non-owner cannot burnFrom alice without allowance.
    await assert.rejects(
      t.write.burnFrom([alice.account.address, ONE], { account: alice.account }),
    );

    // Standard self-burn works.
    await t.write.burn([ONE], { account: alice.account });
    assert.equal(await t.read.balanceOf([alice.account.address]), 2n * ONE);
  });
});
