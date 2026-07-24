/**
 * Seed the ASH/WETH pool on Robinhood with the bridged ASH and register both
 * RH farm LPs (CYBER/WETH, ASH/WETH) in the FundedFarm.
 *
 * The seeded ASH is minted 1:1 against ASH the relayer holds and reserves on
 * Cyberia (backing the bridged supply) — do NOT mint more bridged ASH than the
 * reserved Cyberia ASH. Seed rate matches ASH's Cyberia market price.
 *
 * Usage: npx tsx scripts/add-robinhood-funded-pools.ts
 */

import "dotenv/config";
import { createWalletClient, createPublicClient, http, parseAbi, parseEther, formatEther, type Abi } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");
const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const RPC_URL = process.env.ROBINHOOD_RPC_URL ?? "https://rpc.mainnet.chain.robinhood.com";
const dep = JSON.parse(fs.readFileSync("./deployments/robinhood-funded-farm.json", "utf8"));
const ASH = dep.AshBridged as `0x${string}`;
const FARM = dep.FundedFarm as `0x${string}`;
const ROUTER = "0xB0aC30907c04b61F1482e62eA66eF4562a690917" as const;
const FACTORY = "0xD199e6ae74B992F017f8940B26Fa18A7dD30eE86" as const;
const WETH = "0x0Bd7D308f8E1639FAb988df18A8011f41EAcAD73" as const;
const CYBER_WETH_LP = "0x4E93763183A3eC492f01C06AE28805f0C1d0e6E7" as const;

// Seed: 100 bridged ASH (backed by 100 reserved Cyberia ASH) paired at ASH's
// Cyberia market price (~$0.00195; ETH ~$1863 ⇒ ~956,750 ASH/ETH).
const ASH_SEED = parseEther("100");
const ETH_SEED = parseEther("0.0001045");

const chain = { ...mainnet, id: 4663, name: "Robinhood Chain", nativeCurrency: { name: "Ethereum", symbol: "ETH", decimals: 18 } };
const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

const abi = parseAbi([
  "function mint(address to, uint256 amount)",
  "function approve(address spender, uint256 amount) returns (bool)",
  "function getPair(address, address) view returns (address)",
  "function addLiquidityETH(address token, uint256 amountTokenDesired, uint256 amountTokenMin, uint256 amountETHMin, address to, uint256 deadline) payable returns (uint256, uint256, uint256)",
  "function add(uint256 allocPoint, address lpToken, bool withUpdate)",
  "function poolLength() view returns (uint256)",
]) as Abi;

async function send(label: string, request: Parameters<typeof walletClient.writeContract>[0]): Promise<void> {
  const hash = await walletClient.writeContract(request);
  const r = await publicClient.waitForTransactionReceipt({ hash });
  if (r.status !== "success") throw new Error(`${label} reverted (${hash})`);
  console.log(`${label}: ok (${hash})`);
}

async function main() {
  console.log("Seeding ASH/WETH + registering RH farm pools");
  console.log(`  ASH seed ${formatEther(ASH_SEED)} + ETH ${formatEther(ETH_SEED)}`);

  await send("ash.mint(relayer, 100)", { address: ASH, abi, functionName: "mint", args: [account.address, ASH_SEED] });
  await send("ash.approve(router)", { address: ASH, abi, functionName: "approve", args: [ROUTER, ASH_SEED] });
  await send("router.addLiquidityETH(ASH/WETH)", {
    address: ROUTER, abi, functionName: "addLiquidityETH",
    args: [ASH, ASH_SEED, 0n, 0n, account.address, BigInt(Math.floor(Date.now() / 1000) + 600)],
    value: ETH_SEED,
  });

  const ashWethLp = (await publicClient.readContract({
    address: FACTORY, abi, functionName: "getPair", args: [ASH, WETH],
  })) as `0x${string}`;
  console.log("ASH/WETH LP:", ashWethLp);

  // Register the 2 RH farms (equal allocPoint 100 each).
  await send("farm.add(CYBER/WETH)", { address: FARM, abi, functionName: "add", args: [100n, CYBER_WETH_LP, false] });
  await send("farm.add(ASH/WETH)", { address: FARM, abi, functionName: "add", args: [100n, ashWethLp, false] });

  const len = await publicClient.readContract({ address: FARM, abi, functionName: "poolLength" });
  console.log("FundedFarm poolLength:", (len as bigint).toString());

  dep.pools = [
    { pid: 0, label: "CYBER/WETH LP", lpToken: CYBER_WETH_LP, allocPoint: 100 },
    { pid: 1, label: "ASH/WETH LP", lpToken: ashWethLp, allocPoint: 100 },
  ];
  dep.ashWethPair = ashWethLp;
  fs.writeFileSync("./deployments/robinhood-funded-farm.json", JSON.stringify(dep, null, 2) + "\n");
  console.log("Updated deployments/robinhood-funded-farm.json");
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
