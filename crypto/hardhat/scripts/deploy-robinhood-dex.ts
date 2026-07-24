/**
 * Deploy the Ritual DEX stack on Robinhood Chain (Arbitrum Orbit L2, id 4663):
 *   1. UniswapV2Factory   (canonical bytecode, feeToSetter = deployer)
 *   2. UniswapV2Router02  (canonical bytecode, WETH = chain-canonical aeWETH)
 *   3. Ash                (farm reward token, 1-ASH premint to deployer)
 *   4. MasterChef         (rewardToken = ASH, then ash.setMinter(chef))
 *
 * WETH is NOT deployed: Robinhood Chain ships a canonical aeWETH proxy
 * (payable deposit/withdraw over native ETH) that every other DEX there uses.
 *
 * After deploying, the script verifies the router's hardcoded pair-address
 * math by creating the ASH/WETH pair and comparing the factory's answer with
 * the CREATE2 prediction, then seeds dust liquidity and performs a dust
 * ETH -> ASH swap so "create LP + swap" is proven end-to-end.
 *
 * Usage:
 *   npx hardhat compile && npx tsx scripts/fetch-uniswap-bytecodes.ts
 *   npx tsx scripts/deploy-robinhood-dex.ts
 */

import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  http,
  encodePacked,
  getAddress,
  keccak256,
  parseEther,
  type Abi,
  type Hex,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");

const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const RPC_URL = process.env.ROBINHOOD_RPC_URL ?? "https://rpc.mainnet.chain.robinhood.com";
// Chain-canonical wrapped native ETH (aeWETH behind a proxy, 269k+ holders).
const WETH = "0x0Bd7D308f8E1639FAb988df18A8011f41EAcAD73" as const;
const DEPLOYMENT_FILE = "./deployments/robinhood-dex.json";

// Emission: ASH per block. block.number on an Orbit chain tracks the parent
// chain (~12s cadence here), so 1e18/block ≈ 7 200 ASH/day. Owner-tunable
// later via MasterChef.setRewardPerBlock.
const REWARD_PER_BLOCK = process.env.REWARD_PER_BLOCK
  ? BigInt(process.env.REWARD_PER_BLOCK)
  : 1_000_000_000_000_000_000n;

const chain = {
  ...mainnet,
  id: 4663,
  name: "Robinhood Chain",
  nativeCurrency: { name: "Ethereum", symbol: "ETH", decimals: 18 },
};

const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

if (fs.existsSync(DEPLOYMENT_FILE) && process.env.FORCE !== "true") {
  throw new Error(`${DEPLOYMENT_FILE} already exists — set FORCE=true to redeploy`);
}

const FACTORY_ABI = [
  { type: "constructor", inputs: [{ name: "_feeToSetter", type: "address" }] },
  { type: "function", name: "createPair", stateMutability: "nonpayable",
    inputs: [{ name: "tokenA", type: "address" }, { name: "tokenB", type: "address" }],
    outputs: [{ name: "pair", type: "address" }] },
  { type: "function", name: "getPair", stateMutability: "view",
    inputs: [{ name: "", type: "address" }, { name: "", type: "address" }],
    outputs: [{ type: "address" }] },
  { type: "function", name: "allPairsLength", stateMutability: "view",
    inputs: [], outputs: [{ type: "uint256" }] },
] as const satisfies Abi;

