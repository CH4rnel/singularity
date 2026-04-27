import "dotenv/config";
import hardhatToolboxViemPlugin from "@nomicfoundation/hardhat-toolbox-viem";
import { configVariable, defineConfig } from "hardhat/config";

const DEPLOYER_PK = process.env.DEPLOYER_PK;

if (!DEPLOYER_PK) {
  throw new Error("DEPLOYER_PK is not set in .env");
}

export default defineConfig({
  plugins: [hardhatToolboxViemPlugin],
  solidity: {
    profiles: {
      default: {
        compilers: [
          { version: "0.8.19" },
          {
            version: "0.6.6",
            settings: {
              optimizer: { enabled: true, runs: 200 },
            },
          },
          {
            version: "0.5.16",
            settings: {
              optimizer: { enabled: true, runs: 200 },
            },
          },
        ],
      },
      production: {
        compilers: [
          {
            version: "0.8.19",
            settings: {
              optimizer: { enabled: true, runs: 200 },
            },
          },
          {
            version: "0.6.6",
            settings: {
              optimizer: { enabled: true, runs: 200 },
            },
          },
          {
            version: "0.5.16",
            settings: {
              optimizer: { enabled: true, runs: 200 },
            },
          },
        ],
      },
    },
  },
  networks: {
    hardhatMainnet: {
      type: "edr-simulated",
      chainType: "l1",
    },
    hardhatOp: {
      type: "edr-simulated",
      chainType: "op",
    },
    sepolia: {
      type: "http",
      chainType: "l1",
      url: configVariable("SEPOLIA_RPC_URL"),
      accounts: [configVariable("SEPOLIA_PRIVATE_KEY")],
    },
    cyberia: {
      type: "http",
      url: "http://195.166.164.94:8545",
      chainId: 49406,
      accounts: [DEPLOYER_PK],
    },
  },
});
