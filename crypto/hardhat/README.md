# Sample Hardhat 3 Beta Project (`node:test` and `viem`)

This project showcases a Hardhat 3 Beta project using the native Node.js test runner (`node:test`) and the `viem` library for Ethereum interactions.

To learn more about the Hardhat 3 Beta, please visit the [Getting Started guide](https://hardhat.org/docs/getting-started#getting-started-with-hardhat-3). To share your feedback, join our [Hardhat 3 Beta](https://hardhat.org/hardhat3-beta-telegram-group) Telegram group or [open an issue](https://github.com/NomicFoundation/hardhat/issues/new) in our GitHub issue tracker.

## Project Overview

This example project includes:

- A simple Hardhat configuration file.
- Foundry-compatible Solidity unit tests.
- TypeScript integration tests using [`node:test`](nodejs.org/api/test.html), the new Node.js native test runner, and [`viem`](https://viem.sh/).
- Examples demonstrating how to connect to different types of networks, including locally simulating OP mainnet.

## Usage

### Running Tests

To run all the tests in the project, execute the following command:

```shell
npx hardhat test
```

You can also selectively run the Solidity or `node:test` tests:

```shell
npx hardhat test solidity
npx hardhat test nodejs
```

### Make a deployment to Sepolia

This project includes an example Ignition module to deploy the contract. You can deploy this module to a locally simulated chain or to Sepolia.

To run the deployment to a local chain:

```shell
npx hardhat ignition deploy ignition/modules/Counter.ts
```

To run the deployment to Sepolia, you need an account with funds to send the transaction. The provided Hardhat configuration includes a Configuration Variable called `SEPOLIA_PRIVATE_KEY`, which you can use to set the private key of the account you want to use.

You can set the `SEPOLIA_PRIVATE_KEY` variable using the `hardhat-keystore` plugin or by setting it as an environment variable.

To set the `SEPOLIA_PRIVATE_KEY` config variable using `hardhat-keystore`:

```shell
npx hardhat keystore set SEPOLIA_PRIVATE_KEY
```

After setting the variable, you can run the deployment with the Sepolia network:

```shell
npx hardhat ignition deploy --network sepolia ignition/modules/Counter.ts
```

## Deploying USDC / USDT to Cyberia

`contracts/USDC.sol` and `contracts/USDT.sol` are 6-decimal ERC20s with
`mint` / `burnFrom` gated by `Ownable`. They mirror canonical USDC / USDT and
are meant to be driven by the Cyberia bridge / relayer (the owner). Both also
include `ERC20Permit` so wallets and the DEX can use gasless approvals.

### 1. Prerequisites

- `crypto/hardhat/.env` must contain the deployer key:

  ```env
  DEPLOYER_PK=0x...        # deployer private key (NEVER commit, NEVER paste in chat)
  # Optional overrides:
  # CYBERIA_RPC_URL=https://rpc.cyberia.church
  # USDC_OWNER=0x...       # final USDC owner / minter; defaults to deployer
  # USDT_OWNER=0x...       # final USDT owner / minter; defaults to deployer
  ```

  Do not echo, `cat`, or otherwise print `.env` contents — especially
  `DEPLOYER_PK`.

- Deployer address must hold enough CYBER on chain id `49406` to pay gas.

### 2. Install & compile

```shell
cd crypto/hardhat
npm install
npx hardhat compile
```

This produces `artifacts/contracts/USDC.sol/USDC.json` and
`artifacts/contracts/USDT.sol/USDT.json`, which the deploy scripts read.

### 3. Deploy to Cyberia

USDC:

```shell
cd crypto/hardhat
npx hardhat run scripts/deploy-usdc.ts --network cyberia
```

USDT:

```shell
cd crypto/hardhat
npx hardhat run scripts/deploy-usdt.ts --network cyberia
```

Each script will:

1. Load `DEPLOYER_PK` from `.env`.
2. Connect to Cyberia RPC (`CYBERIA_RPC_URL` or `https://rpc.cyberia.church`,
   chain id `49406`).
3. Deploy the token with constructor arg `initialOwner = USDC_OWNER` /
   `USDT_OWNER` (or the deployer if unset).
4. Print the deployed address, block number, and gas used.

Expected output (USDC, USDT is analogous):

```
Deploying USDC...
  Deployer: 0x....
  Initial owner / minter: 0x....
  RPC: https://rpc.cyberia.church
Transaction hash: 0x....
USDC deployed at: 0x....
Block: ...
Gas used: ...
```

### 4. After deployment

- Verify the contracts on Blockscout: <https://explorer.cyberia.church>
  (Solidity `0.8.19`, optimizer settings as in `hardhat.config.ts` `production`
  profile if you redeploy with `--profile production`).
- Save the deployed addresses (e.g. in `deployments/` or the Ritual DEX
  config).
- The owner (`USDC_OWNER` / `USDT_OWNER`) can now call `mint(to, amount)` to
  credit bridged stablecoins, and `burnFrom(from, amount)` to redeem them.

### 5. Minting USDC / USDT

Use `scripts/mint-stable.ts`. It works for both tokens; you just point it at
the right deployed address and tell it which token via `TOKEN`.

Required env vars (can be exported inline; `.env` is loaded but inline `KEY=…`
on the command line wins):

| Var            | Meaning                                     |
| -------------- | ------------------------------------------- |
| `DEPLOYER_PK`  | Owner / minter private key (from `.env`)    |
| `TOKEN`        | `USDC` or `USDT`                            |
| `TOKEN_ADDRESS`| Deployed token address on Cyberia           |
| `MINT_TO`      | Recipient address                           |
| `MINT_AMOUNT`  | Human amount, e.g. `1000` (= 1000 USDC/USDT)|

The script reads `decimals()` on-chain (`6` for both), converts the human
amount via `parseUnits`, and refuses to send if the caller is not the token
owner.

Mint 1,000 USDC:

```shell
cd crypto/hardhat
TOKEN=USDC \
TOKEN_ADDRESS=0xUsdcAddressOnCyberia \
MINT_TO=0xRecipient \
MINT_AMOUNT=1000 \
npx hardhat run scripts/mint-stable.ts --network cyberia
```

Mint 1,000 USDT:

```shell
cd crypto/hardhat
TOKEN=USDT \
TOKEN_ADDRESS=0xUsdtAddressOnCyberia \
MINT_TO=0xRecipient \
MINT_AMOUNT=1000 \
npx hardhat run scripts/mint-stable.ts --network cyberia
```

Expected output:

```
Token: USDC @ 0x....
Decimals: 6
Caller: 0x....
Token owner: 0x....
Mint to: 0x....
Amount: 1000 USDC (1000000000 base units)
Transaction hash: 0x....
Status: success
Gas used: ...
Recipient balance: 1000 USDC
```

> Do **not** put `DEPLOYER_PK` on the command line — keep it only in `.env`.
> Never paste `.env` contents or private keys into logs, chats, or commits.

### 6. Operational notes

- `decimals()` returns `6` to match canonical USDC / USDT. Always work in
  micro-units (`1 USDC = 1_000_000`, same for USDT).
- `mint` and the privileged `burnFrom` path require `msg.sender == owner()`.
- Non-owner users can still `burn(amount)` their own balance and
  `burnFrom(from, amount)` via standard ERC20 allowance.
- If you need to rotate the bridge owner, call `transferOwnership(newOwner)`
  from the current owner.
