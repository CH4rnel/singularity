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

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK is not set in .env");

const TOKEN_ADDRESS = process.env.TOKEN_ADDRESS;
if (!TOKEN_ADDRESS) throw new Error("TOKEN_ADDRESS is not set in .env");

const MINT_TO = process.env.MINT_TO;
if (!MINT_TO) throw new Error("MINT_TO is not set in .env");

const MINT_AMOUNT = process.env.MINT_AMOUNT;
if (!MINT_AMOUNT) throw new Error("MINT_AMOUNT is not set in .env");

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;
const account = privateKeyToAccount(pk as `0x${string}`);

const artifact = JSON.parse(
  fs.readFileSync(
    "./artifacts/contracts/VotingToken.sol/VotingToken.json",
    "utf8",
  ),
);

const cyberia = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
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
const decimals = await publicClient.readContract({
  address: tokenAddress,
  abi: artifact.abi,
  functionName: "decimals",
});
const owner = await publicClient.readContract({
  address: tokenAddress,
  abi: artifact.abi,
  functionName: "owner",
});
const symbol = await publicClient.readContract({
  address: tokenAddress,
  abi: artifact.abi,
  functionName: "symbol",
});
const amount = parseUnits(MINT_AMOUNT, Number(decimals));

console.log("Token:", tokenAddress);
console.log("Caller:", account.address);
console.log("Token owner:", owner);
console.log("Mint to:", mintTo);
console.log("Amount:", MINT_AMOUNT, symbol);

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

const balance = await publicClient.readContract({
  address: tokenAddress,
  abi: artifact.abi,
  functionName: "balanceOf",
  args: [mintTo],
});
console.log("Recipient balance:", formatUnits(balance as bigint, Number(decimals)));
