import "dotenv/config";
import { readFileSync, writeFileSync, existsSync } from "node:fs";
import { createPublicClient, createWalletClient, defineChain, getAddress, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";

const chainId = Number(process.env.EVM_CHAIN_ID);
if (![49406, 49407].includes(chainId)) throw new Error("EVM_CHAIN_ID must be 49406 or 49407");
const rpc = process.env.EVM_RPC_URL;
const key = process.env.BRIDGE_RELAYER_PRIVATE_KEY || process.env.DEPLOYER_PK;
const owner = process.env.BRIDGE_RELAYER_ADDRESS;
if (!rpc || !key || !owner) throw new Error("EVM_RPC_URL, deployer key and BRIDGE_RELAYER_ADDRESS are required");
const account = privateKeyToAccount((key.startsWith("0x") ? key : `0x${key}`) as `0x${string}`);
const chain = defineChain({ id: chainId, name: chainId === 49406 ? "Cyberia" : "Cyberia Fuji", nativeCurrency: {name: "CYBER", symbol: "CYBER", decimals: 18}, rpcUrls: {default: {http: [rpc]}} });
const publicClient = createPublicClient({ chain, transport: http(rpc) });
if (await publicClient.getChainId() !== chainId) throw new Error("RPC chain ID mismatch");
const artifact = JSON.parse(readFileSync("artifacts/contracts/AVAXFuji.sol/AVAXFuji.json", "utf8"));
const recordPath = `deployments/avax-fuji-${chainId}.json`;
if (existsSync(recordPath)) {
  const record = JSON.parse(readFileSync(recordPath, "utf8"));
  const code = await publicClient.getCode({address: record.address});
  if (!code || code === "0x") throw new Error("Recorded wrapper has no code; refusing a replacement");
  console.log(JSON.stringify(record));
} else {
  if (!process.argv.includes("--broadcast")) throw new Error("Pass --broadcast to deploy; no token is preminted");
  const wallet = createWalletClient({ account, chain, transport: http(rpc) });
  const hash = await wallet.deployContract({abi: artifact.abi, bytecode: artifact.bytecode, args: [getAddress(owner)]});
  console.log(JSON.stringify({broadcastTxHash: hash}));
  const receipt = await publicClient.waitForTransactionReceipt({hash});
  if (receipt.status !== "success" || !receipt.contractAddress) throw new Error("Deployment did not succeed");
  const record = {chainId, symbol: "AVAX.FUJI", name: "Avalanche Fuji Test AVAX", address: receipt.contractAddress, owner: getAddress(owner), transactionHash: hash, blockNumber: receipt.blockNumber.toString()};
  writeFileSync(recordPath, JSON.stringify(record, null, 2) + "\n");
  console.log(JSON.stringify(record));
}
