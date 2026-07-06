import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { parseEther, zeroAddress } from "viem";
import { network } from "hardhat";

/**
 * LaunchpadNative: fair launches paid in native CYBER. Covers the money path —
 * the caller's CYBER becomes token/WCYBER liquidity whose LP lands on the burn
 * address — plus the min-liquidity gate and the owner-only listing of
 * pre-existing tokens (LAIN/MINE style).
 */
describe("LaunchpadNative", async function () {
  const { viem } = await network.connect();
  const publicClient = await viem.getPublicClient();
  const [deployer, alice] = await viem.getWalletClients();

  const BURN = "0x000000000000000000000000000000000000dEaD";
  const MIN = parseEther("10");
  const SUPPLY = parseEther("1000000");

  // Fresh WCYBER + QuickSwap V2 factory/router + launchpad (min 10 CYBER).
  async function deploy() {
    const wcyber = await viem.deployContract("WCYBER");
    const factory = await viem.deployContract("UniswapV2Factory", [
      deployer.account.address,
    ]);
    const router = await viem.deployContract("UniswapV2Router02", [
      factory.address,
      wcyber.address,
    ]);
    const pad = await viem.deployContract("LaunchpadNative", [
      router.address,
      MIN,
    ]);
    return { wcyber, factory, router, pad };
  }

  it("stores router wiring and the native minimum", async function () {
    const { wcyber, factory, router, pad } = await deploy();
    assert.equal(
      (await pad.read.router()).toLowerCase(),
      router.address.toLowerCase(),
    );
    assert.equal(
      (await pad.read.factory()).toLowerCase(),
      factory.address.toLowerCase(),
    );
    assert.equal(
      (await pad.read.wcyber()).toLowerCase(),
      wcyber.address.toLowerCase(),
    );
    assert.equal(await pad.read.minLiquidity(), MIN);
    assert.equal(await pad.read.allTokensLength(), 0n);
  });

  it("launches a token: full supply pooled with the CYBER sent, LP burned", async function () {
    const { wcyber, factory, pad } = await deploy();

    const hash = await pad.write.launch(["Neon", "NEON", SUPPLY], {
      value: MIN,
      account: alice.account,
    });
    await publicClient.waitForTransactionReceipt({ hash });

    assert.equal(await pad.read.allTokensLength(), 1n);
    const token = await pad.read.allTokens([0n]);
    const pair = await factory.read.getPair([token, wcyber.address]);
    assert.notEqual(pair, zeroAddress);
    assert.equal(await pad.read.pairOf([token]), pair);

    // 100% of the supply and all 10 CYBER (as WCYBER) sit in the pair.
    const erc20 = await viem.getContractAt("LaunchpadToken", token);
    assert.equal(await erc20.read.balanceOf([pair]), SUPPLY);
    assert.equal(await erc20.read.balanceOf([alice.account.address]), 0n);
    assert.equal(await wcyber.read.balanceOf([pair]), MIN);

    // LP went to the burn address, nothing stayed on the launchpad or caller.
    const lp = await viem.getContractAt("UniswapV2Pair", pair);
    const burned = await lp.read.balanceOf([BURN]);
    assert.ok(burned > 0n);
    assert.equal(await lp.read.balanceOf([pad.address]), 0n);
    assert.equal(await lp.read.balanceOf([alice.account.address]), 0n);

    const launched = await pad.getEvents.TokenLaunched();
    assert.equal(launched.length, 1);
    assert.equal(launched[0].args.cyberLiquidity, MIN);
    assert.equal(launched[0].args.tokenSupply, SUPPLY);
    assert.equal(
      launched[0].args.creator!.toLowerCase(),
      alice.account.address.toLowerCase(),
    );
  });

  it("rejects launches below the 10 CYBER minimum", async function () {
    const { pad } = await deploy();
    await assert.rejects(
      pad.write.launch(["Neon", "NEON", SUPPLY], {
        value: MIN - 1n,
        account: alice.account,
      }),
      /cyber<min/,
    );
  });

  it("makes the launched token immediately tradable for CYBER", async function () {
    const { wcyber, router, pad } = await deploy();

    await publicClient.waitForTransactionReceipt({
      hash: await pad.write.launch(["Neon", "NEON", SUPPLY], { value: MIN }),
    });
    const token = await pad.read.allTokens([0n]);

    const deadline = BigInt(Math.floor(Date.now() / 1000) + 600);
    const hash = await router.write.swapExactETHForTokens(
      [0n, [wcyber.address, token], alice.account.address, deadline],
      { value: parseEther("1"), account: alice.account },
    );
    await publicClient.waitForTransactionReceipt({ hash });

    const erc20 = await viem.getContractAt("LaunchpadToken", token);
    const bought = await erc20.read.balanceOf([alice.account.address]);
    assert.ok(bought > 0n);
  });

  it("lists pre-existing tokens via registerToken, owner-only and once", async function () {
    const { wcyber, factory, router, pad } = await deploy();

    // A stand-in for LAIN/MINE: an existing token with a live WCYBER pool.
    const lain = await viem.deployContract("LaunchpadToken", [
      "Lain",
      "LAIN",
      SUPPLY,
      deployer.account.address,
    ]);
    await lain.write.approve([router.address, SUPPLY]);
    const deadline = BigInt(Math.floor(Date.now() / 1000) + 600);
    await publicClient.waitForTransactionReceipt({
      hash: await router.write.addLiquidityETH(
        [
          lain.address,
          parseEther("1000"),
          0n,
          0n,
          deployer.account.address,
          deadline,
        ],
        { value: parseEther("5") },
      ),
    });
    const pair = await factory.read.getPair([lain.address, wcyber.address]);

    await assert.rejects(
      pad.write.registerToken([lain.address, pair], {
        account: alice.account,
      }),
      /not owner/,
    );

    await publicClient.waitForTransactionReceipt({
      hash: await pad.write.registerToken([lain.address, pair]),
    });
    assert.equal(await pad.read.allTokensLength(), 1n);
    assert.equal(
      (await pad.read.allTokens([0n])).toLowerCase(),
      lain.address.toLowerCase(),
    );
    assert.equal(await pad.read.pairOf([lain.address]), pair);

    await assert.rejects(
      pad.write.registerToken([lain.address, pair]),
      /listed/,
    );
  });
});
