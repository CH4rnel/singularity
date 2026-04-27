/**
 * Deploy the full DEX stack on Cyberia chain (49406):
 *   1. WCYBER (wrapped native token)
 *   2. UniswapV2Factory
 *   3. UniswapV2Router02
 *   4. MasterChef (farming)
 *
 * UniswapV2 Factory and Router are deployed using canonical ABI + bytecode
 * from the original Uniswap V2 contracts (Solidity 0.5.16 / 0.6.6).
 * WCYBER and MasterChef are compiled from our own Solidity 0.8.19 contracts.
 *
 * Usage:
 *   npx hardhat compile   # compile WCYBER + MasterChef first
 *   npx tsx scripts/deploy-dex.ts
 *
 * After deployment, note the INIT_CODE_HASH printed — you need it for the
 * PancakeSwap frontend v2-sdk constants.
 */

import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  http,
  encodeAbiParameters,
  keccak256,
  type Abi,
  type Hex,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

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

const RPC_URL = "http://195.166.164.94:8545";

const walletClient = createWalletClient({
  chain,
  transport: http(RPC_URL),
  account,
});

const publicClient = createPublicClient({
  chain,
  transport: http(RPC_URL),
});

// CyberToken address (already deployed)
const CYBER_TOKEN_ADDRESS = process.env.CYBER_TOKEN_ADDRESS as `0x${string}` | undefined;

// Reward per block for MasterChef (default: 1 CYBER per block)
const REWARD_PER_BLOCK = process.env.REWARD_PER_BLOCK
  ? BigInt(process.env.REWARD_PER_BLOCK)
  : 1_000_000_000_000_000_000n; // 1e18 = 1 CYBER

// ---------------------------------------------------------------------------
// Deploy helpers
// ---------------------------------------------------------------------------

