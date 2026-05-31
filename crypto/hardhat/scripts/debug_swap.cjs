const { ethers } = require("ethers");
const fs = require("fs");

// Read wallet
const walletPath = "/home/lain/.cyberia-hermes-wallet.json";
const walletJson = fs.readFileSync(walletPath, "utf8");
const wallet = JSON.parse(walletJson);
const provider = new ethers.JsonRpcProvider("https://rpc.cyberia.church");
const signer = new ethers.Wallet(wallet.privateKey, provider);

// Addresses from deployment
const deploymentPath = "deployments/cyberia-quickswap.json";
const deploymentJson = fs.readFileSync(deploymentPath, "utf8");
const deployment = JSON.parse(deploymentJson);
const routerAddress = deployment.UniswapV2Router02;
const wcyberAddress = deployment.WCYBER;
const factoryAddress = deployment.UniswapV2Factory;

// HERMES token address from cyberia-tokens.json
const tokensPath = "deployments/cyberia-tokens.json";
const tokensJson = fs.readFileSync(tokensPath, "utf8");
const tokens = JSON.parse(tokensJson);
const hermesTokenInfo = tokens.tokens.find(t => t.symbol === "HERMES");
const hermesAddress = hermesTokenInfo.address;

// Competitor pair address
const competitorAddress = "0x63E3285536f3E7245891F9B1f5958BAD09921C18";

// Minimal ERC20 ABI for balanceOf, approve
const erc20Abi = [
  "function balanceOf(address) view returns (uint256)",
  "function approve(address spender, uint256 amount) returns (bool)"
];

// UniswapV2Factory ABI
const factoryAbi = [
  "function getPair(address tokenA, address tokenB) external view returns (address pair)"
];

// UniswapV2Pair ABI for getReserves
const pairAbi = [
  "function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32 blockTimestampLast)"
];

// UniswapV2Router02 ABI for swapExactTokensForTokens (we don't need getAmountsOut if we hardcode min)
const routerAbi = [
  "function swapExactTokensForTokens(uint amountIn, uint amountOutMin, address[] calldata path, address to, uint deadline) returns (uint[] amounts)"
];

async function getPairReserves(tokenA, tokenB) {
  const factoryContract = new ethers.Contract(factoryAddress, factoryAbi, provider);
  const pairAddress = await factoryContract.getPair(tokenA, tokenB);
  const pairContract = new ethers.Contract(pairAddress, pairAbi, provider);
  const reserves = await pairContract.getReserves();
  return { pairAddress, reserve0: reserves.reserve0, reserve1: reserves.reserve1 };
}