const ROUTER_ABI = [
  { type: "constructor",
    inputs: [{ name: "_factory", type: "address" }, { name: "_WETH", type: "address" }] },
  { type: "function", name: "factory", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
  { type: "function", name: "WETH", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
  { type: "function", name: "addLiquidityETH", stateMutability: "payable",
    inputs: [
      { name: "token", type: "address" },
      { name: "amountTokenDesired", type: "uint256" },
      { name: "amountTokenMin", type: "uint256" },
      { name: "amountETHMin", type: "uint256" },
      { name: "to", type: "address" },
      { name: "deadline", type: "uint256" },
    ],
    outputs: [
      { name: "amountToken", type: "uint256" },
      { name: "amountETH", type: "uint256" },
      { name: "liquidity", type: "uint256" },
    ] },
  { type: "function", name: "swapExactETHForTokens", stateMutability: "payable",
    inputs: [
      { name: "amountOutMin", type: "uint256" },
      { name: "path", type: "address[]" },
      { name: "to", type: "address" },
      { name: "deadline", type: "uint256" },
    ],
    outputs: [{ name: "amounts", type: "uint256[]" }] },
] as const satisfies Abi;

const ERC20_ABI = [
  { type: "function", name: "approve", stateMutability: "nonpayable",
    inputs: [{ name: "spender", type: "address" }, { name: "amount", type: "uint256" }],
    outputs: [{ type: "bool" }] },
  { type: "function", name: "balanceOf", stateMutability: "view",
    inputs: [{ name: "", type: "address" }], outputs: [{ type: "uint256" }] },
] as const satisfies Abi;

async function deployBytecode(
  label: string, abi: Abi, bytecode: Hex, args: unknown[] = [],
): Promise<`0x${string}`> {
  console.log(`Deploying ${label}...`);
  const hash = await walletClient.deployContract({ abi, bytecode, args });
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success" || !receipt.contractAddress) {
    throw new Error(`${label} deployment reverted (${hash})`);
  }
  console.log(`  ${label}: ${receipt.contractAddress} (tx ${hash})`);
  return receipt.contractAddress;
}

async function write(
  label: string,
  request: Parameters<typeof walletClient.writeContract>[0],
): Promise<void> {
  const hash = await walletClient.writeContract(request);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") throw new Error(`${label} reverted (${hash})`);
  console.log(`  ${label}: ok (tx ${hash})`);
}

async function main() {
  console.log("=== Ritual DEX deployment on Robinhood Chain (4663) ===");
  console.log("Deployer:", account.address);
  console.log("WETH (canonical):", WETH);

  const factoryBytecode = JSON.parse(
    fs.readFileSync("./bytecodes/UniswapV2Factory.json", "utf8"),
  ).bytecode as Hex;
  const routerBytecode = JSON.parse(
    fs.readFileSync("./bytecodes/UniswapV2Router02.json", "utf8"),
  ).bytecode as Hex;
  const pairBytecode = JSON.parse(
    fs.readFileSync("./node_modules/@uniswap/v2-core/build/UniswapV2Pair.json", "utf8"),
  ).bytecode as string;
  const initCodeHash = keccak256(
    (pairBytecode.startsWith("0x") ? pairBytecode : `0x${pairBytecode}`) as Hex,
  );
  console.log("INIT_CODE_HASH:", initCodeHash);

  const ashArtifact = JSON.parse(
    fs.readFileSync("./artifacts/contracts/Ash.sol/Ash.json", "utf8"),
  );
  const chefArtifact = JSON.parse(
    fs.readFileSync("./artifacts/contracts/MasterChef.sol/MasterChef.json", "utf8"),
  );

  const factory = await deployBytecode(
    "UniswapV2Factory", FACTORY_ABI, factoryBytecode, [account.address],
  );
  const router = await deployBytecode(
    "UniswapV2Router02", ROUTER_ABI, routerBytecode, [factory, WETH],
  );
  const ash = await deployBytecode(
    "Ash", ashArtifact.abi, ashArtifact.bytecode as Hex, [account.address],
  );
  // startBlock 0: block.number is already past it, so pools accrue from the
  // moment they are added — no dependence on Orbit L1/L2 block-number quirks.
  const chef = await deployBytecode(
    "MasterChef", chefArtifact.abi, chefArtifact.bytecode as Hex,
    [ash, REWARD_PER_BLOCK, 0n],
  );
  await write("ash.setMinter(chef)", {
    address: ash, abi: ashArtifact.abi as Abi, functionName: "setMinter", args: [chef],
  });

  // --- Verify pair math: factory answer must equal the CREATE2 prediction the
  // router bytecode computes internally, or every swap would revert.
  await write("factory.createPair(ASH, WETH)", {
    address: factory, abi: FACTORY_ABI, functionName: "createPair", args: [ash, WETH],
  });
  const pair = (await publicClient.readContract({
    address: factory, abi: FACTORY_ABI, functionName: "getPair", args: [ash, WETH],
  })) as `0x${string}`;
  const [token0, token1] =
    ash.toLowerCase() < WETH.toLowerCase() ? [ash, WETH] : [WETH, ash];
  const predicted = getAddress(
    `0x${keccak256(
      encodePacked(
        ["bytes1", "address", "bytes32", "bytes32"],
        ["0xff", factory, keccak256(encodePacked(["address", "address"], [token0, token1])), initCodeHash],
      ),
    ).slice(-40)}`,
  );
  if (getAddress(pair) !== predicted) {
    throw new Error(`CREATE2 mismatch: factory says ${pair}, router math says ${predicted}`);
  }
  console.log("  pair address matches router CREATE2 math:", pair);

  // --- Smoke test with dust amounts: seed ASH/ETH liquidity, swap ETH -> ASH.
  const deadline = BigInt(Math.floor(Date.now() / 1000) + 600);
  await write("ash.approve(router)", {
    address: ash, abi: ERC20_ABI, functionName: "approve", args: [router, parseEther("1")],
  });
  await write("router.addLiquidityETH (0.5 ASH + 0.0002 ETH)", {
    address: router, abi: ROUTER_ABI, functionName: "addLiquidityETH",
    args: [ash, parseEther("0.5"), 0n, 0n, account.address, deadline],
    value: parseEther("0.0002"),
  });
  await write("router.swapExactETHForTokens (0.00002 ETH -> ASH)", {
    address: router, abi: ROUTER_ABI, functionName: "swapExactETHForTokens",
    args: [0n, [WETH, ash], account.address, deadline],
    value: parseEther("0.00002"),
  });

  const record = {
    chainId: 4663,
    chainName: "Robinhood Chain",
    rpc: RPC_URL,
    deployer: account.address,
    WETH,
    UniswapV2Factory: factory,
    UniswapV2Router02: router,
    INIT_CODE_HASH: initCodeHash,
    ASH: ash,
    MasterChef: chef,
    rewardPerBlock: REWARD_PER_BLOCK.toString(),
    ashWethPair: pair,
    timestamp: new Date().toISOString(),
  };
  fs.writeFileSync(DEPLOYMENT_FILE, JSON.stringify(record, null, 2) + "\n");
  console.log(`\nDeployment recorded in ${DEPLOYMENT_FILE}`);
  console.log(JSON.stringify(record, null, 2));
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
