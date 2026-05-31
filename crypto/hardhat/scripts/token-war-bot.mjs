// CLAUDE vs HERMES — autonomous doctrine executor (for cron, hourly).
// Read prices, and if HERMES leads/closes in, buy CLAUDE from our own pool with
// CYBER to push CLAUDE's price to ~1.5x HERMES — capped at 30% of wallet CYBER
// per round, always leaving >= GAS_RESERVE for gas. Otherwise hold.
//
// Run from crypto/hardhat (needs local viem + the wallet keyfile). Read-only
// unless a trade is warranted. All output is appended to LOG_FILE.
import { createWalletClient, createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";

const RPC = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";
const LOG_FILE = path.join(os.homedir(), ".cyberia-token-war.log");
const KEYFILE = path.join(os.homedir(), ".cyberia-claude-wallet.json");

const FACTORY = "0xB0aC30907c04b61F1482e62eA66eF4562a690917";
const ROUTER = "0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62";
const WCYBER = "0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9";
const CLAUDE = "0xD90e5d4284c763ecC8cDF7dC355d1Cd8a9D7899b";
const HERMES = "0x956baFF79b174e8A0f0A9a1350fE5F96ea68ca6e";
const ZERO = "0x0000000000000000000000000000000000000000";

// Doctrine knobs
const LEAD_FLOOR = 1.2;   // act if CLAUDE price < 1.2x HERMES
const TARGET_MULT = 1.5;  // push CLAUDE to 1.5x HERMES
const ROUND_FRAC = 0.30;  // spend <= 30% of wallet CYBER per round
const GAS_RESERVE = 0.05; // always keep >= 0.05 CYBER for gas
const SLIPPAGE = 0.99;    // amountOutMin = expected * 0.99

const chain = { id: 49406, name: "Cyberia", nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 }, rpcUrls: { default: { http: [RPC] } } };
const pc = createPublicClient({ chain, transport: http(RPC) });