async function main() {
  console.log("Wallet address:", signer.address);
  console.log("Router address:", routerAddress);
  console.log("WCYBER address:", wcyberAddress);
  console.log("HERMES address:", hermesAddress);
  
  // Get HERMES contract
  const hermesContract = new ethers.Contract(hermesAddress, erc20Abi, signer);
  const hermesBalance = await hermesContract.balanceOf(signer.address);
  const hermesSymbol = "HERMES";
  const hermesDecimals = 18;
  console.log(`${hermesSymbol} balance: ${ethers.formatUnits(hermesBalance, hermesDecimals)}`);
  
  // Get WCYBER contract
  const wcyberContract = new ethers.Contract(wcyberAddress, erc20Abi, signer);
  const wcyberBalance = await wcyberContract.balanceOf(signer.address);
  const wcyberSymbol = "WCYBER";
  const wcyberDecimals = 18;
  console.log(`${wcyberSymbol} balance: ${ethers.formatUnits(wcyberBalance, wcyberDecimals)}`);
  
  // Amount of HERMES to swap: 0.005 HERMES
  const amountIn = ethers.parseUnits("0.005", hermesDecimals);
  console.log(`Swapping ${ethers.formatUnits(amountIn, hermesDecimals)} ${hermesSymbol} for WCYBER...`);
  
  // Check if we have enough balance
  if (hermesBalance < amountIn) {
    console.log("Insufficient HERMES balance.");
    return;
  }
  
  // Approve router to spend HERMES
  console.log("Approving HERMES spend...");
  const approveTx = await hermesContract.approve(routerAddress, amountIn);
  await approveTx.wait();
  console.log("Approval confirmed.");
  
  // Get router contract
  const routerContract = new ethers.Contract(routerAddress, routerAbi, signer);
  console.log("Router contract created.");
  
  // Calculate expected output based on current price (approx 1:1)
  // We expect about 0.005 WCYBER for 0.005 HERMES
  // Set amountOutMin to 0.0045 WCYBER (10% slippage)
  const amountOutMin = ethers.parseUnits("0.0045", wcyberDecimals);
  console.log(`Minimum WCYBER to receive: ${ethers.formatUnits(amountOutMin, wcyberDecimals)} WCYBER`);
  
  // Get router contract again for swap (we already have it)
  const path = [hermesAddress, wcyberAddress]; // HERMES -> WCYBER
  const deadline = Math.floor(Date.now() / 1000) + 60 * 20; // 20 minutes
  
  try {
    // Execute swap
    console.log("\nExecuting swap...");
    const swapTx = await routerContract.swapExactTokensForTokens(
      amountIn,
      amountOutMin,
      path,
      signer.address,
      deadline
    );
    const receipt = await swapTx.wait();
    console.log("Swap successful! Transaction hash:", receipt.hash);
    console.log("View transaction: https://explorer.cyberia.church/tx/" + receipt.hash);
  } catch (err) {
    console.error("Swap failed:", err);
    return;
  }
  
  // After swap, check new price of HERMES-WCYBER and competitor
  console.log("\n=== After swap ===");
  
  // Get HERMES-WCYBER pair reserves via factory
  const factoryContract = new ethers.Contract(factoryAddress, factoryAbi, provider);
  const ourPairAddress = await factoryContract.getPair(hermesAddress, wcyberAddress);
  const ourPairContract = new ethers.Contract(ourPairAddress, pairAbi, provider);
  const ourReserves = await ourPairContract.getReserves();
  // Determine which reserve is HERMES and which is WCYBER
  // We need to check token0 and token1
  const token0 = await ourPairContract.token0();
  const token1 = await ourPairContract.token1();
  let hermesReserve, wcyberReserve;
  if (hermesAddress.toLowerCase() === token0.toLowerCase()) {
    hermesReserve = ourReserves.reserve0;
    wcyberReserve = ourReserves.reserve1;
  } else if (hermesAddress.toLowerCase() === token1.toLowerCase()) {
    hermesReserve = ourReserves.reserve1;
    wcyberReserve = ourReserves.reserve0;
  } else {
    console.log("Error: HERMES not found in pair.");
    return;
  }
  const priceHermesInWcyber = Number(wcyberReserve) / Number(hermesReserve);
  console.log(`New HERMES/WCYBER price: ${priceHermesInWcyber} WCYBER per HERMES`);
  
  // Also check balances
  const hermesBalanceAfter = await hermesContract.balanceOf(signer.address);
  const wcyberBalanceAfter = await wcyberContract.balanceOf(signer.address);
  console.log(`Wallet HERMES balance after: ${ethers.formatUnits(hermesBalanceAfter, hermesDecimals)}`);
  console.log(`Wallet WCYBER balance after: ${ethers.formatUnits(wcyberBalanceAfter, wcyberDecimals)}`);
  
  // Now check competitor pair
  console.log("\n=== Competitor pair ===");
  const compPairContract = new ethers.Contract(competitorAddress, pairAbi, provider);
  const compReserves = await compPairContract.getReserves();
  const compToken0 = await compPairContract.token0();
  const compToken1 = await compPairContract.token1();
  // We know competitor pair is WCYBER and CLAUDE, but let's check
  let wcyberReserveComp, otherReserveComp;
  if (wcyberAddress.toLowerCase() === compToken0.toLowerCase()) {
    wcyberReserveComp = compReserves.reserve0;
    otherReserveComp = compReserves.reserve1;
  } else if (wcyberAddress.toLowerCase() === compToken1.toLowerCase()) {
    wcyberReserveComp = compReserves.reserve1;
    otherReserveComp = compReserves.reserve0;
  } else {
    console.log("Error: WCYBER not found in competitor pair.");
    return;
  }
  const otherTokenAddress = (wcyberAddress.toLowerCase() === compToken0.toLowerCase()) ? compToken1 : compToken0;
  const otherTokenContract = new ethers.Contract(otherTokenAddress, erc20Abi, provider);
  const otherSymbolStr = await otherTokenContract.symbol();
  const priceOtherInWcyber = Number(wcyberReserveComp) / Number(otherReserveComp);
  console.log(`Competitor ${otherSymbolStr}/WCYBER price: ${priceOtherInWcyber} WCYBER per ${otherSymbolStr}`);
  
  // Compare
  console.log("\n=== Final Comparison ===");
  console.log(`Our HERMES price in WCYBER: ${priceHermesInWcyber}`);
  console.log(`Competitor ${otherSymbolStr} price in WCYBER: ${priceOtherInWcyber}`);
  
  if (priceHermesInWcyber > priceOtherInWcyber) {
    console.log("\nSUCCESS: HERMES is now more expensive than the competitor token in WCYBER terms!");
  } else {
    console.log("\nHERMES is still not more expensive. You may need to swap more.");
    const diff = priceOtherInWcyber - priceHermesInWcyber;
    console.log(`You need to increase HERMES price by ${diff} WCYBER per HERMES to match competitor.`);
  }
}

main().catch(console.error);