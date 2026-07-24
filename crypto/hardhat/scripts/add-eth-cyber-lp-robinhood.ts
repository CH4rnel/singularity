/**
 * Seed the ETH/CYBER pair on the Robinhood Chain Ritual DEX (factory
 * 0xd199…EE86, router 0xB0aC…0917) at the real market rate.
 *
 * Mints the CYBER side to the relayer (owner) as a deliberate treasury LP
 * seed — this is circulating CYBER beyond bridged deposits, so keep the
 * relayer's native CYBER balance on Cyberia funded if holders are expected
 * to bridge the pool's CYBER back home.
 *
 * Rate discipline: ETH_USD must come from a real market source (the Cyberia
 * ETH/WCYBER pool is dust and prices ETH ~3x off), CYBER_USD from the price
 * walker (token_prices).
 *
 * Usage:
 *   ETH_AMOUNT=0.006 CYBER_PER_ETH=24414 npx tsx scripts/add-eth-cyber-lp-robinhood.ts
 */

import "dotenv/config";
import { createWalletClient, createPublicClient, http, parseAbi, parseEther, formatEther, type Abi } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");

const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const RPC_URL = process.env.ROBINHOOD_RPC_URL ?? "https://rpc.mainnet.chain.robinhood.com";
const CYBER = "0x753979e6585CCa139fbB1918966D563a25eEB3B2" as const;
const ROUTER = "0xB0aC30907c04b61F1482e62eA66eF4562a690917" as const;
const FACTORY = "0xD199e6ae74B992F017f8940B26Fa18A7dD30eE86" as const;
const WETH = "0x0Bd7D308f8E1639FAb988df18A8011f41EAcAD73" as const;

const ETH_AMOUNT = parseEther(process.env.ETH_AMOUNT ?? "0.006");
const CYBER_PER_ETH = BigInt(process.env.CYBER_PER_ETH ?? "24414");
const CYBER_AMOUNT = (ETH_AMOUNT * CYBER_PER_ETH);

const chain = {
  ...mainnet,
  id: 4663,
  name: "Robinhood Chain",
  nativeCurrency: { name: "Ethereum", symbol: "ETH", decimals: 18 },
};

const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

const abi = parseAbi([
  "function mint(address to, uint256 amount)",
  "function approve(address spender, uint256 amount) returns (bool)",
  "function getPair(address, address) view returns (address)",
  "function getReserves() view returns (uint112, uint112, uint32)",
  "function addLiquidityETH(address token, uint256 amountTokenDesired, uint256 amountTokenMin, uint256 amountETHMin, address to, uint256 deadline) payable returns (uint256, uint256, uint256)",
]) as Abi;

async function write(label: string, request: Parameters<typeof walletClient.writeContract>[0]): Promise<void> {
  const hash = await walletClient.writeContract(request);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") throw new Error(`${label} reverted (${hash})`);
  console.log(`${label}: ok (tx ${hash})`);
}

async function main() {
  console.log("Seeding ETH/CYBER LP on Robinhood Chain (4663)");
  console.log(`  ETH: ${formatEther(ETH_AMOUNT)}  CYBER: ${formatEther(CYBER_AMOUNT)} (rate ${CYBER_PER_ETH}/ETH)`);

  await write("cyber.mint(relayer)", {
    address: CYBER, abi, functionName: "mint", args: [account.address, CYBER_AMOUNT],
  });
  await write("cyber.approve(router)", {
    address: CYBER, abi, functionName: "approve", args: [ROUTER, CYBER_AMOUNT],
  });

  const deadline = BigInt(Math.floor(Date.now() / 1000) + 600);
  await write("router.addLiquidityETH", {
    address: ROUTER, abi, functionName: "addLiquidityETH",
    args: [CYBER, CYBER_AMOUNT, 0n, 0n, account.address, deadline],
    value: ETH_AMOUNT,
  });

  const pair = (await publicClient.readContract({
    address: FACTORY, abi, functionName: "getPair", args: [CYBER, WETH],
  })) as `0x${string}`;
  const reserves = (await publicClient.readContract({
    address: pair, abi, functionName: "getReserves",
  })) as readonly [bigint, bigint, number];

  console.log(`\nETH/CYBER pair: ${pair}`);
  console.log(`Reserves: ${formatEther(reserves[0])} / ${formatEther(reserves[1])}`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
