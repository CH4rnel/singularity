import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import vm from "node:vm";
import ts from "typescript";

const source = readFileSync(new URL("../scripts/relay-bridge.ts", import.meta.url), "utf8");
const script = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

async function runRelay({ convert = false, timeoutAt = null, bridgeAddress = undefined } = {}) {
  const events = [];
  const { promise: completed, resolve: finish } = Promise.withResolvers();
  const publicClient = {
    getBalance: async () => 1000000000000000000n,
    readContract: async () => 1000000000000000000n,
    waitForTransactionReceipt: async ({ hash }) => {
      events.push({ wait: hash });
      if (hash === timeoutAt) throw new Error("receipt timeout");
      return { status: "success", blockNumber: 1n };
    },
  };
  const walletClient = {
    writeContract: async ({ functionName, address }) => {
      events.push({ write: functionName, address });
      return ({ releaseCyberSol: "0xmint", approve: "0xapproval", swap: "0xswap" })[functionName];
    },
    sendTransaction: async () => "0xpayout",
  };

  vm.runInNewContext(script, {
    exports: {},
    require(name) {
      if (name === "dotenv/config") return {};
      if (name === "viem") return {
        createWalletClient: () => walletClient,
        createPublicClient: () => publicClient,
        http: () => ({}),
        parseAbi: (abi) => abi,
      };
      if (name === "viem/accounts") return { privateKeyToAccount: () => ({ address: "0xrelayer" }) };
      if (name === "viem/chains") return { mainnet: {} };
      throw new Error(`Unexpected dependency: ${name}`);
    },
    process: {
      env: { BRIDGE_RELAYER_PRIVATE_KEY: "fixture", BRIDGE_EVM_CONTRACT_ADDRESS: bridgeAddress },
      argv: ["node", "relay-bridge.ts", "sol_to_evm", "0xrecipient", "1000000", "7", "0", convert ? "1" : "0"],
      exit: (code) => { events.push({ exit: code }); finish(); },
    },
    console: {
      log(...args) {
        const line = args.map(String).join(" ");
        events.push({ line });
        if (line.startsWith("{") && JSON.parse(line).status === "success") finish();
      },
      error: (message) => events.push({ error: String(message) }),
    },
  });

  await completed;
  return events;
}

function broadcasts(events) {
  return events.flatMap((event) => event.line?.startsWith("{") ? [JSON.parse(event.line)] : []);
}

test("a release emits its durable hash before a receipt timeout", async () => {
  const events = await runRelay({ timeoutAt: "0xmint" });
  assert.deepEqual(broadcasts(events), [{ broadcastTxHash: "0xmint" }]);
  assert.ok(events.findIndex((event) => event.line === '{"broadcastTxHash":"0xmint"}') < events.findIndex((event) => event.wait === "0xmint"));
  assert.deepEqual(events.at(-1), { exit: 1 });
});

test("conversion emits its mint, swap and recipient payout before receipt waits", async () => {
  const events = await runRelay({ convert: true, timeoutAt: "0xpayout" });
  assert.ok(broadcasts(events).some((event) => event.broadcastTxHash === "0xmint"));
  assert.ok(broadcasts(events).some((event) => event.broadcastTxHash === "0xpayout"));
  for (const hash of ["0xmint", "0xswap", "0xpayout"]) {
    assert.ok(events.findIndex((event) => event.line === JSON.stringify({ broadcastTxHash: hash })) < events.findIndex((event) => event.wait === hash));
  }
  assert.deepEqual(events.at(-1), { exit: 1 });
});

test("a conversion swap timeout leaves its mint and swap broadcasts visible", async () => {
  const events = await runRelay({ convert: true, timeoutAt: "0xswap" });
  assert.deepEqual(broadcasts(events), [{ broadcastTxHash: "0xmint" }, { broadcastTxHash: "0xswap" }]);
  assert.ok(events.findIndex((event) => event.line === '{"broadcastTxHash":"0xswap"}') < events.findIndex((event) => event.wait === "0xswap"));
  assert.deepEqual(events.at(-1), { exit: 1 });
});

test("a completed plain release preserves the final result", async () => {
  const events = await runRelay();
  assert.deepEqual(broadcasts(events).at(-1), { txHash: "0xmint", status: "success", gasDropTxHash: null });
});

test("a completed conversion preserves the recipient payout result", async () => {
  const events = await runRelay({ convert: true });
  assert.deepEqual(broadcasts(events).at(-1), {
    txHash: "0xpayout", status: "success", converted: true, convertedAmountWei: "1000", gasDropTxHash: null,
  });
});

test("the relay defaults to the deployed frontend bridge", async () => {
  const events = await runRelay();
  assert.equal(events.find((event) => event.write === "releaseCyberSol").address, "0xEf2c8E731006EEDD8F44f5Ea03A389635BB28f90");
});

test("an explicit relay bridge override takes precedence", async () => {
  const events = await runRelay({ bridgeAddress: "0xconfigured" });
  assert.equal(events.find((event) => event.write === "releaseCyberSol").address, "0xconfigured");
});
