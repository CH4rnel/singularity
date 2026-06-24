import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { network } from "hardhat";

/**
 * Tests for CyberSolSwap — a fixed-rate redeemer that converts bridged
 * CYBER.sol into native CYBER at a hard 1000 : 1 ratio.
 *
 * The mock CYBER.sol is the real `WrappedCyberSol` contract (18 decimals,
 * owner-mintable), so the test mirrors the production token exactly.
 */
describe("CyberSolSwap", async function () {
  const { viem } = await network.connect();
  const publicClient = await viem.getPublicClient();
  const [deployer, alice, bob] = await viem.getWalletClients();

  const ONE = 10n ** 18n; // 1 token (18 decimals)
  const RATE = 1000n;

  // Deploy a fresh CYBER.sol mock + swap, mint `mintToAlice` CYBER.sol to alice,
  // and fund the swap with `fundNative` native CYBER. Returns the handles.
  async function deploy(mintToAlice = 0n, fundNative = 0n) {
    // WrappedCyberSol(bridge) sets `bridge` as the owner/minter — use deployer.
    const cyberSol = await viem.deployContract("WrappedCyberSol", [
      deployer.account.address,
    ]);
    const swap = await viem.deployContract("CyberSolSwap", [cyberSol.address]);

    if (mintToAlice > 0n) {
      await cyberSol.write.mint([alice.account.address, mintToAlice]);
    }
    if (fundNative > 0n) {
      // Plain native transfer hits receive() and funds payout liquidity.
      const hash = await deployer.sendTransaction({
        to: swap.address,
        value: fundNative,
      });
      await publicClient.waitForTransactionReceipt({ hash });
    }
    return { cyberSol, swap };
  }

  it("constructor wires cyberSol, RATE and owner; rejects zero token", async function () {
    const { cyberSol, swap } = await deploy();
    assert.equal(
      (await swap.read.cyberSol()).toLowerCase(),
      cyberSol.address.toLowerCase(),
    );
    assert.equal(await swap.read.RATE(), RATE);
    assert.equal(
      (await swap.read.owner()).toLowerCase(),
      deployer.account.address.toLowerCase(),
    );

    await assert.rejects(
      viem.deployContract("CyberSolSwap", [
        "0x0000000000000000000000000000000000000000",
      ]),
      /cyberSol=0/,
    );
  });

  it("quote returns amountIn / RATE", async function () {
    const { swap } = await deploy();
    assert.equal(await swap.read.quote([1000n * ONE]), ONE);
    assert.equal(await swap.read.quote([0n]), 0n);
    assert.equal(await swap.read.quote([1500n]), 1n); // integer division
  });

  it("receive() funds native liquidity and emits NativeFunded", async function () {
    const { swap } = await deploy();
    const hash = await deployer.sendTransaction({
      to: swap.address,
      value: 3n * ONE,
    });
    await publicClient.waitForTransactionReceipt({ hash });

    assert.equal(await publicClient.getBalance({ address: swap.address }), 3n * ONE);

    const events = await publicClient.getContractEvents({
      address: swap.address,
      abi: swap.abi,
      eventName: "NativeFunded",
      fromBlock: 0n,
    });
    assert.equal(events.length, 1);
    assert.equal(events[0].args.amount, 3n * ONE);
  });

  it("swap: happy path moves tokens in, pays native out, emits Swapped", async function () {
    const amountIn = 1000n * ONE; // 1000 CYBER.sol
    const expectedOut = amountIn / RATE; // 1 CYBER
    const { cyberSol, swap } = await deploy(amountIn, 5n * ONE);

    await cyberSol.write.approve([swap.address, amountIn], {
      account: alice.account,
    });

    const swapBalBefore = await publicClient.getBalance({ address: swap.address });
    const aliceNativeBefore = await publicClient.getBalance({
      address: alice.account.address,
    });

    const hash = await swap.write.swap([amountIn], { account: alice.account });
    const receipt = await publicClient.waitForTransactionReceipt({ hash });
    const gasCost = receipt.gasUsed * receipt.effectiveGasPrice;

    // CYBER.sol moved entirely from alice into the swap contract.
    assert.equal(await cyberSol.read.balanceOf([alice.account.address]), 0n);
    assert.equal(await cyberSol.read.balanceOf([swap.address]), amountIn);

    // Native CYBER: contract paid out exactly expectedOut...
    assert.equal(
      await publicClient.getBalance({ address: swap.address }),
      swapBalBefore - expectedOut,
    );
    // ...and alice received it (net of gas she paid).
    assert.equal(
      await publicClient.getBalance({ address: alice.account.address }),
      aliceNativeBefore + expectedOut - gasCost,
    );

    const events = await publicClient.getContractEvents({
      address: swap.address,
      abi: swap.abi,
      eventName: "Swapped",
      fromBlock: 0n,
    });
    assert.equal(events.length, 1);
    assert.equal(events[0].args.user?.toLowerCase(), alice.account.address.toLowerCase());
    assert.equal(events[0].args.amountIn, amountIn);
    assert.equal(events[0].args.amountOut, expectedOut);
  });

  it("swap reverts when amountIn is not a multiple of RATE", async function () {
    const amountIn = 1000n * ONE + 1n; // 1 wei over a clean multiple
    const { cyberSol, swap } = await deploy(amountIn, 5n * ONE);
    await cyberSol.write.approve([swap.address, amountIn], {
      account: alice.account,
    });
    await assert.rejects(
      swap.write.swap([amountIn], { account: alice.account }),
      /amount not multiple of RATE/,
    );
  });

  it("swap reverts when amountOut would be zero", async function () {
    const { swap } = await deploy(0n, 5n * ONE);
    // 0 passes the modulo check but rounds to 0 payout.
    await assert.rejects(
      swap.write.swap([0n], { account: alice.account }),
      /amount too small/,
    );
  });

  it("swap reverts when native liquidity is insufficient", async function () {
    const amountIn = 1000n * ONE; // wants 1 CYBER out
    // Fund with less than the payout (only 0.5 CYBER).
    const { cyberSol, swap } = await deploy(amountIn, ONE / 2n);
    await cyberSol.write.approve([swap.address, amountIn], {
      account: alice.account,
    });
    await assert.rejects(
      swap.write.swap([amountIn], { account: alice.account }),
      /insufficient CYBER liquidity/,
    );
  });

  it("swap reverts without sufficient token allowance", async function () {
    const amountIn = 1000n * ONE;
    const { swap } = await deploy(amountIn, 5n * ONE); // alice funded, no approve
    await assert.rejects(
      swap.write.swap([amountIn], { account: alice.account }),
    );
  });

  it("withdrawTokens: owner rescues collected CYBER.sol, others cannot", async function () {
    const amountIn = 1000n * ONE;
    const { cyberSol, swap } = await deploy(amountIn, 5n * ONE);
    await cyberSol.write.approve([swap.address, amountIn], {
      account: alice.account,
    });
    await swap.write.swap([amountIn], { account: alice.account });

    // Non-owner cannot withdraw.
    await assert.rejects(
      swap.write.withdrawTokens([bob.account.address, amountIn], {
        account: alice.account,
      }),
      /Ownable: caller is not the owner/,
    );

    // Owner sweeps the collected CYBER.sol to bob.
    await swap.write.withdrawTokens([bob.account.address, amountIn]);
    assert.equal(await cyberSol.read.balanceOf([bob.account.address]), amountIn);
    assert.equal(await cyberSol.read.balanceOf([swap.address]), 0n);
  });

  it("withdrawNative: owner removes native liquidity, others cannot", async function () {
    const { swap } = await deploy(0n, 4n * ONE);

    await assert.rejects(
      swap.write.withdrawNative([bob.account.address, ONE], {
        account: alice.account,
      }),
      /Ownable: caller is not the owner/,
    );

    const bobBefore = await publicClient.getBalance({ address: bob.account.address });
    await swap.write.withdrawNative([bob.account.address, 4n * ONE]);
    assert.equal(
      await publicClient.getBalance({ address: bob.account.address }),
      bobBefore + 4n * ONE,
    );
    assert.equal(await publicClient.getBalance({ address: swap.address }), 0n);
  });
});
