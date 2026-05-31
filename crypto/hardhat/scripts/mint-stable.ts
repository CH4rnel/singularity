import "dotenv/config";
import {
  createPublicClient,
  createWalletClient,
  formatUnits,
  http,
  parseUnits,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

/**
 * Generic mint script for the Cyberia stablecoins (USDC.sol / USDT.sol).
 *
 * Required env vars:
 *   DEPLOYER_PK   - owner / minter private key
 *   TOKEN         - "USDC" | "USDT" (selects the artifact)
 *                   OR provide TOKEN_ARTIFACT for a custom path
 *   TOKEN_ADDRESS - deployed token address on Cyberia
 *   MINT_TO       - recipient address
 *   MINT_AMOUNT   - human-readable amount (e.g. "1000" = 1000 USDC)
 *
 * Optional:
 *   RPC_URL        - defaults to https://rpc.cyberia.church
 *   TOKEN_ARTIFACT - override artifact path
 */

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK is not set in .env");

const TOKEN = (process.env.TOKEN ?? "").toUpperCase();
const TOKEN_ARTIFACT = process.env.TOKEN_ARTIFACT;
if (!TOKEN_ARTIFACT && TOKEN !== "USDC" && TOKEN !== "USDT") {
  throw new Error('TOKEN must be "USDC" or "USDT" (or set TOKEN_ARTIFACT)');
}

const TOKEN_ADDRESS = process.env.TOKEN_ADDRESS;
if (!TOKEN_ADDRESS) throw new Error("TOKEN_ADDRESS is not set");

const MINT_TO = process.env.MINT_TO;
if (!MINT_TO) throw new Error("MINT_TO is not set");

const MINT_AMOUNT = process.env.MINT_AMOUNT;
if (!MINT_AMOUNT) throw new Error("MINT_AMOUNT is not set");

const artifactPath =
  TOKEN_ARTIFACT ??
  (TOKEN === "USDC"
    ? "./artifacts/contracts/USDC.sol/USDC.json"
    : "./artifacts/contracts/USDT.sol/USDT.json");

const artifact = JSON.parse(fs.readFileSync(artifactPath, "utf8"));

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;
const account = privateKeyToAccount(pk as `0x${string}`);

const cyberia = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "CYBER", symbol: "CYBER", decimals: 18 },
};

const RPC_URL = process.env.RPC_URL ?? "https://rpc.cyberia.church";

const publicClient = createPublicClient({
  chain: cyberia,
  transport: http(RPC_URL),
});

const walletClient = createWalletClient({
  chain: cyberia,
  transport: http(RPC_URL),
  account,
});

const tokenAddress = TOKEN_ADDRESS as `0x${string}`;
const mintTo = MINT_TO as `0x${string}`;

const decimals = (await publicClient.readContract({
  address: tokenAddress,
  abi: artifact.abi,
  functionName: "decimals",
})) as number;

const symbol = (await publicClient.readContract({
  address: tokenAddress,
  abi: artifact.abi,
  functionName: "symbol",
})) as string;

const owner = (await publicClient.readContract({
  address: tokenAddress,
  abi: artifact.abi,
  functionName: "owner",
})) as `0x${string}`;

const amount = parseUnits(MINT_AMOUNT, Number(decimals));

console.log("Token:", symbol, "@", tokenAddress);
console.log("Decimals:", Number(decimals));
console.log("Caller:", account.address);
console.log("Token owner:", owner);
console.log("Mint to:", mintTo);
console.log("Amount:", MINT_AMOUNT, symbol, `(${amount.toString()} base units)`);

if (owner.toLowerCase() !== account.address.toLowerCase()) {
  throw new Error(
    `Caller ${account.address} is not the token owner (${owner}). Cannot mint.`,
  );
}

const { request } = await publicClient.simulateContract({
  address: tokenAddress,
  abi: artifact.abi,
  functionName: "mint",
  args: [mintTo, amount],
  account,
});

const hash = await walletClient.writeContract(request);
console.log("Transaction hash:", hash);

const receipt = await publicClient.waitForTransactionReceipt({ hash });
console.log("Status:", receipt.status);
console.log("Gas used:", receipt.gasUsed.toString());

const balance = (await publicClient.readContract({
  address: tokenAddress,
  abi: artifact.abi,
  functionName: "balanceOf",
  args: [mintTo],
})) as bigint;

console.log(
  `Recipient balance: ${formatUnits(balance, Number(decimals))} ${symbol}`,
);
