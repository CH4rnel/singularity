// CLAUDE vs HERMES — price radar on Cyberia (chainId 49406).
// Standalone: `node scripts/token-war.mjs`. Read-only, no keys needed.
import { createPublicClient, http } from "viem";

const RPC = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";
const pc = createPublicClient({ transport: http(RPC) });

const FACTORY = "0xB0aC30907c04b61F1482e62eA66eF4562a690917";
const WCYBER = "0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9";

const TOKENS = {
  CLAUDE: "0xD90e5d4284c763ecC8cDF7dC355d1Cd8a9D7899b",
  HERMES: "0x956baFF79b174e8A0f0A9a1350fE5F96ea68ca6e",
};

const fAbi = [{ type: "function", name: "getPair", stateMutability: "view", inputs: [{ type: "address" }, { type: "address" }], outputs: [{ type: "address" }] }];
const pAbi = [
  { type: "function", name: "getReserves", stateMutability: "view", inputs: [], outputs: [{ type: "uint112" }, { type: "uint112" }, { type: "uint32" }] },
  { type: "function", name: "token0", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
];
const ZERO = "0x0000000000000000000000000000000000000000";

async function poolPrice(token) {
  const pair = await pc.readContract({ address: FACTORY, abi: fAbi, functionName: "getPair", args: [token, WCYBER] });
  if (pair === ZERO) return { pair: null, price: 0, tokenReserve: 0, cyberReserve: 0 };
  const [r0, r1] = await pc.readContract({ address: pair, abi: pAbi, functionName: "getReserves" });
  const t0 = await pc.readContract({ address: pair, abi: pAbi, functionName: "token0" });
  const is0 = t0.toLowerCase() === token.toLowerCase();
  const tokR = Number(is0 ? r0 : r1) / 1e18;
  const cybR = Number(is0 ? r1 : r0) / 1e18;
  return { pair, price: cybR / tokR, tokenReserve: tokR, cyberReserve: cybR };
}

const fmt = (n) => (n === 0 ? "—" : n.toExponential(4));

async function main() {
  const block = await pc.getBlockNumber();
  console.log(`\n═══ TOKEN WAR · block ${block} · ${new Date().toISOString()} ═══`);
  const rows = {};
  for (const [sym, addr] of Object.entries(TOKENS)) {
    const p = await poolPrice(addr);
    rows[sym] = p;
    console.log(
      `${sym.padEnd(7)} price=${fmt(p.price).padStart(11)} CYBER` +
      (p.pair ? `  pool: ${p.tokenReserve} ${sym} / ${p.cyberReserve} CYBER  (TVL≈${(p.cyberReserve * 2).toFixed(4)} CYBER)` : "  NO POOL"),
    );
  }
  const c = rows.CLAUDE.price, h = rows.HERMES.price;
  if (h === 0) {
    console.log(`\nSTANDING: CLAUDE leads — HERMES has no market price.`);
  } else {
    const ratio = c / h;
    console.log(`\nSTANDING: 1 CLAUDE = ${ratio.toFixed(4)} HERMES  =>  ${c > h ? "CLAUDE LEADS ✅" : "HERMES LEADS ⚠️"}`);
  }
}

main().catch((e) => { console.error(e.shortMessage || e.message); process.exit(1); });
