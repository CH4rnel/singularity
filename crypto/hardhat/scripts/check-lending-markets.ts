import "dotenv/config";
import { createPublicClient, http, formatUnits } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";

// Read-only preflight for add-lending-markets.ts. Resolves how the lending
// oracle will price each token (healthy WCYBER pair / dust pair / fallback),
// flags markets already listed, inspects the operator-supplied pairs and checks
// the deployer owns the comptroller + oracle. Broadcasts nothing.

const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};
const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";
const pub = createPublicClient({ chain, transport: http(RPC_URL) });

const WCYBER = "0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9" as const;
const FACTORY = "0xB0aC30907c04b61F1482e62eA66eF4562a690917" as const;
const ORACLE = "0x8fEA279fb70D3D1B20a0E50cbC649c83C41Dc4D1" as const;
const COMPTROLLER = "0xe66aa9842dc74F1c10ede19cA20Ece6E08F1CC88" as const;

// Below this much WCYBER on the priced side, a pool's ratio is meaningless
// (1-wei dust) — the same guard the analytics walker uses.
const DUST_WCYBER = 0.05;

const TOKENS: Record<string, `0x${string}`> = {
  USDC: "0xdc25597B19799010047F17e9591EFE08EFd40077",
  USDT: "0x94845aF24a3E431593A2b941b2b31836dE45185D",
  BTC: "0x9332081f308BC978fe259237850fA253131b46Fa",
  LTC: "0x001AFD19C9d890b0cf0fcd6D654f9BFe4f264F14",
  SOL: "0x53450B1d205f1e41d10B653FBBDEa74160dafFf4",
  RUB: "0x3cE7d8E486E16baD2Fb1487Fe1da4dC33237d923",
  SILVER: "0xAd9dfef9D671aFCF29Dbdd7Df360E7cA8D5ac40b",
  TRUR: "0x6D056e56f5D90ed5680f0335D80E112799a735C8",
  TGLD: "0xE2A45069C3e7897CAB592bEd389764e6eCf3b8a5",
  TMOS: "0x3352254390526624a140B06E7D2dDA8BA85a9E89",
  TOFZ: "0x46A6f512885De25AaefBf5A5F842ba378700Fe22",
  GOLD: "0x38297140d60B48f746aD83D851b852Fd23eF9871",
  XMR: "0xe2E8D51C18d6e0FDDbb9Ff4BF63235D688dd00Ae",
  TRX: "0x60617237bC60f73c0393c7a6d7352e16DF20472a",
  KRSQ: "0x4945419ccEEF0Dc70B054700DE2750A056B03eE3",
  YTN: "0x3a5820Be90c3fB9c5F3Fb47a4859544193B0f8C6",
};

// Operator-supplied pairs for the tokens with no direct WCYBER pool.
const PAIRS: Record<string, `0x${string}`> = {
  SOL: "0xBc9cbe6B1876480D094221eb32C9887df4E62ea6",
  TRX: "0xb6184a51c0faa2810d4a8eb8c25bb18cb0bd4e33",
  KRSQ: "0x828b0c5d46cfdf4adc78e2cb4139547aca56845c",
  YTN: "0xda176fed5d6d1e1eb8c37556a01678f9e18b941f",
};

