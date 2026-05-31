import "dotenv/config";
import { createWalletClient, createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set");

const CYBER_TOKEN = process.env.CYBER_TOKEN_ADDRESS;
if (!CYBER_TOKEN) throw new Error("CYBER_TOKEN_ADDRESS not set in .env");

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;

const bridgeArtifact = JSON.parse(
  fs.readFileSync("./artifacts/contracts/CyberBridge.sol/CyberBridge.json", "utf8"),
);

const account = privateKeyToAccount(pk as `0x${string}`);

const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};

const client = createWalletClient({
  chain,
  transport: http("https://rpc.cyberia.church"),
  account,
});

const publicClient = createPublicClient({
  chain,
  transport: http("https://rpc.cyberia.church"),
});

console.log("Deploying CyberBridge from:", client.account.address);
console.log("CYBER token address:", CYBER_TOKEN);
console.log("Relayer (owner):", client.account.address);

const hash = await client.deployContract({
  abi: bridgeArtifact.abi,
  bytecode: bridgeArtifact.bytecode,
  args: [CYBER_TOKEN, client.account.address],
});

console.log("Transaction hash:", hash);

const receipt = await publicClient.waitForTransactionReceipt({ hash });
console.log("CyberBridge deployed at:", receipt.contractAddress);
console.log("Block:", receipt.blockNumber);

// Read the wrappedCyberSol address from the contract
if (receipt.contractAddress) {
  const wrappedAddr = await publicClient.readContract({
    address: receipt.contractAddress,
    abi: bridgeArtifact.abi,
    functionName: "wrappedCyberSol",
  });
  console.log("WrappedCyberSol deployed at:", wrappedAddr);
}
