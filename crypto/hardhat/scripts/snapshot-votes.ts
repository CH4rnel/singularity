import "dotenv/config";
import { createPublicClient, formatUnits, http } from "viem";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const TOKEN_ADDRESS = process.env.TOKEN_ADDRESS;
if (!TOKEN_ADDRESS) throw new Error("TOKEN_ADDRESS is not set in .env");

const VOTERS = process.env.VOTERS;
if (!VOTERS) throw new Error("VOTERS is not set in .env");

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

const tokenAddress = TOKEN_ADDRESS as `0x${string}`;
const latestBlock = await publicClient.getBlockNumber();
const snapshotBlock = process.env.SNAPSHOT_BLOCK
  ? BigInt(process.env.SNAPSHOT_BLOCK)
  : latestBlock - 1n;
const voters = VOTERS.split(",").map((address) => address.trim() as `0x${string}`);

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
console.log("Latest block:", latestBlock);
console.log("Snapshot block:", snapshotBlock);

for (const voter of voters) {
  const votes = await publicClient.readContract({
    address: tokenAddress,
    abi: artifact.abi,
    functionName: "getPastVotes",
    args: [voter, snapshotBlock],
  });
  console.log(voter, formatUnits(votes as bigint, Number(decimals)), symbol);
}
