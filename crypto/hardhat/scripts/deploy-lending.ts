import "dotenv/config";
import { encodeAbiParameters, createWalletClient, createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "node:fs";
import * as path from "node:path";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");

const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};

const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

const ARTIFACTS = {
  Comptroller: "./artifacts/contracts/lending/LendingComptroller.sol/LendingComptroller.json",
  RateModel: "./artifacts/contracts/lending/JumpRateModel.sol/JumpRateModel.json",
  Oracle: "./artifacts/contracts/lending/SimplePriceOracle.sol/SimplePriceOracle.json",
  Market: "./artifacts/contracts/lending/LendingMarket.sol/LendingMarket.json",
};

const ERC20_ABI = [
  { type: "function", name: "decimals", stateMutability: "view", inputs: [], outputs: [{ type: "uint8" }] },
  { type: "function", name: "symbol", stateMutability: "view", inputs: [], outputs: [{ type: "string" }] },
] as const;

function loadArtifact(p: string) {
  const raw = fs.readFileSync(path.resolve(p), "utf8");
  return JSON.parse(raw) as { abi: any[]; bytecode: `0x${string}` };
}

async function deploy(name: string, artifactPath: string, args: unknown[] = []) {
  const artifact = loadArtifact(artifactPath);
  console.log(`Deploying ${name}…`);
  const hash = await walletClient.deployContract({
    abi: artifact.abi,
    bytecode: artifact.bytecode,
    args,
    gas: 10_000_000n,
  } as any);
  console.log(`  tx: ${hash}`);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (!receipt.contractAddress) throw new Error("No contract address");
  console.log(`  ${name}: ${receipt.contractAddress}`);
  return { address: receipt.contractAddress as `0x${string}`, abi: artifact.abi };
}

async function sendTx(to: `0x${string}`, abi: any[], functionName: string, args: unknown[]) {
  const hash = await walletClient.writeContract({
    address: to,
    abi,
    functionName,
    args,
    gas: 3_000_000n,
  } as any);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") throw new Error(`${functionName} failed: ${hash}`);
  return receipt;
}

const MANTISSA = 10n ** 18n;
const BLOCKS_PER_YEAR = 15_768_000n; // ~2s block time

type MarketCfg = {
  symbol: string;
  underlying: `0x${string}`;
  name: string;
  shareSymbol: string;
  reserveFactor: bigint;
  collateralFactor: bigint;
  priceUsd: bigint;
};

const MARKETS: MarketCfg[] = [
  {
    symbol: "WCYBER",
    underlying: "0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9",
    name: "Cyberia Lend WCYBER",
    shareSymbol: "clWCYBER",
    reserveFactor: 10n ** 17n,
    collateralFactor: 75n * 10n ** 16n,
    priceUsd: 1n * MANTISSA,
  },
  {
    symbol: "ASH",
    underlying: "0x992Fca0a89DD95afb17751f6CC233Adb9B089df5",
    name: "Cyberia Lend ASH",
    shareSymbol: "clASH",
    reserveFactor: 2n * 10n ** 17n,
    collateralFactor: 40n * 10n ** 16n,
    priceUsd: 10n ** 17n,
  },
  {
    symbol: "CYBER",
    underlying: "0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA",
    name: "Cyberia Lend CYBER",
    shareSymbol: "clCYBER",
    reserveFactor: 10n ** 17n,
    collateralFactor: 60n * 10n ** 16n,
    priceUsd: 1n * MANTISSA,
  },
];

async function main() {
  console.log("Deployer:", account.address);
  const balance = await publicClient.getBalance({ address: account.address });
  console.log("Balance: ", balance.toString(), "wei");
  console.log("RPC:     ", RPC_URL, "\n");

  // 1. Comptroller (deployer = owner)
  const comptroller = await deploy("LendingComptroller", ARTIFACTS.Comptroller);

  // 2. JumpRateModel: base 2%, slope 10%, jump 100%, kink 80%
  const rateModel = await deploy("JumpRateModel", ARTIFACTS.RateModel, [
    2n * 10n ** 16n,
    10n * 10n ** 16n,
    100n * 10n ** 16n,
    80n * 10n ** 16n,
    BLOCKS_PER_YEAR,
  ]);

  // 3. SimplePriceOracle
  const oracle = await deploy("SimplePriceOracle", ARTIFACTS.Oracle);

  console.log("\nWiring comptroller…");
  await sendTx(comptroller.address, comptroller.abi, "setPriceOracle", [oracle.address]);
  await sendTx(comptroller.address, comptroller.abi, "setCloseFactor", [5n * 10n ** 17n]);
  await sendTx(comptroller.address, comptroller.abi, "setLiquidationIncentive", [108n * 10n ** 16n]);
  console.log("  ok");

  console.log("\nSetting oracle prices…");
  await sendTx(oracle.address, oracle.abi, "setUnderlyingPrices", [
    MARKETS.map((m) => m.underlying),
    MARKETS.map((m) => m.priceUsd),
  ]);
  console.log("  ok");

  // 4. Deploy each market directly, then support + set CF in comptroller.
  const marketArtifact = loadArtifact(ARTIFACTS.Market);
  const created: Record<string, { address: string; underlying: string }> = {};

  for (const m of MARKETS) {
    console.log(`\n--- ${m.symbol} ---`);
    const decimals = (await publicClient.readContract({
      address: m.underlying,
      abi: ERC20_ABI,
      functionName: "decimals",
    })) as number;
    console.log(`  underlying decimals: ${decimals}`);

    const market = await deploy(`LendingMarket(${m.symbol})`, ARTIFACTS.Market, [
      m.underlying,
      comptroller.address,
      rateModel.address,
      m.reserveFactor,
      m.name,
      m.shareSymbol,
      decimals,
      account.address,
    ]);

    console.log("  comptroller.supportMarket…");
    await sendTx(comptroller.address, comptroller.abi, "supportMarket", [market.address]);
    console.log("  comptroller.setCollateralFactor…");
    await sendTx(comptroller.address, comptroller.abi, "setCollateralFactor", [
      market.address,
      m.collateralFactor,
    ]);

    created[m.symbol] = { address: market.address, underlying: m.underlying };
  }

  const summary = {
    chainId: chain.id,
    chainName: "Cyberia",
    rpc: RPC_URL,
    deployer: account.address,
    LendingComptroller: comptroller.address,
    JumpRateModel: rateModel.address,
    SimplePriceOracle: oracle.address,
    blocksPerYear: BLOCKS_PER_YEAR.toString(),
    closeFactorMantissa: "500000000000000000",
    liquidationIncentiveMantissa: "1080000000000000000",
    markets: created,
    timestamp: new Date().toISOString(),
  };

  console.log("\n═══════════════════════════════════════");
  console.log(JSON.stringify(summary, null, 2));
  console.log("═══════════════════════════════════════");

  const outDir = path.resolve("./deployments");
  fs.mkdirSync(outDir, { recursive: true });
  const outFile = path.join(outDir, "cyberia-lending.json");
  fs.writeFileSync(outFile, JSON.stringify(summary, null, 2));
  console.log(`Saved to ${outFile}`);
  console.log(`\nSet for the frontend:`);
  console.log(`VITE_LENDING_COMPTROLLER=${comptroller.address}`);

  // Suppress unused import lint
  void encodeAbiParameters;
}

main().catch((e) => { console.error(e); process.exit(1); });
