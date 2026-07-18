#!/usr/bin/env -S npx tsx
import { existsSync, readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { config } from "dotenv";
import {
  createPublicClient,
  formatEther,
  formatUnits,
  http,
  isAddress,
  parseAbi,
  type Address,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { CYBERIA_TOKENS, cyberiaChain, RITUAL_V2 } from "../src/plugins/cyberia/index.js";

type JournalFile = {
  trades?: unknown[];
  positions?: Record<string, {
    token: string;
    symbol: string;
    qtyWei: string;
    costWei: string;
  }>;
};

type BasisPosition = {
  token: Address;
  symbol: string;
  qtyWei: bigint;
  costWei: bigint;
  source: "journal" | "onchain";
};

type ExplorerTransaction = {
  hash: string;
  from?: { hash?: string };
  to?: { hash?: string };
  value?: string;
  fee?: { value?: string } | string;
  method?: string | null;
  status?: string;
};

type ExplorerTransfer = {
  transaction_hash?: string;
  from?: { hash?: string; name?: string | null };
  to?: { hash?: string };
  token?: { address_hash?: string; symbol?: string };
  total?: { value?: string; decimals?: string };
};

const projectRoot = dirname(dirname(fileURLToPath(import.meta.url)));
config({ path: join(projectRoot, ".env") });

const erc20Abi = parseAbi([
  "function balanceOf(address owner) view returns (uint256)",
  "function decimals() view returns (uint8)",
  "function symbol() view returns (string)",
]);

const factoryAbi = parseAbi([
  "function getPair(address tokenA, address tokenB) view returns (address)",
]);

const pairAbi = parseAbi([
  "function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32 blockTimestampLast)",
  "function token0() view returns (address)",
]);

function arg(name: string): string | undefined {
  const prefix = `--${name}=`;
  return process.argv.find((item) => item.startsWith(prefix))?.slice(prefix.length);
}

function sameAddress(a: string, b: string): boolean {
  return a.toLowerCase() === b.toLowerCase();
}

function getAmountOut(amountIn: bigint, reserveIn: bigint, reserveOut: bigint): bigint {
  if (amountIn <= 0n || reserveIn <= 0n || reserveOut <= 0n) return 0n;
  const amountInWithFee = amountIn * 997n;
  return (amountInWithFee * reserveOut) / (reserveIn * 1000n + amountInWithFee);
}

function formatWei(value: bigint, decimals = 12): string {
  const text = formatEther(value);
  if (!text.includes(".")) return text;
  const [whole, frac] = text.split(".");
  const cut = frac.slice(0, decimals).replace(/0+$/, "");
  return cut ? `${whole}.${cut}` : whole;
}

function formatToken(value: bigint, decimals: number): string {
  const text = formatUnits(value, decimals);
  if (!text.includes(".")) return text;
  const [whole, frac] = text.split(".");
  const cut = frac.slice(0, 12).replace(/0+$/, "");
  return cut ? `${whole}.${cut}` : whole;
}

function formatPct(valueWei: bigint, basisWei: bigint): string {
  if (basisWei === 0n) return "n/a";
  const scaled = Number(((valueWei - basisWei) * 1_000_000n) / basisWei) / 10_000;
  return `${scaled >= 0 ? "+" : ""}${scaled.toFixed(4).replace(/0+$/, "").replace(/\.$/, "")}%`;
}

function readJson<T>(file: string): T | null {
  try {
    return JSON.parse(readFileSync(file, "utf8")) as T;
  } catch {
    return null;
  }
}

function resolveDataDir(): string {
  return resolve(projectRoot, process.env.LAINOS_DATA_DIR ?? "data");
}

function resolveAddress(dataDir: string): { address: Address; source: string } {
  const requested = arg("address") ?? process.env.LAINOS_ADDRESS;
  if (requested && isAddress(requested)) return { address: requested as Address, source: "argument" };

  const pk = process.env.CYBERIA_AGENT_PK;
  if (pk && /^0x[0-9a-fA-F]{64}$/.test(pk)) {
    return { address: privateKeyToAccount(pk as `0x${string}`).address, source: "CYBERIA_AGENT_PK" };
  }

  const wallet = readJson<{ address?: string }>(join(dataDir, "wallet.json"));
  if (wallet?.address && isAddress(wallet.address)) {
    return { address: wallet.address as Address, source: "data/wallet.json" };
  }

  throw new Error("No wallet address found. Use --address=0x... or create/fund LainOS wallet first.");
}

async function explorerItems<T>(path: string): Promise<T[]> {
  const base = process.env.CYBERIA_EXPLORER_API_URL ?? "https://explorer.cyberia.church/api/v2";
  const out: T[] = [];
  let params: Record<string, string> | null = {};

  while (params) {
    const url = new URL(`${base}${path}`);
    for (const [key, value] of Object.entries(params)) url.searchParams.set(key, value);
    const res = await fetch(url);
    if (!res.ok) throw new Error(`Explorer ${path} failed: HTTP ${res.status}`);
    const body = (await res.json()) as { items?: T[]; next_page_params?: Record<string, unknown> | null };
    out.push(...(body.items ?? []));
    params = body.next_page_params
      ? Object.fromEntries(Object.entries(body.next_page_params).map(([key, value]) => [key, String(value)]))
      : null;
  }

  return out;
}

function mergeBasis(target: Map<string, BasisPosition>, input: BasisPosition): void {
  const key = input.token.toLowerCase();
  const prev = target.get(key);
  if (!prev) {
    target.set(key, input);
    return;
  }
  target.set(key, {
    ...prev,
    qtyWei: prev.qtyWei + input.qtyWei,
    costWei: prev.costWei + input.costWei,
  });
}

async function reconstructedBasis(address: Address): Promise<{ positions: Map<string, BasisPosition>; feesWei: bigint }> {
  const [transactions, transfers] = await Promise.all([
    explorerItems<ExplorerTransaction>(`/addresses/${address}/transactions`),
    explorerItems<ExplorerTransfer>(`/addresses/${address}/token-transfers`),
  ]);

  const txByHash = new Map(transactions.map((tx) => [tx.hash.toLowerCase(), tx]));
  const positions = new Map<string, BasisPosition>();
  let feesWei = 0n;

  for (const transfer of transfers) {
    const hash = transfer.transaction_hash?.toLowerCase();
    const token = transfer.token?.address_hash;
    const qty = transfer.total?.value;
    if (!hash || !token || !isAddress(token) || !qty) continue;
    if (!sameAddress(transfer.to?.hash ?? "", address)) continue;

    const tx = txByHash.get(hash);
    if (!tx || tx.status !== "ok") continue;
    if (!sameAddress(tx.from?.hash ?? "", address)) continue;
    if (!sameAddress(tx.to?.hash ?? "", RITUAL_V2.router)) continue;
    if ((tx.method ?? "").toLowerCase() !== "0x7ff36ab5") continue; // swapExactETHForTokens

    const costWei = BigInt(tx.value ?? "0");
    if (costWei <= 0n) continue;
    const feeValue = typeof tx.fee === "string" ? tx.fee : tx.fee?.value;
    feesWei += BigInt(feeValue ?? "0");
    mergeBasis(positions, {
      token: token as Address,
      symbol: transfer.token?.symbol ?? "TOKEN",
      qtyWei: BigInt(qty),
      costWei,
      source: "onchain",
    });
  }

  return { positions, feesWei };
}

async function sellSideValue(client: ReturnType<typeof createPublicClient>, token: Address, amountInWei: bigint): Promise<bigint> {
  const pair = await client.readContract({
    address: RITUAL_V2.factory,
    abi: factoryAbi,
    functionName: "getPair",
    args: [RITUAL_V2.wrappedNative, token],
  });
  if (sameAddress(pair, "0x0000000000000000000000000000000000000000")) return 0n;

  const [[reserve0, reserve1], token0] = await Promise.all([
    client.readContract({ address: pair, abi: pairAbi, functionName: "getReserves" }),
    client.readContract({ address: pair, abi: pairAbi, functionName: "token0" }),
  ]);
  const nativeIs0 = sameAddress(token0, RITUAL_V2.wrappedNative);
  return getAmountOut(amountInWei, nativeIs0 ? reserve1 : reserve0, nativeIs0 ? reserve0 : reserve1);
}

async function main() {
  const dataDir = resolveDataDir();
  const { address, source } = resolveAddress(dataDir);
  const rpc = process.env.CYBERIA_RPC_URL ?? cyberiaChain.rpcUrls.default.http[0];
  const client = createPublicClient({ chain: cyberiaChain, transport: http(rpc) });

  const journalFile = join(dataDir, "trades.json");
  const journal = existsSync(journalFile) ? readJson<JournalFile>(journalFile) : null;
  const basis = new Map<string, BasisPosition>();

  for (const pos of Object.values(journal?.positions ?? {})) {
    if (!isAddress(pos.token) || BigInt(pos.qtyWei) <= 0n) continue;
    basis.set(pos.token.toLowerCase(), {
      token: pos.token as Address,
      symbol: pos.symbol,
      qtyWei: BigInt(pos.qtyWei),
      costWei: BigInt(pos.costWei),
      source: "journal",
    });
  }

  const onchain = await reconstructedBasis(address);
  for (const pos of onchain.positions.values()) {
    if (!basis.has(pos.token.toLowerCase())) basis.set(pos.token.toLowerCase(), pos);
  }

  const tokens = new Map<string, Address>();
  for (const token of Object.values(CYBERIA_TOKENS)) tokens.set(token.toLowerCase(), token);
  for (const pos of basis.values()) tokens.set(pos.token.toLowerCase(), pos.token);

  const rows = [];
  let totalValueWei = 0n;
  let totalBasisWei = 0n;
  let unknownBasis = 0;

  for (const token of tokens.values()) {
    if (sameAddress(token, RITUAL_V2.wrappedNative)) continue;
    try {
      const [raw, decimals, symbol] = await Promise.all([
        client.readContract({ address: token, abi: erc20Abi, functionName: "balanceOf", args: [address] }),
        client.readContract({ address: token, abi: erc20Abi, functionName: "decimals" }),
        client.readContract({ address: token, abi: erc20Abi, functionName: "symbol" }).catch(() => "TOKEN"),
      ]);
      if (raw <= 0n) continue;

      const valueWei = await sellSideValue(client, token, raw).catch(() => 0n);
      const pos = basis.get(token.toLowerCase());
      let basisWei: bigint | null = null;
      if (pos && pos.qtyWei > 0n) {
        const covered = raw < pos.qtyWei ? raw : pos.qtyWei;
        basisWei = (pos.costWei * covered) / pos.qtyWei;
        totalBasisWei += basisWei;
      } else {
        unknownBasis += 1;
      }
      totalValueWei += valueWei;

      rows.push({
        symbol,
        token,
        amount: formatToken(raw, Number(decimals)),
        valueCyber: formatWei(valueWei),
        basisCyber: basisWei === null ? null : formatWei(basisWei),
        unrealizedCyber: basisWei === null ? null : formatWei(valueWei - basisWei),
        unrealizedPct: basisWei === null ? null : formatPct(valueWei, basisWei),
        basisSource: pos?.source ?? null,
      });
    } catch {
      // Skip unreadable registry entries; they should not block a treasury read.
    }
  }

  rows.sort((a, b) => Number(b.valueCyber) - Number(a.valueCyber));
  const nativeWei = await client.getBalance({ address });
  const unrealizedWei = totalBasisWei > 0n ? totalValueWei - totalBasisWei : null;
  const afterGasWei = unrealizedWei === null ? null : unrealizedWei - onchain.feesWei;

  console.log(JSON.stringify({
    asOf: new Date().toISOString(),
    address,
    addressSource: source,
    journalFile: existsSync(journalFile) ? journalFile : null,
    nativeCyber: formatWei(nativeWei, 18),
    positions: rows,
    totalBasisCyber: formatWei(totalBasisWei),
    totalPositionValueCyber: formatWei(totalValueWei),
    unrealizedCyber: unrealizedWei === null ? null : formatWei(unrealizedWei),
    unrealizedPct: unrealizedWei === null ? null : formatPct(totalValueWei, totalBasisWei),
    reconstructedGasFeesCyber: formatWei(onchain.feesWei),
    unrealizedAfterGasCyber: afterGasWei === null ? null : formatWei(afterGasWei),
    positionsWithoutBasis: unknownBasis,
  }, null, 2));
}

main().catch((err) => {
  console.error(`portfolio pnl failed: ${(err as Error).message}`);
  process.exit(1);
});
