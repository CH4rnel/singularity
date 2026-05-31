import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { network } from "hardhat";

describe("TokenFactory", async function () {
  const { viem } = await network.connect();
  const publicClient = await viem.getPublicClient();

  it("deploys a factory and creates a voting token", async function () {
    const addresses = await viem.getAddresses();
    const deployer = addresses[0];
    const tokenOwner = addresses[1];

    const factory = await viem.deployContract("TokenFactory", [deployer]);
    const receipt = await factory.write.createToken(["Voting Token", "VOTE", tokenOwner]);

    const events = await publicClient.getContractEvents({
      address: factory.address,
      abi: factory.abi,
      eventName: "TokenCreated",
      fromBlock: receipt.blockNumber,
      strict: true,
    });

    assert.equal(events.length, 1);
    assert.equal(events[0].args[1], tokenOwner);

    const tokenAddress = events[0].args[0];
    const token = await viem.getContract({
      address: tokenAddress,
      abi: [
        { inputs: [], name: "name", outputs: [{ type: "string" }], stateMutability: "view", type: "function" },
        { inputs: [], name: "symbol", outputs: [{ type: "string" }], stateMutability: "view", type: "function" },
      ],
    });

    assert.equal(await token.read.name(), "Voting Token");
    assert.equal(await token.read.symbol(), "VOTE");
  });
});