const ERC20 = [
  { type: "function", name: "decimals", stateMutability: "view", inputs: [], outputs: [{ type: "uint8" }] },
  { type: "function", name: "symbol", stateMutability: "view", inputs: [], outputs: [{ type: "string" }] },
] as const;
const FACTORY_ABI = [
  { type: "function", name: "getPair", stateMutability: "view", inputs: [{ type: "address" }, { type: "address" }], outputs: [{ type: "address" }] },
] as const;
const PAIR_ABI = [
  { type: "function", name: "token0", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
  { type: "function", name: "token1", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
  { type: "function", name: "getReserves", stateMutability: "view", inputs: [], outputs: [{ type: "uint112" }, { type: "uint112" }, { type: "uint32" }] },
] as const;
const COMPTROLLER_ABI = [
  { type: "function", name: "getAllMarkets", stateMutability: "view", inputs: [], outputs: [{ type: "address[]" }] },
] as const;
const MARKET_ABI = [
  { type: "function", name: "underlying", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
] as const;
const OWNABLE_ABI = [
  { type: "function", name: "owner", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
] as const;
const ORACLE_ABI = [
  { type: "function", name: "fallbackPrice", stateMutability: "view", inputs: [{ type: "address" }], outputs: [{ type: "uint256" }] },
] as const;

const ZERO = "0x0000000000000000000000000000000000000000";
const eq = (a: string, b: string) => a.toLowerCase() === b.toLowerCase();

const dec = (a: `0x${string}`) =>
  pub.readContract({ address: a, abi: ERC20, functionName: "decimals" }) as Promise<number>;
const sym = async (a: `0x${string}`) => {
  try {
    return (await pub.readContract({ address: a, abi: ERC20, functionName: "symbol" })) as string;
  } catch {
    return a.slice(0, 8);
  }
};

async function inspectPair(pair: `0x${string}`) {
  const [t0, t1, reserves] = await Promise.all([
    pub.readContract({ address: pair, abi: PAIR_ABI, functionName: "token0" }) as Promise<`0x${string}`>,
    pub.readContract({ address: pair, abi: PAIR_ABI, functionName: "token1" }) as Promise<`0x${string}`>,
    pub.readContract({ address: pair, abi: PAIR_ABI, functionName: "getReserves" }) as Promise<[bigint, bigint, number]>,
  ]);
  const [s0, s1, d0, d1] = await Promise.all([sym(t0), sym(t1), dec(t0), dec(t1)]);
  return { t0, t1, s0, s1, d0, d1, r0: reserves[0], r1: reserves[1] };
}

async function main() {
  console.log("RPC:", RPC_URL);

  // Ownership.
  const [oracleOwner, comptrollerOwner] = await Promise.all([
    pub.readContract({ address: ORACLE, abi: OWNABLE_ABI, functionName: "owner" }) as Promise<string>,
    pub.readContract({ address: COMPTROLLER, abi: OWNABLE_ABI, functionName: "owner" }) as Promise<string>,
  ]);
  console.log("Oracle owner:     ", oracleOwner);
  console.log("Comptroller owner:", comptrollerOwner);
  if (process.env.DEPLOYER_PK) {
    const pk = (process.env.DEPLOYER_PK.startsWith("0x") ? process.env.DEPLOYER_PK : `0x${process.env.DEPLOYER_PK}`) as `0x${string}`;
    const me = privateKeyToAccount(pk).address;
    console.log("Deployer (DEPLOYER_PK):", me,
      eq(me, oracleOwner) && eq(me, comptrollerOwner) ? "✓ owns oracle+comptroller" : "✗ NOT owner — txs would revert");
  } else {
    console.log("DEPLOYER_PK not set — skipping ownership match.");
  }

  // Already-listed underlyings.
  const markets = (await pub.readContract({ address: COMPTROLLER, abi: COMPTROLLER_ABI, functionName: "getAllMarkets" })) as `0x${string}`[];
  const listed = new Set<string>();
  for (const m of markets) {
    const u = (await pub.readContract({ address: m, abi: MARKET_ABI, functionName: "underlying" })) as string;
    listed.add(u.toLowerCase());
  }
  console.log(`\nExisting markets: ${markets.length}\n`);

  console.log("Inspecting operator-supplied pairs:");
  for (const [name, pair] of Object.entries(PAIRS)) {
    try {
      const p = await inspectPair(pair);
      const rW =
        eq(p.t0, WCYBER) ? Number(formatUnits(p.r0, 18)) :
        eq(p.t1, WCYBER) ? Number(formatUnits(p.r1, 18)) : null;
      console.log(
        `  ${name.padEnd(5)} ${pair}  ${p.s0}/${p.s1}  ` +
        `r0=${formatUnits(p.r0, p.d0)} r1=${formatUnits(p.r1, p.d1)}` +
        (rW === null ? "  (not a WCYBER pair)" : `  WCYBER side=${rW}`),
      );
    } catch (e) {
      console.log(`  ${name.padEnd(5)} ${pair}  FAILED: ${(e as Error).message}`);
    }
  }

  console.log("\nPer-token oracle resolution:");
  for (const [name, addr] of Object.entries(TOKENS)) {
    const d = await dec(addr).catch(() => -1);
    const already = listed.has(addr.toLowerCase());
    const wpair = (await pub.readContract({ address: FACTORY, abi: FACTORY_ABI, functionName: "getPair", args: [addr, WCYBER] })) as string;
    const fb = (await pub.readContract({ address: ORACLE, abi: ORACLE_ABI, functionName: "fallbackPrice", args: [addr] })) as bigint;

    let source = "FALLBACK";
    let detail = fb > 0n ? `fb=${formatUnits(fb, 18)} WCYBER/token` : "NO fallback → price 0";
    if (!eq(wpair, ZERO)) {
      const r = (await pub.readContract({ address: wpair as `0x${string}`, abi: PAIR_ABI, functionName: "getReserves" })) as [bigint, bigint, number];
      const t0 = (await pub.readContract({ address: wpair as `0x${string}`, abi: PAIR_ABI, functionName: "token0" })) as string;
      const wRaw = eq(t0, WCYBER) ? r[0] : r[1];
      const wWhole = Number(formatUnits(wRaw, 18));
      const tokRaw = eq(t0, WCYBER) ? r[1] : r[0];
      const tokWhole = Number(formatUnits(tokRaw, d < 0 ? 18 : d));
      const price = tokWhole > 0 ? wWhole / tokWhole : 0;
      const dust = wWhole < DUST_WCYBER;
      source = dust ? "DUST-PAIR ⚠" : "WCYBER-PAIR";
      detail = `pair=${wpair} WCYBERside=${wWhole} price≈${price} WCYBER/token`;
    }

    console.log(
      `  ${name.padEnd(6)} dec=${String(d).padEnd(2)} ${already ? "[LISTED, skip]" : "[add]      "} ${source.padEnd(13)} ${detail}`,
    );
  }
}

main().catch((e) => { console.error(e); process.exit(1); });