async function deployFromArtifact(artifactPath: string, args: unknown[] = []) {
  const artifact = JSON.parse(fs.readFileSync(artifactPath, "utf8"));
  const hash = await walletClient.deployContract({
    abi: artifact.abi,
    bytecode: artifact.bytecode as Hex,
    args,
  });
  console.log("  tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  console.log("  address:", receipt.contractAddress);
  return { address: receipt.contractAddress!, abi: artifact.abi as Abi };
}

async function deployFromBytecode(abi: Abi, bytecode: Hex, args: unknown[] = []) {
  const hash = await walletClient.deployContract({
    abi,
    bytecode,
    args,
  });
  console.log("  tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  console.log("  address:", receipt.contractAddress);
  return receipt.contractAddress!;
}

// ---------------------------------------------------------------------------
// Canonical UniswapV2 ABIs (minimal, needed for deployment + init_code_hash)
// ---------------------------------------------------------------------------

const UniswapV2FactoryABI = [
  {
    type: "constructor",
    inputs: [{ name: "_feeToSetter", type: "address" }],
  },
  {
    type: "function",
    name: "allPairsLength",
    inputs: [],
    outputs: [{ type: "uint256" }],
    stateMutability: "view",
  },
  {
    type: "function",
    name: "createPair",
    inputs: [
      { name: "tokenA", type: "address" },
      { name: "tokenB", type: "address" },
    ],
    outputs: [{ name: "pair", type: "address" }],
    stateMutability: "nonpayable",
  },
  {
    type: "function",
    name: "getPair",
    inputs: [
      { name: "", type: "address" },
      { name: "", type: "address" },
    ],
    outputs: [{ type: "address" }],
    stateMutability: "view",
  },
  {
    type: "function",
    name: "feeTo",
    inputs: [],
    outputs: [{ type: "address" }],
    stateMutability: "view",
  },
  {
    type: "function",
    name: "feeToSetter",
    inputs: [],
    outputs: [{ type: "address" }],
    stateMutability: "view",
  },
  {
    type: "function",
    name: "setFeeTo",
    inputs: [{ name: "_feeTo", type: "address" }],
    outputs: [],
    stateMutability: "nonpayable",
  },
  {
    type: "function",
    name: "setFeeToSetter",
    inputs: [{ name: "_feeToSetter", type: "address" }],
    outputs: [],
    stateMutability: "nonpayable",
  },
  {
    type: "function",
    name: "pairCodeHash",
    inputs: [],
    outputs: [{ type: "bytes32" }],
    stateMutability: "pure",
  },
  {
    type: "event",
    name: "PairCreated",
    inputs: [
      { name: "token0", type: "address", indexed: true },
      { name: "token1", type: "address", indexed: true },
      { name: "pair", type: "address", indexed: false },
      { name: "", type: "uint256", indexed: false },
    ],
  },
] as const;

const UniswapV2Router02ABI = [
  {
    type: "constructor",
    inputs: [
      { name: "_factory", type: "address" },
      { name: "_WETH", type: "address" },
    ],
  },
  {
    type: "function",
    name: "factory",
    inputs: [],
    outputs: [{ type: "address" }],
    stateMutability: "view",
  },
  {
    type: "function",
    name: "WETH",
    inputs: [],
    outputs: [{ type: "address" }],
    stateMutability: "view",
  },
  {
    type: "function",
    name: "addLiquidity",
    inputs: [
      { name: "tokenA", type: "address" },
      { name: "tokenB", type: "address" },
      { name: "amountADesired", type: "uint256" },
      { name: "amountBDesired", type: "uint256" },
      { name: "amountAMin", type: "uint256" },
      { name: "amountBMin", type: "uint256" },
      { name: "to", type: "address" },
      { name: "deadline", type: "uint256" },
    ],
    outputs: [
      { name: "amountA", type: "uint256" },
      { name: "amountB", type: "uint256" },
      { name: "liquidity", type: "uint256" },
    ],
    stateMutability: "nonpayable",
  },
  {
    type: "function",
    name: "addLiquidityETH",
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
    ],
    stateMutability: "payable",
  },
  {
    type: "function",
    name: "swapExactTokensForTokens",
    inputs: [
      { name: "amountIn", type: "uint256" },
      { name: "amountOutMin", type: "uint256" },
      { name: "path", type: "address[]" },
      { name: "to", type: "address" },
      { name: "deadline", type: "uint256" },
    ],
    outputs: [{ name: "amounts", type: "uint256[]" }],
    stateMutability: "nonpayable",
  },
  {
    type: "function",
    name: "swapExactETHForTokens",
    inputs: [
      { name: "amountOutMin", type: "uint256" },
      { name: "path", type: "address[]" },
      { name: "to", type: "address" },
      { name: "deadline", type: "uint256" },
    ],
    outputs: [{ name: "amounts", type: "uint256[]" }],
    stateMutability: "payable",
  },
  {
    type: "function",
    name: "swapExactTokensForETH",
    inputs: [
      { name: "amountIn", type: "uint256" },
      { name: "amountOutMin", type: "uint256" },
      { name: "path", type: "address[]" },
      { name: "to", type: "address" },
      { name: "deadline", type: "uint256" },
    ],
    outputs: [{ name: "amounts", type: "uint256[]" }],
    stateMutability: "nonpayable",
  },
  {
    type: "function",
    name: "getAmountsOut",
    inputs: [
      { name: "amountIn", type: "uint256" },
      { name: "path", type: "address[]" },
    ],
    outputs: [{ name: "amounts", type: "uint256[]" }],
    stateMutability: "view",
  },
] as const;

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main() {
  console.log("=== DEX Deployment on Cyberia (chainId 49406) ===");
  console.log("Deployer:", account.address);
  console.log("");

  // 1. Deploy WCYBER
  console.log("1. Deploying WCYBER (Wrapped CYBER)...");
  const wcyber = await deployFromArtifact(
    "./artifacts/contracts/WCYBER.sol/WCYBER.json"
  );
  console.log("");

  // 2. Deploy UniswapV2Factory
  // Using canonical bytecode from https://etherscan.io/address/0x5C69bEe701ef814a2B6a3EDD4B1652CB9cc5aA6f#code
  // The bytecode file must be placed at ./bytecodes/UniswapV2Factory.json
  console.log("2. Deploying UniswapV2Factory...");
  let factoryAddress: `0x${string}`;
  let factoryAbi: Abi;
  const factoryBytecodeFile = "./bytecodes/UniswapV2Factory.json";
  if (fs.existsSync(factoryBytecodeFile)) {
    const { bytecode } = JSON.parse(fs.readFileSync(factoryBytecodeFile, "utf8"));
    factoryAddress = await deployFromBytecode(
      UniswapV2FactoryABI as unknown as Abi,
      bytecode as Hex,
      [account.address] // feeToSetter
    );
    factoryAbi = UniswapV2FactoryABI as unknown as Abi;
  } else {
    console.log("  ERROR: bytecodes/UniswapV2Factory.json not found!");
    console.log("  Please provide the canonical UniswapV2Factory bytecode.");
    console.log("  You can get it from: https://etherscan.io/address/0x5C69bEe701ef814a2B6a3EDD4B1652CB9cc5aA6f#code");
    console.log("");
    console.log("  Create ./bytecodes/UniswapV2Factory.json with:");
    console.log('  { "bytecode": "0x..." }');
    process.exit(1);
  }
  console.log("");

  // 3. Get INIT_CODE_HASH
  console.log("3. Reading INIT_CODE_HASH (pairCodeHash)...");
  let initCodeHash: string;
  try {
    initCodeHash = await publicClient.readContract({
      address: factoryAddress,
      abi: factoryAbi,
      functionName: "pairCodeHash",
    }) as string;
    console.log("  INIT_CODE_HASH:", initCodeHash);
  } catch {
    console.log("  WARNING: pairCodeHash() not available on this factory.");
    console.log("  You'll need to compute it manually after creating the first pair.");
    initCodeHash = "UNKNOWN — compute after first pair creation";
  }
  console.log("");

  // 4. Deploy UniswapV2Router02
  console.log("4. Deploying UniswapV2Router02...");
  let routerAddress: `0x${string}`;
  const routerBytecodeFile = "./bytecodes/UniswapV2Router02.json";
  if (fs.existsSync(routerBytecodeFile)) {
    const { bytecode } = JSON.parse(fs.readFileSync(routerBytecodeFile, "utf8"));
    routerAddress = await deployFromBytecode(
      UniswapV2Router02ABI as unknown as Abi,
      bytecode as Hex,
      [factoryAddress, wcyber.address] // factory, WETH
    );
  } else {
    console.log("  ERROR: bytecodes/UniswapV2Router02.json not found!");
    console.log("  Please provide the canonical UniswapV2Router02 bytecode.");
    console.log("  You can get it from: https://etherscan.io/address/0x7a250d5630B4cF539739dF2C5dAcb4c659F2488D#code");
    console.log("");
    console.log("  Create ./bytecodes/UniswapV2Router02.json with:");
    console.log('  { "bytecode": "0x..." }');
    process.exit(1);
  }
  console.log("");

  // 5. Deploy MasterChef (farming)
  console.log("5. Deploying MasterChef...");
  if (!CYBER_TOKEN_ADDRESS) {
    console.log("  WARNING: CYBER_TOKEN_ADDRESS not set in .env.");
    console.log("  Skipping MasterChef deployment.");
    console.log("  Set CYBER_TOKEN_ADDRESS and re-run to deploy MasterChef.");
    console.log("");
    printSummary(wcyber.address, factoryAddress, routerAddress, initCodeHash, null);
    return;
  }

  const currentBlock = await publicClient.getBlockNumber();
  const masterChef = await deployFromArtifact(
    "./artifacts/contracts/MasterChef.sol/MasterChef.json",
    [
      CYBER_TOKEN_ADDRESS,   // reward token
      REWARD_PER_BLOCK,      // reward per block
      currentBlock + 10n,    // start block (10 blocks from now)
    ]
  );
  console.log("");

  // 6. Transfer CYBER minting rights to MasterChef
  console.log("6. Transferring CYBER token ownership to MasterChef...");
  console.log("  NOTE: MasterChef needs mint() rights on CyberToken.");
  console.log("  Run this manually if CyberToken is Ownable:");
  console.log(`  cyberToken.transferOwnership("${masterChef.address}")`);
  console.log("");

  printSummary(
    wcyber.address,
    factoryAddress,
    routerAddress,
    initCodeHash,
    masterChef.address
  );
}

function printSummary(
  wcyber: string,
  factory: string,
  router: string,
  initCodeHash: string,
  masterChef: string | null,
) {
  console.log("╔══════════════════════════════════════════════════════════════╗");
  console.log("║                   DEX DEPLOYMENT SUMMARY                    ║");
  console.log("╠══════════════════════════════════════════════════════════════╣");
  console.log(`║  Chain:            Cyberia (49406)                          ║`);
  console.log(`║  WCYBER:           ${wcyber}`);
  console.log(`║  UniswapV2Factory: ${factory}`);
  console.log(`║  UniswapV2Router:  ${router}`);
  console.log(`║  INIT_CODE_HASH:   ${initCodeHash}`);
  if (masterChef) {
    console.log(`║  MasterChef:       ${masterChef}`);
  }
  console.log("╚══════════════════════════════════════════════════════════════╝");
  console.log("");
  console.log("Next steps:");
  console.log("  1. Add these addresses to PancakeSwap frontend:");
  console.log("     - packages/v2-sdk/src/constants.ts");
  console.log("     - packages/smart-router/evm/constants/exchange.ts");
  console.log("  2. Add INIT_CODE_HASH to INIT_CODE_HASH_MAP");
  console.log("  3. Create initial liquidity pairs (e.g., CYBER/WCYBER)");
  if (masterChef) {
    console.log("  4. Transfer CyberToken ownership to MasterChef");
    console.log("  5. Add LP pools to MasterChef via add()");
  }
}

main().catch(console.error);
