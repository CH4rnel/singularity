import {
  createPublicClient,
  createWalletClient,
  defineChain,
  formatEther,
  formatUnits,
  http,
  isAddress,
  parseEther,
  type Address,
  type PublicClient,
  type WalletClient,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { createLogger } from "../../logger.js";
import type {
  Action,
  IAgentRuntime,
  Plugin,
  Provider,
  Service,
} from "../../types.js";

const log = createLogger("plugin:cyberia");

/** Cyberia EVM chain (chainId 49406, native CYBER). */
export const cyberiaChain = defineChain({
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
  rpcUrls: { default: { http: ["https://rpc.cyberia.church"] } },
  blockExplorers: {
    default: { name: "Blockscout", url: "https://explorer.cyberia.church" },
  },
});

/** On-chain ERC20 registry (real Cyberia deployments). Extend as needed. */
export const CYBERIA_TOKENS: Record<string, Address> = {
  USDC: "0xdc25597B19799010047F17e9591EFE08EFd40077",
  USDT: "0x94845aF24a3E431593A2b941b2b31836dE45185D",
  BTC: "0x9332081f308BC978fe259237850fA253131b46Fa",
  LTC: "0x001AFD19C9d890b0cf0fcd6D654f9BFe4f264F14",
  SOL: "0x53450B1d205f1e41d10B653FBBDEa74160dafFf4",
  RUB: "0x3cE7d8E486E16baD2Fb1487Fe1da4dC33237d923",
  SILVER: "0xAd9dfef9D671aFCF29Dbdd7Df360E7cA8D5ac40b",
};

const ERC20_ABI = [
  {
    type: "function",
    name: "balanceOf",
    stateMutability: "view",
    inputs: [{ name: "owner", type: "address" }],
    outputs: [{ name: "", type: "uint256" }],
  },
  {
    type: "function",
    name: "decimals",
    stateMutability: "view",
    inputs: [],
    outputs: [{ name: "", type: "uint8" }],
  },
  {
    type: "function",
    name: "symbol",
    stateMutability: "view",
    inputs: [],
    outputs: [{ name: "", type: "string" }],
  },
  {
    type: "function",
    name: "totalSupply",
    stateMutability: "view",
    inputs: [],
    outputs: [{ name: "", type: "uint256" }],
  },
] as const;

/** Long-lived chain client; exposes read/write helpers to actions. */
export class CyberiaChainService implements Service {
  readonly name = "cyberia-chain";
  public publicClient!: PublicClient;
  public walletClient?: WalletClient;
  public agentAddress?: Address;

  async start(runtime: IAgentRuntime): Promise<void> {
    const rpc = runtime.getSetting("CYBERIA_RPC_URL") ?? cyberiaChain.rpcUrls.default.http[0];
    this.publicClient = createPublicClient({
      chain: cyberiaChain,
      transport: http(rpc),
    });

    const pk = runtime.getSetting("CYBERIA_AGENT_PK");
    if (pk && /^0x[0-9a-fA-F]{64}$/.test(pk)) {
      const account = privateKeyToAccount(pk as Address);
      this.agentAddress = account.address;
      this.walletClient = createWalletClient({
        account,
        chain: cyberiaChain,
        transport: http(rpc),
      });
      log.info(`signer enabled for ${account.address}`);
    } else {
      log.info("read-only mode (no CYBERIA_AGENT_PK).");
    }
  }

  resolveToken(token: string): Address | undefined {
    if (isAddress(token)) return token as Address;
    return CYBERIA_TOKENS[token.toUpperCase()];
  }

  async nativeBalance(address: Address): Promise<string> {
    const wei = await this.publicClient.getBalance({ address });
    return formatEther(wei);
  }

  async tokenBalance(
    token: Address,
    address: Address,
  ): Promise<{ amount: string; symbol: string }> {
    const [raw, decimals, symbol] = await Promise.all([
      this.publicClient.readContract({
        address: token,
        abi: ERC20_ABI,
        functionName: "balanceOf",
        args: [address],
      }),
      this.publicClient.readContract({
        address: token,
        abi: ERC20_ABI,
        functionName: "decimals",
      }),
      this.publicClient
        .readContract({ address: token, abi: ERC20_ABI, functionName: "symbol" })
        .catch(() => "TOKEN"),
    ]);
    return { amount: formatUnits(raw as bigint, Number(decimals)), symbol: symbol as string };
  }

  async tokenInfo(
    token: Address,
  ): Promise<{ symbol: string; decimals: number; supply: string }> {
    const [symbol, decimals, supplyRaw] = await Promise.all([
      this.publicClient
        .readContract({ address: token, abi: ERC20_ABI, functionName: "symbol" })
        .catch(() => "TOKEN"),
      this.publicClient.readContract({ address: token, abi: ERC20_ABI, functionName: "decimals" }),
      this.publicClient.readContract({ address: token, abi: ERC20_ABI, functionName: "totalSupply" }),
    ]);
    const dec = Number(decimals);
    return { symbol: symbol as string, decimals: dec, supply: formatUnits(supplyRaw as bigint, dec) };
  }
}

function getService(runtime: IAgentRuntime): CyberiaChainService {
  const svc = runtime.getService<CyberiaChainService>("cyberia-chain");
  if (!svc) throw new Error("cyberia-chain service not started");
  return svc;
}

const chainProvider: Provider = {
  name: "cyberia_chain",
  async get(runtime) {
    const svc = getService(runtime);
    const parts = [`Cyberia chain (id ${cyberiaChain.id}), native token CYBER.`];
    try {
      const block = await svc.publicClient.getBlockNumber();
      parts.push(`Latest block: ${block}.`);
    } catch {
      parts.push("RPC currently unreachable.");
    }
    if (svc.agentAddress) {
      parts.push(`Your wallet: ${svc.agentAddress}.`);
      try {
        const bal = await svc.nativeBalance(svc.agentAddress);
        parts.push(`Your CYBER balance: ${bal}.`);
      } catch {
        /* ignore */
      }
    }
    parts.push(`Known tokens: ${Object.keys(CYBERIA_TOKENS).join(", ")}.`);
    return parts.join(" ");
  },
};

const checkBalanceAction: Action = {
  name: "check_balance",
  similes: ["get_balance", "cyber_balance", "native_balance"],
  description: "Read the native CYBER balance of an address on Cyberia.",
  parameters: {
    type: "object",
    properties: {
      address: {
        type: "string",
        description: "0x EVM address. Defaults to the agent's own wallet.",
      },
    },
  },
  examples: [
    { user: "What's the CYBER balance of 0xabc...?", agent: "Checking the chain…" },
  ],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    const address = (params.address as string) ?? svc.agentAddress;
    if (!address || !isAddress(address)) {
      return { ok: false, text: "I need a valid 0x address to check." };
    }
    const balance = await svc.nativeBalance(address as Address);
    return {
      ok: true,
      text: `${address} holds ${balance} CYBER.`,
      data: { address, balance, symbol: "CYBER" },
    };
  },
};

