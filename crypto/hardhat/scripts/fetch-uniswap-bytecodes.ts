/**
 * Fetch canonical UniswapV2 contract bytecodes from Ethereum mainnet.
 * 
 * This reads the deployed bytecodes directly from the Ethereum RPC
 * (the creation bytecode can also be obtained from Etherscan verified sources).
 *
 * For deployment, we need the CREATION bytecode (constructor + runtime).
 * The easiest way is to use the verified source from Etherscan/GitHub.
 * 
 * This script provides the canonical creation bytecodes as hex strings.
 * Source: https://github.com/Uniswap/v2-core / https://github.com/Uniswap/v2-periphery
 *
 * Usage: npx tsx scripts/fetch-uniswap-bytecodes.ts
 *
 * NOTE: The bytecodes below are the canonical Uniswap V2 creation bytecodes.
 * They are large (~15KB each) so they are fetched from npm packages.
 */

import * as fs from "fs";
import * as path from "path";

// The canonical UniswapV2Factory creation bytecode
// Source: @uniswap/v2-core/build/UniswapV2Factory.json
// The factory constructor takes 1 arg: address _feeToSetter
//
// Since we can't easily import the npm package in this Hardhat 3 project,
// we'll provide instructions for obtaining the bytecodes.

const BYTECODES_DIR = path.join(process.cwd(), "bytecodes");

function printInstructions() {
  console.log(`
╔══════════════════════════════════════════════════════════════════════╗
║          How to obtain UniswapV2 canonical bytecodes                ║
╠══════════════════════════════════════════════════════════════════════╣
║                                                                      ║
║  Option A: From npm (recommended)                                    ║
║                                                                      ║
║    npm install --no-save @uniswap/v2-core @uniswap/v2-periphery     ║
║                                                                      ║
║    Then run this script again — it will extract the bytecodes.       ║
║                                                                      ║
║  Option B: From Etherscan                                            ║
║                                                                      ║
║    Factory: https://etherscan.io/address/                            ║
║      0x5C69bEe701ef814a2B6a3EDD4B1652CB9cc5aA6f#code                ║
║                                                                      ║
║    Router02: https://etherscan.io/address/                           ║
║      0x7a250d5630B4cF539739dF2C5dAcb4c659F2488D#code                ║
║                                                                      ║
║    Copy the "Contract Creation Code" and save as:                    ║
║      bytecodes/UniswapV2Factory.json  →  { "bytecode": "0x..." }    ║
║      bytecodes/UniswapV2Router02.json →  { "bytecode": "0x..." }    ║
║                                                                      ║
╚══════════════════════════════════════════════════════════════════════╝
`);
}

async function main() {
  // Try to extract from node_modules if available
  const factoryArtifact = "./node_modules/@uniswap/v2-core/build/UniswapV2Factory.json";
  const routerArtifact = "./node_modules/@uniswap/v2-periphery/build/UniswapV2Router02.json";

  if (!fs.existsSync(BYTECODES_DIR)) {
    fs.mkdirSync(BYTECODES_DIR, { recursive: true });
  }

  let extracted = 0;

  if (fs.existsSync(factoryArtifact)) {
    const artifact = JSON.parse(fs.readFileSync(factoryArtifact, "utf8"));
    const bytecode = artifact.bytecode;
    fs.writeFileSync(
      path.join(BYTECODES_DIR, "UniswapV2Factory.json"),
      JSON.stringify({ bytecode }, null, 2)
    );
    console.log("✓ Extracted UniswapV2Factory bytecode");
    extracted++;
  } else {
    console.log("✗ @uniswap/v2-core not installed — cannot extract Factory bytecode");
  }

  if (fs.existsSync(routerArtifact)) {
    const artifact = JSON.parse(fs.readFileSync(routerArtifact, "utf8"));
    const bytecode = artifact.bytecode;
    fs.writeFileSync(
      path.join(BYTECODES_DIR, "UniswapV2Router02.json"),
      JSON.stringify({ bytecode }, null, 2)
    );
    console.log("✓ Extracted UniswapV2Router02 bytecode");
    extracted++;
  } else {
    console.log("✗ @uniswap/v2-periphery not installed — cannot extract Router bytecode");
  }

  if (extracted < 2) {
    printInstructions();
    console.log("Run:  npm install --no-save @uniswap/v2-core @uniswap/v2-periphery");
    console.log("Then: npx tsx scripts/fetch-uniswap-bytecodes.ts");
  } else {
    console.log(`\nAll bytecodes saved to ${BYTECODES_DIR}/`);
    console.log("You can now run:  npx tsx scripts/deploy-dex.ts");
  }
}

main().catch(console.error);
