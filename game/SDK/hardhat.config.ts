import "dotenv/config";

import { defineConfig } from "hardhat/config";

const cyberiaPrivateKey = process.env.CYBERIA_PRIVATE_KEY;

export default defineConfig({
  solidity: {
    profiles: {
      default: {
        version: "0.8.28",
      },
      production: {
        version: "0.8.28",
        settings: {
          optimizer: {
            enabled: true,
            runs: 200,
          },
        },
      },
    },
  },
  networks: {
    hardhatMainnet: {
      type: "edr-simulated",
      chainType: "l1",
      chainId: 31337,
    },
    cyberia: {
      type: "http",
      chainType: "l1",
      chainId: 49406,
      url: process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church",
      accounts: cyberiaPrivateKey === undefined ? [] : [cyberiaPrivateKey],
    },
  },
});