const tokenBalanceAction: Action = {
  name: "token_balance",
  similes: ["erc20_balance", "get_token_balance"],
  description:
    "Read an ERC20 token balance on Cyberia. Token may be a symbol (USDC, BTC, …) or a 0x address.",
  parameters: {
    type: "object",
    properties: {
      token: { type: "string", description: "Token symbol or 0x address." },
      address: { type: "string", description: "Holder address. Defaults to the agent." },
    },
    required: ["token"],
  },
  examples: [
    { user: "How much USDC does 0xabc... have?", agent: "Reading the token contract…" },
  ],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    const token = svc.resolveToken(String(params.token ?? ""));
    if (!token) {
      return {
        ok: false,
        text: `Unknown token. Known: ${Object.keys(CYBERIA_TOKENS).join(", ")}.`,
      };
    }
    const holder = (params.address as string) ?? svc.agentAddress;
    if (!holder || !isAddress(holder)) {
      return { ok: false, text: "I need a valid holder 0x address." };
    }
    const { amount, symbol } = await svc.tokenBalance(token, holder as Address);
    return {
      ok: true,
      text: `${holder} holds ${amount} ${symbol}.`,
      data: { token, holder, amount, symbol },
    };
  },
};

const sendCyberAction: Action = {
  name: "send_cyber",
  similes: ["transfer_cyber", "pay", "send_native"],
  description:
    "Transfer native CYBER from the agent's wallet to an address. Requires a configured signer.",
  parameters: {
    type: "object",
    properties: {
      to: { type: "string", description: "Recipient 0x address." },
      amount: { type: "string", description: "Amount of CYBER, e.g. '1.5'." },
    },
    required: ["to", "amount"],
  },
  examples: [{ user: "Send 1 CYBER to 0xabc...", agent: "Broadcasting the transfer…" }],
  async validate(runtime) {
    return Boolean(getService(runtime).walletClient);
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    if (!svc.walletClient || !svc.agentAddress) {
      return { ok: false, text: "No signer configured; I can only read the chain." };
    }
    const to = String(params.to ?? "");
    const amount = String(params.amount ?? "");
    if (!isAddress(to)) return { ok: false, text: "Recipient is not a valid address." };
    if (!/^\d+(\.\d+)?$/.test(amount) || Number(amount) <= 0) {
      return { ok: false, text: "Amount must be a positive number." };
    }
    const hash = await svc.walletClient.sendTransaction({
      account: svc.walletClient.account!,
      chain: cyberiaChain,
      to: to as Address,
      value: parseEther(amount),
    });
    return {
      ok: true,
      text: `Sent ${amount} CYBER to ${to}. Tx: ${hash}`,
      data: { hash, to, amount },
    };
  },
};

