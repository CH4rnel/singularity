import { createPublicClient, http } from "viem";
import { mainnet } from "viem/chains";

const cyberia = { ...mainnet, id: 49406 };
const pub = createPublicClient({ chain: cyberia as any, transport: http("https://rpc.cyberia.church") });

const hash = "0x03f6afb49219a65ebb539a58bd47e8f22593b9b5748f067f19d9c9d2525d64ef" as `0x${string}`;
const tx = await pub.getTransaction({ hash });
console.log("tx.from:", tx.from);
console.log("tx.to:", tx.to);

// try debug_traceTransaction
try {
  const trace = await pub.request({ method: "debug_traceTransaction" as any, params: [hash, { tracer: "callTracer" }] as any });
  console.log(JSON.stringify(trace, null, 2));
} catch (e: any) {
  console.error("debug_traceTransaction failed:", e.message);
}
