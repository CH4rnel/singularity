import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  http,
  parseEther,
  formatEther,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

// Convert bridged CYBER.sol -> native CYBER through the CyberSolSwap redeemer
// at the fixed 1000 : 1 rate. Amount (CYBER.sol, human units) comes from the
// AMOUNT env var because `hardhat run` does not forward positional args. Usage:
//
//   npx hardhat run scripts/swap-cybersol.ts --network cyberia            # default 1 CYBER.sol
//   AMOUNT=5 npx hardhat run scripts/swap-cybersol.ts --network cyberia
//
// The swap contract address is read from CYBERSOL_SWAP or
// deployments/cyberia-cybersol-swap.json; CYBER.sol from the bridge registry.

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set");
const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const RPC_URL = process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church";
const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "CYBER", symbol: "CYBER", decimals: 18 },
};

const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

const RATE = 1000n;

function resolveSwapAddress(): `0x${string}` {
  if (process.env.CYBERSOL_SWAP) return process.env.CYBERSOL_SWAP as `0x${string}`;
  const path = "./deployments/cyberia-cybersol-swap.json";
  if (!fs.existsSync(path)) {
    throw new Error(
      "CyberSolSwap address not found: set CYBERSOL_SWAP or deploy first (deploy-cybersol-swap.ts)",
    );
  }
  return JSON.parse(fs.readFileSync(path, "utf8")).address as `0x${string}`;
}

const swapAddress = resolveSwapAddress();
const bridge = JSON.parse(fs.readFileSync("./deployments/cyberia-bridge.json", "utf8"));
const cyberSol = (process.env.CYBER_SOL ?? bridge.wrappedCyberSol) as `0x${string}`;
if (!cyberSol) throw new Error("CYBER.sol address not found");

// Amount of CYBER.sol to convert, in human units. Default to a small 1 CYBER.sol.
const amountArg = process.env.AMOUNT ?? "1";
let amountIn = parseEther(amountArg);
// swap() requires amountIn to be an exact multiple of RATE (in wei); round down.
amountIn = (amountIn / RATE) * RATE;
if (amountIn === 0n) throw new Error("amount too small (must be >= 1000 wei of CYBER.sol)");
const amountOut = amountIn / RATE;

const erc20Abi = [
  { name: "balanceOf", type: "function", stateMutability: "view", inputs: [{ name: "a", type: "address" }], outputs: [{ type: "uint256" }] },
  { name: "allowance", type: "function", stateMutability: "view", inputs: [{ name: "o", type: "address" }, { name: "s", type: "address" }], outputs: [{ type: "uint256" }] },
  { name: "approve", type: "function", stateMutability: "nonpayable", inputs: [{ name: "s", type: "address" }, { name: "v", type: "uint256" }], outputs: [{ type: "bool" }] },
] as const;

const swapAbi = [
  { name: "swap", type: "function", stateMutability: "nonpayable", inputs: [{ name: "amountIn", type: "uint256" }], outputs: [] },
] as const;

async function main() {
  console.log("CyberSolSwap conversion (1000 CYBER.sol -> 1 CYBER)");
  console.log("  caller       :", account.address);
  console.log("  swap contract:", swapAddress);
  console.log("  CYBER.sol    :", cyberSol);
  console.log("  amount in    :", formatEther(amountIn), "CYBER.sol");
  console.log("  amount out   :", formatEther(amountOut), "CYBER");

  const [tokenBal, nativeBefore, liquidity, allowance] = await Promise.all([
    publicClient.readContract({ address: cyberSol, abi: erc20Abi, functionName: "balanceOf", args: [account.address] }),
    publicClient.getBalance({ address: account.address }),
    publicClient.getBalance({ address: swapAddress }),
    publicClient.readContract({ address: cyberSol, abi: erc20Abi, functionName: "allowance", args: [account.address, swapAddress] }),
  ]);

  console.log("  your CYBER.sol balance:", formatEther(tokenBal));
  console.log("  contract CYBER liquidity:", formatEther(liquidity));

  if (tokenBal < amountIn) throw new Error("insufficient CYBER.sol balance");
  if (liquidity < amountOut) throw new Error(`contract has insufficient CYBER liquidity: need ${formatEther(amountOut)}, has ${formatEther(liquidity)}`);

  if (allowance < amountIn) {
    console.log("\nApproving CYBER.sol...");
    const approveHash = await walletClient.writeContract({
      address: cyberSol, abi: erc20Abi, functionName: "approve", args: [swapAddress, amountIn],
    });
    console.log("  approve tx:", approveHash);
    const ar = await publicClient.waitForTransactionReceipt({ hash: approveHash });
    if (ar.status !== "success") throw new Error("approve failed");
  } else {
    console.log("\nAllowance already sufficient.");
  }

  console.log("Swapping...");
  const swapHash = await walletClient.writeContract({
    address: swapAddress, abi: swapAbi, functionName: "swap", args: [amountIn],
  });
  console.log("  swap tx:", swapHash);
  const sr = await publicClient.waitForTransactionReceipt({ hash: swapHash });
  if (sr.status !== "success") throw new Error(`swap reverted: ${swapHash}`);

  const nativeAfter = await publicClient.getBalance({ address: account.address });
  console.log("\nSwap status:", sr.status, "gasUsed:", sr.gasUsed.toString());
  console.log("  native CYBER before:", formatEther(nativeBefore));
  console.log("  native CYBER after :", formatEther(nativeAfter));
}

main().catch((error) => {
  console.error(`FATAL: ${error.shortMessage || error.message}`);
  process.exit(1);
});