const chainStatusAction: Action = {
  name: "chain_status",
  similes: ["network_status", "latest_block", "block_number", "gas_price"],
  description: "Report Cyberia network status: latest block height and gas price.",
  parameters: { type: "object", properties: {} },
  examples: [{ user: "what's the latest block on cyberia?", agent: "Reading the chain head…" }],
  async validate() {
    return true;
  },
  async handler(runtime) {
    const svc = getService(runtime);
    const [block, gas] = await Promise.all([
      svc.publicClient.getBlockNumber(),
      svc.publicClient.getGasPrice().catch(() => 0n),
    ]);
    const gwei = formatUnits(gas, 9);
    return {
      ok: true,
      text: `Cyberia (chain ${cyberiaChain.id}) is at block ${block}, gas ~${gwei} gwei.`,
      data: { chainId: cyberiaChain.id, block: block.toString(), gasGwei: gwei },
    };
  },
};

const walletOverviewAction: Action = {
  name: "wallet_overview",
  similes: ["portfolio", "holdings", "wallet_balances", "overview"],
  description: "Summarise an address's Cyberia holdings: native CYBER plus every known token it holds.",
  parameters: {
    type: "object",
    properties: {
      address: { type: "string", description: "0x address. Defaults to the agent's own wallet." },
    },
  },
  examples: [{ user: "what does 0xabc… hold on cyberia?", agent: "Pulling the whole portfolio…" }],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    const address = (params.address as string) ?? svc.agentAddress;
    if (!address || !isAddress(address)) {
      return { ok: false, text: "I need a valid 0x address to look up." };
    }
    const native = await svc.nativeBalance(address as Address);
    const holdings: Record<string, string> = { CYBER: native };
    const entries = await Promise.all(
      Object.entries(CYBERIA_TOKENS).map(async ([sym, addr]) => {
        try {
          const { amount } = await svc.tokenBalance(addr, address as Address);
          return [sym, amount] as const;
        } catch {
          return [sym, "0"] as const;
        }
      }),
    );
    for (const [sym, amount] of entries) {
      if (Number(amount) > 0) holdings[sym] = amount;
    }
    const summary = Object.entries(holdings)
      .map(([s, a]) => `${a} ${s}`)
      .join(", ");
    return { ok: true, text: `${address} holds: ${summary}.`, data: { address, holdings } };
  },
};

