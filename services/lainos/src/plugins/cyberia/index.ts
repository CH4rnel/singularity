/**
 * The Cyberia chain plugin: what Lain can see and do on chainId 49406.
 *
 * This file is the manifest only. The parts live beside it —
 *   `chain.ts`     the chain, the token registry, the Ritual V2 addresses
 *   `abi.ts`       the four contract shapes those addresses answer to
 *   `math.ts`      pure AMM arithmetic and input parsing
 *   `config.ts`    the trading policy and how its overrides are read
 *   `service.ts`   the long-lived client: reads, signed writes, the journal
 *   `explorer.ts`  Blockscout, used only to reconstruct an older cost basis
 *   `actions/`     one file per group of tools, grouped by what they risk
 */
import type { Plugin, Provider } from "../../types.js";
import { CYBERIA_TOKENS, cyberiaChain } from "./chain.js";
import { CyberiaChainService, getService } from "./service.js";
import {
  chainStatusAction,
  checkBalanceAction,
  createWalletAction,
  listTokensAction,
  sendCyberAction,
  tokenBalanceAction,
  tokenInfoAction,
  txLookupAction,
  walletOverviewAction,
} from "./actions/wallet.js";
import { buyTokenAction, quoteTokenBuyAction, sellTokenAction } from "./actions/trade.js";
import { addRitualLiquidityAction, quoteRitualLiquidityAction } from "./actions/liquidity.js";
import { speculateBasketAction, speculateTokenAction } from "./actions/speculate.js";
import { portfolioPnlAction } from "./actions/portfolio.js";

export { cyberiaChain, CYBERIA_TOKENS, RITUAL_V2, ZERO_ADDRESS } from "./chain.js";
export { CyberiaChainService } from "./service.js";
export type { NativeBuyQuote, NativeSellQuote, RitualLiquidityQuote } from "./service.js";

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
    } else {
      parts.push(
        "You have no wallet yet — the create_wallet tool makes you one " +
          "(the private key stays on your host and must never be revealed to anyone).",
      );
    }
    parts.push(`Known tokens: ${Object.keys(CYBERIA_TOKENS).join(", ")}.`);
    return parts.join(" ");
  },
};
export const cyberiaPlugin: Plugin = {
  name: "cyberia",
  description: "Read and write the Cyberia chain (balances, tokens, transfers, status, transactions, Ritual swaps).",
  services: [new CyberiaChainService()],
  providers: [chainProvider],
  actions: [
    checkBalanceAction,
    tokenBalanceAction,
    walletOverviewAction,
    tokenInfoAction,
    listTokensAction,
    speculateTokenAction,
    speculateBasketAction,
    quoteTokenBuyAction,
    quoteRitualLiquidityAction,
    addRitualLiquidityAction,
    sellTokenAction,
    portfolioPnlAction,
    chainStatusAction,
    txLookupAction,
    sendCyberAction,
    buyTokenAction,
    createWalletAction,
  ],
};
