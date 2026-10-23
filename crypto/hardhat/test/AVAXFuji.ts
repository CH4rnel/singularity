import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { network } from "hardhat";

describe("AVAXFuji", async () => {
  const { viem } = await network.connect();
  const [relayer, user] = await viem.getWalletClients();

  it("starts empty, identifies Fuji, and restricts issuance to the bridge", async () => {
    const token = await viem.deployContract("AVAXFuji", [relayer.account.address]);
    assert.equal(await token.read.totalSupply(), 0n);
    assert.equal(await token.read.symbol(), "AVAX.FUJI");
    assert.equal(await token.read.decimals(), 18);
    await assert.rejects(token.write.mint([user.account.address, 100n], { account: user.account }));
    await token.write.mint([user.account.address, 100n]);
    assert.equal(await token.read.balanceOf([user.account.address]), 100n);
  });

  it("only lets the relayer burn wrappers actually deposited to it", async () => {
    const token = await viem.deployContract("AVAXFuji", [relayer.account.address]);
    await token.write.mint([user.account.address, 100n]);
    await assert.rejects(token.write.burnFrom([user.account.address, 100n]));
    await token.write.transfer([relayer.account.address, 100n], { account: user.account });
    await token.write.burnFrom([relayer.account.address, 100n]);
    assert.equal(await token.read.totalSupply(), 0n);
  });
});