const fAbi = [{ type: "function", name: "getPair", stateMutability: "view", inputs: [{ type: "address" }, { type: "address" }], outputs: [{ type: "address" }] }];
const pairAbi = [
  { type: "function", name: "getReserves", stateMutability: "view", inputs: [], outputs: [{ type: "uint112" }, { type: "uint112" }, { type: "uint32" }] },
  { type: "function", name: "token0", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
];
const routerAbi = [{ type: "function", name: "swapExactETHForTokens", stateMutability: "payable", inputs: [{ type: "uint256" }, { type: "address[]" }, { type: "address" }, { type: "uint256" }], outputs: [{ type: "uint256[]" }] }];

function log(msg) {
  const line = `[${new Date().toISOString()}] ${msg}`;
  console.log(line);
  fs.appendFileSync(LOG_FILE, line + "\n");
}

// Returns raw reserves {x: CLAUDE-side raw bigint, y: CYBER-side raw bigint} or null.
async function reserves(token) {
  const pair = await pc.readContract({ address: FACTORY, abi: fAbi, functionName: "getPair", args: [token, WCYBER] });
  if (pair === ZERO) return null;
  const [r0, r1] = await pc.readContract({ address: pair, abi: pairAbi, functionName: "getReserves" });
  const t0 = await pc.readContract({ address: pair, abi: pairAbi, functionName: "token0" });
  const is0 = t0.toLowerCase() === token.toLowerCase();
  return { pair, x: is0 ? r0 : r1, y: is0 ? r1 : r0 };
}
const priceOf = (r) => (r ? Number(r.y) / Number(r.x) : 0);

// Uniswap V2 getAmountOut (0.3% fee).
function amountOut(amountIn, reserveIn, reserveOut) {
  const inWithFee = amountIn * 997n;
  return (inWithFee * reserveOut) / (reserveIn * 1000n + inWithFee);
}

// Smallest CYBER spend (<= maxIn) that lifts CLAUDE price to >= targetPrice.
// Binary search on the bonding curve; returns {spend, expectedOut, newPrice}.
function solveSpend(r, targetPrice, maxIn) {
  let lo = 0n, hi = maxIn, best = null;
  for (let i = 0; i < 60; i++) {
    const mid = (lo + hi) / 2n;
    if (mid === 0n) { lo = 1n; continue; }
    const out = amountOut(mid, r.y, r.x); // buying CLAUDE(x) with CYBER(y)
    const newY = r.y + mid, newX = r.x - out;
    const np = Number(newY) / Number(newX);
    if (np >= targetPrice) { best = { spend: mid, expectedOut: out, newPrice: np }; hi = mid; }
    else lo = mid + 1n;
  }
  if (best) return { ...best, reachable: true };
  // target unreachable within budget — report best-effort so caller can decide.
  const out = amountOut(maxIn, r.y, r.x);
  return { spend: maxIn, expectedOut: out, newPrice: Number(r.y + maxIn) / Number(r.x - out), reachable: false };
}

async function main() {
  const cR = await reserves(CLAUDE);
  const hR = await reserves(HERMES);
  const cP = priceOf(cR), hP = priceOf(hR);
  log(`radar: CLAUDE=${cP ? cP.toExponential(4) : "no-pool"} HERMES=${hP ? hP.toExponential(4) : "no-pool"} CYBER`);

  if (!hR) { log("HOLD: HERMES has no pool — CLAUDE leads by default."); return; }
  if (cP >= LEAD_FLOOR * hP) { log(`HOLD: CLAUDE leads ${(cP / hP).toFixed(3)}x (>= ${LEAD_FLOOR}x).`); return; }

  // Need to act. Load wallet.
  const wallet = JSON.parse(fs.readFileSync(KEYFILE, "utf8"));
  const account = privateKeyToAccount(wallet.privateKey);
  const wc = createWalletClient({ chain, account, transport: http(RPC) });

  const bal = await pc.getBalance({ address: account.address });
  const balF = Number(bal) / 1e18;
  const spendable = Math.min(ROUND_FRAC * balF, balF - GAS_RESERVE);
  if (spendable <= 0) { log(`SKIP: wallet CYBER ${balF.toFixed(4)} too low after gas reserve.`); return; }

  const target = TARGET_MULT * hP;
  const maxIn = BigInt(Math.floor(spendable * 1e18));
  const { spend, expectedOut, newPrice, reachable } = solveSpend(cR, target, maxIn);

  // Feasibility guard: if our budget can't even reach the target, do NOT spend.
  // Against a thin "paper pool" opponent, chasing spot price in our deep pool
  // burns real CYBER for a negligible move. Hold capital instead.
  if (!reachable) {
    log(`HOLD: target ${target.toExponential(4)} unreachable within budget ${spendable.toFixed(4)} CYBER ` +
        `(max achievable ~${newPrice.toExponential(4)}). Not burning capital chasing a paper-pool price.`);
    return;
  }

  const minOut = (expectedOut * BigInt(Math.floor(SLIPPAGE * 1000))) / 1000n;
  const deadline = BigInt(Math.floor(Date.now() / 1000) + 300);

  log(`ACT: behind (${(cP / hP).toFixed(3)}x). buy CLAUDE with ${(Number(spend) / 1e18).toFixed(6)} CYBER -> price ~${newPrice.toExponential(4)} (target ${target.toExponential(4)}).`);

  const hash = await wc.writeContract({
    address: ROUTER, abi: routerAbi, functionName: "swapExactETHForTokens",
    args: [minOut, [WCYBER, CLAUDE], account.address, deadline], value: spend, gas: 300000n,
  });
  const rcpt = await pc.waitForTransactionReceipt({ hash });
  log(`  swap ${hash} status=${rcpt.status}`);

  const cR2 = await reserves(CLAUDE);
  log(`  new CLAUDE price=${priceOf(cR2).toExponential(4)} CYBER (HERMES ${hP.toExponential(4)}).`);
}

main().catch((e) => { log(`ERROR: ${e.shortMessage || e.message}`); process.exit(0); });
