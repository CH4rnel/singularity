import "dotenv/config";
import fs from "node:fs";
import path from "node:path";
import {
  createPublicClient,
  createWalletClient,
  formatEther,
  formatUnits,
  http,
  isAddress,
  parseEther,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";

const DEFAULT_CONFIG = "scripts/dca-buy.json";
const DEFAULT_RPC = "https://rpc.cyberia.church";
const DEFAULT_FACTORY = "0xB0aC30907c04b61F1482e62eA66eF4562a690917";
const DEFAULT_ROUTER = "0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62";
const DEFAULT_WCYBER = "0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9";
const DEFAULT_BLOCKED_TOKEN_SYMBOLS = new Set(["ASH"]);
const DEFAULT_BLOCKED_TOKEN_ADDRESSES = new Set([
  "0x992fca0a89dd95afb17751f6cc233adb9b089df5",
  "0xd199e6ae74b992f017f8940b26fa18a7dd30ee86",
]);
const DEFAULT_STABLE_TOKEN_SYMBOLS = new Set(["USDC", "USDT"]);
const DEFAULT_STABLE_TOKEN_ADDRESSES = new Set([
  "0xdc25597b19799010047f17e9591efe08efd40077",
  "0x94845af24a3e431593a2b941b2b31836de45185d",
]);

const chain = {
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
  rpcUrls: { default: { http: [DEFAULT_RPC] } },
};

const routerAbi = [
  {
    type: "function",
    name: "getAmountsOut",
    stateMutability: "view",
    inputs: [
      { type: "uint256", name: "amountIn" },
      { type: "address[]", name: "path" },
    ],
    outputs: [{ type: "uint256[]", name: "amounts" }],
  },
  {
    type: "function",
    name: "swapExactETHForTokens",
    stateMutability: "payable",
    inputs: [
      { type: "uint256", name: "amountOutMin" },
      { type: "address[]", name: "path" },
      { type: "address", name: "to" },
      { type: "uint256", name: "deadline" },
    ],
    outputs: [{ type: "uint256[]", name: "amounts" }],
  },
];

const factoryAbi = [
  {
    type: "function",
    name: "allPairsLength",
    stateMutability: "view",
    inputs: [],
    outputs: [{ type: "uint256" }],
  },
  {
    type: "function",
    name: "allPairs",
    stateMutability: "view",
    inputs: [{ type: "uint256" }],
    outputs: [{ type: "address" }],
  },
];

const pairAbi = [
  { type: "function", name: "token0", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
  { type: "function", name: "token1", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
  {
    type: "function",
    name: "getReserves",
    stateMutability: "view",
    inputs: [],
    outputs: [
      { type: "uint112", name: "reserve0" },
      { type: "uint112", name: "reserve1" },
      { type: "uint32", name: "blockTimestampLast" },
    ],
  },
];

const erc20Abi = [
  { type: "function", name: "symbol", stateMutability: "view", inputs: [], outputs: [{ type: "string" }] },
  { type: "function", name: "decimals", stateMutability: "view", inputs: [], outputs: [{ type: "uint8" }] },
];

function printHelp() {
  console.log(`Usage:
  node scripts/dca-buy-bot.mjs [--config scripts/dca-buy.json] [--once] [--execute]

Options:
  --config <path>  JSON config path. Defaults to ${DEFAULT_CONFIG}
  --once           Run one buying cycle and exit. Useful for cron/systemd timers.
  --execute        Send transactions. Without this flag the bot is dry-run only.
  --help           Show this help.

Routing:
  Each cycle the bot reads every factory pair, builds a liquidity graph and picks
  the best WCYBER -> token path (up to "maxHops" hops, default 4). This lets it buy
  tokens that have no direct WCYBER pool but are reachable through hubs. Set a
  per-token "path" array (full address list starting with WCYBER) to pin a route.
  A config with no enabled tokens is valid and will not buy anything.

Safety:
  ASH is blocked by default and cannot be bought by this bot.

Private key:
  Set DCA_PRIVATE_KEY or DCA_WALLET_KEYFILE. DEPLOYER_PK is intentionally ignored
  so this bot cannot spend bridge/deployer funds by accident.
  DCA_WALLET_KEYFILE can contain either {"privateKey":"0x..."} or a raw key string.
`);
}

function parseArgs(argv) {
  const args = {
    configPath: DEFAULT_CONFIG,
    once: false,
    execute: false,
  };

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === "--help" || arg === "-h") {
      printHelp();
      process.exit(0);
    }
    if (arg === "--once") {
      args.once = true;
      continue;
    }
    if (arg === "--execute") {
      args.execute = true;
      continue;
    }
    if (arg === "--config") {
      const value = argv[++i];
      if (!value) throw new Error("--config requires a path");
      args.configPath = value;
      continue;
    }
    throw new Error(`Unknown argument: ${arg}`);
  }

  return args;
}

function loadConfig(configPath) {
  if (!fs.existsSync(configPath)) {
    throw new Error(`Config not found: ${configPath}. Copy scripts/dca-buy.example.json first.`);
  }

  const config = JSON.parse(fs.readFileSync(configPath, "utf8"));
  const normalized = {
    rpcUrl: config.rpcUrl || process.env.CYBERIA_RPC_URL || process.env.RPC_URL || DEFAULT_RPC,
    factory: config.factory || DEFAULT_FACTORY,
    router: config.router || DEFAULT_ROUTER,
    wrappedNative: config.wrappedNative || DEFAULT_WCYBER,
    recipient: config.recipient || "self",
    intervalSeconds: Number(config.intervalSeconds ?? 3600),
    gasReserveCyber: String(config.gasReserveCyber ?? "0.05"),
    slippageBps: Number(config.slippageBps ?? 100),
    deadlineSeconds: Number(config.deadlineSeconds ?? 300),
    maxHops: Number(config.maxHops ?? 4),
    allowStableTargets: config.allowStableTargets === true,
    tokens: Array.isArray(config.tokens) ? config.tokens : [],
  };

  if (!isAddress(normalized.factory)) throw new Error(`Invalid factory address: ${normalized.factory}`);
  if (!isAddress(normalized.router)) throw new Error(`Invalid router address: ${normalized.router}`);
  if (!isAddress(normalized.wrappedNative)) throw new Error(`Invalid wrappedNative address: ${normalized.wrappedNative}`);
  if (normalized.recipient !== "self" && !isAddress(normalized.recipient)) {
    throw new Error(`Invalid recipient address: ${normalized.recipient}`);
  }
  if (!Number.isFinite(normalized.intervalSeconds) || normalized.intervalSeconds <= 0) {
    throw new Error("intervalSeconds must be a positive number");
  }
  if (!Number.isInteger(normalized.slippageBps) || normalized.slippageBps < 0 || normalized.slippageBps > 5000) {
    throw new Error("slippageBps must be an integer between 0 and 5000");
  }
  if (!Number.isFinite(normalized.deadlineSeconds) || normalized.deadlineSeconds <= 0) {
    throw new Error("deadlineSeconds must be a positive number");
  }
  if (!Number.isInteger(normalized.maxHops) || normalized.maxHops < 1 || normalized.maxHops > 5) {
    throw new Error("maxHops must be an integer between 1 and 5");
  }

  const enabled = normalized.tokens.filter((token) => token.enabled !== false);

  for (const token of enabled) {
    if (!isAddress(token.address)) throw new Error(`Invalid token address for ${token.symbol || "unnamed token"}: ${token.address}`);
    const symbol = String(token.symbol || "").trim().toUpperCase();
    if (DEFAULT_BLOCKED_TOKEN_SYMBOLS.has(symbol) || DEFAULT_BLOCKED_TOKEN_ADDRESSES.has(token.address.toLowerCase())) {
      throw new Error(`Token ${token.symbol || token.address} is blocked and will not be bought`);
    }
    if (
      !normalized.allowStableTargets &&
      (DEFAULT_STABLE_TOKEN_SYMBOLS.has(symbol) || DEFAULT_STABLE_TOKEN_ADDRESSES.has(token.address.toLowerCase()))
    ) {
      throw new Error(
        `Token ${token.symbol || token.address} is a stablecoin target. ` +
          "DCA buys spend native CYBER; set allowStableTargets=true only if selling CYBER for stables is intentional."
      );
    }
    if (!token.spendCyber) throw new Error(`Token ${token.symbol || token.address} is missing spendCyber`);
    parseEther(String(token.spendCyber));
    if (token.path !== undefined) {
      if (!Array.isArray(token.path) || token.path.length < 2) {
        throw new Error(`Token ${token.symbol || token.address}: path must be an array of at least 2 addresses`);
      }
      for (const hop of token.path) {
        if (!isAddress(hop)) throw new Error(`Token ${token.symbol || token.address}: invalid path address ${hop}`);
      }
      if (token.path[0].toLowerCase() !== normalized.wrappedNative.toLowerCase()) {
        throw new Error(`Token ${token.symbol || token.address}: path must start with wrappedNative ${normalized.wrappedNative}`);
      }
      if (token.path[token.path.length - 1].toLowerCase() !== token.address.toLowerCase()) {
        throw new Error(`Token ${token.symbol || token.address}: path must end with the token address`);
      }
    }
  }

  return { ...normalized, tokens: enabled };
}

function normalizePrivateKey(raw) {
  const value = String(raw || "").trim();
  if (!value) return null;
  return value.startsWith("0x") ? value : `0x${value}`;
}

function loadPrivateKey() {
  const deployerKey = normalizePrivateKey(process.env.DEPLOYER_PK);
  const envKey = normalizePrivateKey(process.env.DCA_PRIVATE_KEY);
  if (envKey) {
    if (deployerKey && envKey.toLowerCase() === deployerKey.toLowerCase()) {
      throw new Error("DCA_PRIVATE_KEY must be a dedicated key and must not equal DEPLOYER_PK");
    }
    return envKey;
  }

  if (!process.env.DCA_WALLET_KEYFILE) return null;

  const keyfile = path.resolve(process.env.DCA_WALLET_KEYFILE);
  const raw = fs.readFileSync(keyfile, "utf8").trim();
  let key;
  try {
    const parsed = JSON.parse(raw);
    key = normalizePrivateKey(parsed.privateKey);
  } catch {
    key = normalizePrivateKey(raw);
  }
  if (key && deployerKey && key.toLowerCase() === deployerKey.toLowerCase()) {
    throw new Error("DCA_WALLET_KEYFILE must contain a dedicated key and must not equal DEPLOYER_PK");
  }
  return key;
}

function minOutWithSlippage(expectedOut, slippageBps) {
  return (expectedOut * BigInt(10_000 - slippageBps)) / 10_000n;
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function log(message) {
  console.log(`[${new Date().toISOString()}] ${message}`);
}

async function readTokenMeta(publicClient, token) {
  const fallbackSymbol = token.symbol || token.address.slice(0, 8);
  try {
    const [symbol, decimals] = await Promise.all([
      publicClient.readContract({ address: token.address, abi: erc20Abi, functionName: "symbol" }),
      publicClient.readContract({ address: token.address, abi: erc20Abi, functionName: "decimals" }),
    ]);
    return { symbol: token.symbol || symbol, decimals };
  } catch {
    return { symbol: fallbackSymbol, decimals: token.decimals ?? 18 };
  }
}

// Uniswap V2 single-hop output, mirrors UniswapV2Library.getAmountOut (0.3% fee).
function quoteHop(amountIn, reserveIn, reserveOut) {
  if (amountIn <= 0n || reserveIn <= 0n || reserveOut <= 0n) return 0n;
  const inWithFee = amountIn * 997n;
  return (inWithFee * reserveOut) / (reserveIn * 1000n + inWithFee);
}

// Read every factory pair once and build an undirected liquidity graph keyed by
// lowercased token address. Symbols are kept for readable route logs.
async function buildPairGraph(publicClient, config) {
  const length = await publicClient.readContract({
    address: config.factory,
    abi: factoryAbi,
    functionName: "allPairsLength",
  });

  const adjacency = new Map(); // lowerAddr -> [{ to, reserveIn, reserveOut }]
  const checksum = new Map(); // lowerAddr -> checksummed address
  const symbols = new Map(); // lowerAddr -> symbol

  for (let i = 0n; i < length; i++) {
    let pair;
    let token0;
    let token1;
    let reserves;
    try {
      pair = await publicClient.readContract({
        address: config.factory,
        abi: factoryAbi,
        functionName: "allPairs",
        args: [i],
      });
      [token0, token1, reserves] = await Promise.all([
        publicClient.readContract({ address: pair, abi: pairAbi, functionName: "token0" }),
        publicClient.readContract({ address: pair, abi: pairAbi, functionName: "token1" }),
        publicClient.readContract({ address: pair, abi: pairAbi, functionName: "getReserves" }),
      ]);
    } catch (error) {
      log(`WARN skip pair index ${i}: ${error.shortMessage || error.message}`);
      continue;
    }

    const a = token0.toLowerCase();
    const b = token1.toLowerCase();
    checksum.set(a, token0);
    checksum.set(b, token1);
    if (!adjacency.has(a)) adjacency.set(a, []);
    if (!adjacency.has(b)) adjacency.set(b, []);
    adjacency.get(a).push({ to: b, reserveIn: reserves[0], reserveOut: reserves[1] });
    adjacency.get(b).push({ to: a, reserveIn: reserves[1], reserveOut: reserves[0] });
  }

  for (const lower of checksum.keys()) {
    try {
      symbols.set(lower, await publicClient.readContract({ address: checksum.get(lower), abi: erc20Abi, functionName: "symbol" }));
    } catch {
      symbols.set(lower, lower.slice(0, 8));
    }
  }

  return { adjacency, checksum, symbols, pairCount: Number(length) };
}

// Best WCYBER -> token route by output, exploring up to maxHops hops. Returns the
// checksummed address path (including endpoints) or null when unreachable.
function findBestRoute(graph, fromToken, toToken, amountIn, maxHops) {
  const from = fromToken.toLowerCase();
  const to = toToken.toLowerCase();
  let best = null;
  const visited = new Set([from]);
  const path = [from];

  (function dfs(node, amount) {
    if (node === to) {
      if (!best || amount > best.amountOut) best = { amountOut: amount, path: [...path] };
      return;
    }
    if (path.length > maxHops) return;
    for (const edge of graph.adjacency.get(node) || []) {
      if (visited.has(edge.to)) continue;
      const out = quoteHop(amount, edge.reserveIn, edge.reserveOut);
      if (out <= 0n) continue;
      visited.add(edge.to);
      path.push(edge.to);
      dfs(edge.to, out);
      path.pop();
      visited.delete(edge.to);
    }
  })(from, amountIn);

  if (!best) return null;
  return {
    path: best.path.map((lower) => graph.checksum.get(lower) || lower),
    label: best.path.map((lower) => graph.symbols.get(lower) || lower.slice(0, 8)).join(" -> "),
  };
}

// Does any pool path connect from -> to within maxHops, ignoring liquidity? Lets us
// tell "no pool path at all" apart from "path exists but too thin/small to trade",
// which is what happens for low-decimal tokens behind a dust pool.
function isReachable(graph, fromToken, toToken, maxHops) {
  const start = fromToken.toLowerCase();
  const target = toToken.toLowerCase();
  const seen = new Set([start]);
  const queue = [[start, 0]];
  while (queue.length) {
    const [node, hops] = queue.shift();
    if (node === target) return true;
    if (hops >= maxHops) continue;
    for (const edge of graph.adjacency.get(node) || []) {
      if (seen.has(edge.to)) continue;
      seen.add(edge.to);
      queue.push([edge.to, hops + 1]);
    }
  }
  return false;
}

async function buyToken({ publicClient, walletClient, account, config, token, graph, dryRun }) {
  const { symbol, decimals } = await readTokenMeta(publicClient, token);
  const amountIn = parseEther(String(token.spendCyber));

  // Check funds first in execute mode, before logging anything, so we never print a
  // BUY for a swap we then skip. Balance is re-read per token because it drops as
  // earlier tokens in the cycle are bought.
  if (!dryRun) {
    const balance = await publicClient.getBalance({ address: account.address });
    const gasReserve = parseEther(config.gasReserveCyber);
    if (balance < amountIn + gasReserve) {
      log(
        `SKIP ${symbol}: wallet balance ${formatEther(balance)} CYBER is below spend ` +
          `${formatEther(amountIn)} + gas reserve ${formatEther(gasReserve)}`
      );
      return;
    }
  }

  let route;
  if (token.path) {
    route = { path: token.path, label: `${token.path.length - 1} pinned hop(s)` };
  } else {
    route = findBestRoute(graph, config.wrappedNative, token.address, amountIn, config.maxHops);
  }
  if (!route) {
    // A path can exist topologically yet yield zero output (low-decimal token behind a
    // thin pool, or spend too small). Skip with the real reason instead of erroring.
    const reason = isReachable(graph, config.wrappedNative, token.address, config.maxHops)
      ? `pools exist but ${formatEther(amountIn)} CYBER is too small to buy a non-zero amount (thin liquidity)`
      : `no pool path from WCYBER within ${config.maxHops} hops`;
    log(`SKIP ${symbol}: ${reason}`);
    return;
  }

  const amounts = await publicClient.readContract({
    address: config.router,
    abi: routerAbi,
    functionName: "getAmountsOut",
    args: [amountIn, route.path],
  });
  const expectedOut = amounts[amounts.length - 1];
  if (expectedOut === 0n) {
    log(`SKIP ${symbol}: router quote is zero for ${formatEther(amountIn)} CYBER (thin liquidity)`);
    return;
  }
  const amountOutMin = minOutWithSlippage(expectedOut, config.slippageBps);

  log(
    `${dryRun ? "DRY-RUN" : "BUY"} ${symbol}: spend ${formatEther(amountIn)} CYBER, ` +
      `quote ${formatUnits(expectedOut, decimals)} ${symbol}, min ${formatUnits(amountOutMin, decimals)} ` +
      `[${route.label}]`
  );

  if (dryRun) return;

  const recipient = config.recipient === "self" ? account.address : config.recipient;
  const deadline = BigInt(Math.floor(Date.now() / 1000) + config.deadlineSeconds);
  const { request } = await publicClient.simulateContract({
    address: config.router,
    abi: routerAbi,
    functionName: "swapExactETHForTokens",
    args: [amountOutMin, route.path, recipient, deadline],
    account,
    value: amountIn,
  });

  const hash = await walletClient.writeContract(request);
  log(`${symbol}: tx ${hash}`);

  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  log(`${symbol}: status=${receipt.status} gasUsed=${receipt.gasUsed.toString()}`);
}

async function runCycle({ publicClient, walletClient, account, config, dryRun }) {
  log(`cycle start: ${config.tokens.length} token(s), ${dryRun ? "dry-run" : "execute"}`);
  if (config.tokens.length === 0) {
    log("no enabled tokens configured; nothing to buy");
    log("cycle end");
    return;
  }
  const graph = await buildPairGraph(publicClient, config);
  log(`routing graph: ${graph.pairCount} pair(s), maxHops ${config.maxHops}`);
  for (const token of config.tokens) {
    try {
      await buyToken({ publicClient, walletClient, account, config, token, graph, dryRun });
    } catch (error) {
      log(`ERROR ${token.symbol || token.address}: ${error.shortMessage || error.message}`);
    }
  }
  log("cycle end");
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const config = loadConfig(args.configPath);
  const dryRun = !args.execute;
  const privateKey = loadPrivateKey();
  const account = privateKey ? privateKeyToAccount(privateKey) : null;

  if (!dryRun && !account) {
    throw new Error("Execution requires DCA_PRIVATE_KEY or DCA_WALLET_KEYFILE; DEPLOYER_PK is ignored by this bot");
  }
  if (!dryRun && config.recipient === "self") {
    log(`wallet: ${account.address}`);
  }
  if (dryRun) {
    log("dry-run mode: pass --execute to send transactions");
  }

  const runtimeChain = { ...chain, rpcUrls: { default: { http: [config.rpcUrl] } } };
  // Public RPC can lag; raise the per-request timeout and retry count so a single
  // slow response no longer aborts the whole cycle with a TimeoutError.
  const transport = http(config.rpcUrl, { timeout: 30_000, retryCount: 5, retryDelay: 1_000 });
  const publicClient = createPublicClient({ chain: runtimeChain, transport });
  const walletClient = account
    ? createWalletClient({ chain: runtimeChain, account, transport })
    : null;

  do {
    await runCycle({ publicClient, walletClient, account, config, dryRun });
    if (args.once) break;
    log(`sleep ${config.intervalSeconds}s`);
    await sleep(config.intervalSeconds * 1000);
  } while (true);
}

main().catch((error) => {
  console.error(`[${new Date().toISOString()}] FATAL: ${error.shortMessage || error.message}`);
  process.exit(1);
});
