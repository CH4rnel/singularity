import "dotenv/config";
import {
  createPublicClient,
  createWalletClient,
  formatUnits,
  http,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK is not set in .env");

const TOKEN_ADDRESS = process.env.TOKEN_ADDRESS;
if (!TOKEN_ADDRESS) throw new Error("TOKEN_ADDRESS is not set in .env");

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;
const account = privateKeyToAccount(pk as `0x${string}`);
const delegatee =
  (process.env.DELEGATEE as `0x${string}` | undefined) ?? account.address;

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
const decimals = await publicClient.readContract({
  address: tokenAddress,
  abi: artifact.abi,
  functionName: "decimals",
});
const symbol = await publicClient.readContract({
  address: tokenAddress,
  abi: artifact.abi,
  functionName: "symbol",
});

console.log("Token:", tokenAddress);
console.log("Delegator:", account.address);
console.log("Delegatee:", delegatee);

const { request } = await publicClient.simulateContract({
  address: tokenAddress,
  abi: artifact.abi,
  functionName: "delegate",
  args: [delegatee],
  account,
});

const hash = await walletClient.writeContract(request);
console.log("Transaction hash:", hash);

const receipt = await publicClient.waitForTransactionReceipt({ hash });
console.log("Status:", receipt.status);
console.log("Block number:", receipt.blockNumber);

const votes = await publicClient.readContract({
  address: tokenAddress,
  abi: artifact.abi,
  functionName: "getVotes",
  args: [delegatee],
});
console.log("Current votes:", formatUnits(votes as bigint, Number(decimals)), symbol);
