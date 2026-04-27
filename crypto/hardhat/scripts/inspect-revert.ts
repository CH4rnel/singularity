import "dotenv/config";
import { createPublicClient, http } from "viem";
import { mainnet } from "viem/chains";

const cyberia = { ...mainnet, id: 49406, name: "Cyberia", nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 } };
const RPC = process.env.RPC_URL ?? "https://rpc.cyberia.church";
const pub = createPublicClient({ chain: cyberia, transport: http(RPC) });

const hash = "0x03f6afb49219a65ebb539a58bd47e8f22593b9b5748f067f19d9c9d2525d64ef" as `0x${string}`;
const tx = await pub.getTransaction({ hash });
console.log("from:", tx.from);
console.log("to:", tx.to);
console.log("value:", tx.value.toString());
console.log("gas:", tx.gas.toString());
console.log("input len:", tx.input.length);
console.log("input first 10 bytes (selector):", tx.input.slice(0, 10));
console.log("input:", tx.input);

const r = await pub.getTransactionReceipt({ hash });
console.log("status:", r.status, "gasUsed:", r.gasUsed.toString());

try {
  await pub.call({ to: tx.to!, data: tx.input, from: tx.from, value: tx.value, gas: tx.gas, blockNumber: r.blockNumber });
  console.log("(no revert on replay)");
} catch (e: any) {
  console.error("REPLAY revert:", e.shortMessage ?? e.message);
  if (e.cause) console.error("cause:", e.cause.shortMessage ?? e.cause.message);
  if (e.metaMessages) console.error(e.metaMessages.join("\n"));
  if (e.details) console.error("details:", e.details);
  if (e.data) console.error("data:", e.data);
}