const txLookupAction: Action = {
  name: "tx_lookup",
  similes: ["get_transaction", "tx_status", "lookup_tx", "check_tx"],
  description: "Look up a Cyberia transaction by hash: status, sender, recipient and value.",
  parameters: {
    type: "object",
    properties: { hash: { type: "string", description: "0x transaction hash (64 hex chars)." } },
    required: ["hash"],
  },
  examples: [{ user: "is tx 0x123… confirmed?", agent: "Checking the receipt…" }],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    const hash = String(params.hash ?? "");
    if (!/^0x[0-9a-fA-F]{64}$/.test(hash)) {
      return { ok: false, text: "That isn't a valid 0x transaction hash." };
    }
    try {
      const tx = await svc.publicClient.getTransaction({ hash: hash as `0x${string}` });
      const receipt = await svc.publicClient
        .getTransactionReceipt({ hash: hash as `0x${string}` })
        .catch(() => null);
      const value = formatEther(tx.value);
      const status = receipt ? (receipt.status === "success" ? "confirmed" : "reverted") : "pending";
      const explorer = `${cyberiaChain.blockExplorers.default.url}/tx/${hash}`;
      return {
        ok: true,
        text: `Tx ${hash.slice(0, 10)}… is ${status}: ${value} CYBER from ${tx.from} to ${tx.to ?? "(contract creation)"}.`,
        data: {
          hash,
          status,
          from: tx.from,
          to: tx.to,
          value,
          block: tx.blockNumber ? tx.blockNumber.toString() : null,
          explorer,
        },
      };
    } catch {
      return { ok: false, text: `No transaction found for ${hash.slice(0, 10)}… (it may not have propagated yet).` };
    }
  },
};

const tokenInfoAction: Action = {
  name: "token_info",
  similes: ["token_metadata", "token_supply", "supply"],
  description: "Read a Cyberia token's symbol, decimals, total supply and contract address.",
  parameters: {
    type: "object",
    properties: { token: { type: "string", description: "Token symbol (USDC, BTC…) or 0x address." } },
    required: ["token"],
  },
  examples: [{ user: "what's the supply of USDC on cyberia?", agent: "Reading the token contract…" }],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    const token = svc.resolveToken(String(params.token ?? ""));
    if (!token) {
      return { ok: false, text: `Unknown token. Known: ${Object.keys(CYBERIA_TOKENS).join(", ")}.` };
    }
    const info = await svc.tokenInfo(token);
    return {
      ok: true,
      text: `${info.symbol}: ${info.supply} total supply, ${info.decimals} decimals, at ${token}.`,
      data: { token, ...info },
    };
  },
};

const listTokensAction: Action = {
  name: "list_tokens",
  similes: ["known_tokens", "tokens", "token_registry"],
  description: "List the tokens LainOS knows on Cyberia, with their contract addresses.",
  parameters: { type: "object", properties: {} },
  examples: [{ user: "what tokens live on cyberia?", agent: "Here's the registry…" }],
  async validate() {
    return true;
  },
  async handler() {
    return {
      ok: true,
      text: `Known Cyberia tokens: ${Object.keys(CYBERIA_TOKENS).join(", ")}.`,
      data: { tokens: CYBERIA_TOKENS },
    };
  },
};

export const cyberiaPlugin: Plugin = {
  name: "cyberia",
  description: "Read and write the Cyberia chain (balances, tokens, transfers, status, transactions).",
  services: [new CyberiaChainService()],
  providers: [chainProvider],
  actions: [
    checkBalanceAction,
    tokenBalanceAction,
    walletOverviewAction,
    tokenInfoAction,
    listTokensAction,
    chainStatusAction,
    txLookupAction,
    sendCyberAction,
  ],
};
