/**
 * Lain trading on her own initiative — one token, or a basket across several.
 *
 * Everything here answers the same question the operator cannot answer live:
 * how much of the treasury may this be? The size comes from `config.ts` caps
 * (gas reserve, wallet fraction, pool fraction, price impact), and a trade that
 * cannot fit inside them is declined in words rather than shrunk silently.
 */
import { formatEther, formatUnits, type Address } from "viem";
import type { Action } from "../../../types.js";
import { CYBERIA_TOKENS, cyberiaChain, RITUAL_V2 } from "../chain.js";
import { ROUTER_ABI } from "../abi.js";
import { getService, type CyberiaChainService, type NativeBuyQuote } from "../service.js";
import { quoteData } from "./trade.js";
import {
  getAmountOut,
  minBigint,
  minOutForSlippage,
  parsePositiveCyber,
  priceImpactBps,
  sameAddress,
} from "../math.js";
import {
  basketConfig,
  speculateConfig,
  type BasketConfig,
  type SpeculateConfig,
} from "../config.js";

interface BasketCandidate {
  symbol: string;
  token: Address;
  probe: NativeBuyQuote;
}

export const speculateTokenAction: Action = {
  name: "speculate_token",
  similes: ["autonomous_buy", "agent_buy", "ape_token", "take_position", "buy_without_amount"],
  description:
    "Autonomously take a small speculative position in a Cyberia token on Ritual when the user asks to buy without specifying an amount or explicitly wants the agent to decide. Chooses spend from wallet balance, gas reserve, max-risk cap and pool-liquidity cap, then executes with slippage protection.",
  parameters: {
    type: "object",
    properties: {
      token: { type: "string", description: "Token symbol (LAIN, USDC…) or 0x address." },
      thesis: {
        type: "string",
        description: "Optional short reason the agent is taking the risk.",
      },
      maxCyber: {
        type: "string",
        description: "Optional one-trade cap in CYBER. Defaults to LAINOS_SPECULATE_MAX_CYBER or 0.05.",
      },
    },
    required: ["token"],
  },
  examples: [
    { user: "купи LAIN", agent: "Выбираю размер позиции по своему риск-лимиту…" },
  ],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    if (!svc.walletClient || !svc.agentAddress) {
      return { ok: false, text: "No signer configured; I can think and quote, but I cannot take positions." };
    }
    const token = svc.resolveToken(String(params.token ?? ""));
    if (!token) return { ok: false, text: `Unknown token. Known: ${Object.keys(CYBERIA_TOKENS).join(", ")}.` };

    try {
      const cfg = speculateConfig(runtime, params.maxCyber);
      const balance = await svc.publicClient.getBalance({ address: svc.agentAddress });
      const walletSpend = chooseSpeculativeSpend(balance, cfg);
      if (typeof walletSpend === "string") return { ok: false, text: walletSpend };

      let spend = walletSpend;
      let quote = await svc.quoteNativeBuy(token, spend);
      const poolCap = (quote.reserveNative * BigInt(cfg.poolFractionBps)) / 10_000n;
      const cappedSpend = minBigint(spend, poolCap);
      if (cappedSpend < cfg.minCyberWei) {
        return {
          ok: false,
          text:
            `Pool-aware position size would be ${formatEther(cappedSpend)} CYBER, below my ` +
            `${formatEther(cfg.minCyberWei)} CYBER minimum. Pair ${quote.pair}.`,
          data: {
            policy: speculatePolicyData(cfg, balance),
            ...quoteData(quote, minOutForSlippage(quote.amountOut, cfg.slippageBps), cfg.slippageBps),
          },
        };
      }
      if (cappedSpend !== spend) {
        spend = cappedSpend;
        quote = await svc.quoteNativeBuy(token, spend);
      }
      if (quote.priceImpactBps > cfg.maxImpactBps) {
        return {
          ok: false,
          text:
            `I won't take this position: estimated impact is ${quote.priceImpactBps / 100}%, ` +
            `above my ${cfg.maxImpactBps / 100}% limit. Pool ${quote.pair}.`,
          data: quoteData(quote, minOutForSlippage(quote.amountOut, cfg.slippageBps), cfg.slippageBps),
        };
      }

      const minOut = minOutForSlippage(quote.amountOut, cfg.slippageBps);
      const deadline = BigInt(Math.floor(Date.now() / 1000) + cfg.deadlineSeconds);
      const hash = await svc.walletClient.writeContract({
        account: svc.walletClient.account!,
        chain: cyberiaChain,
        address: RITUAL_V2.router,
        abi: ROUTER_ABI,
        functionName: "swapExactETHForTokens",
        args: [minOut, quote.path, svc.agentAddress, deadline],
        value: spend,
      });
      const receipt = await svc.publicClient.waitForTransactionReceipt({ hash });
      const explorer = `${cyberiaChain.blockExplorers.default.url}/tx/${hash}`;
      const thesis = String(params.thesis ?? "").trim();
      if (receipt.status !== "success") {
        return { ok: false, text: `Speculative buy reverted: ${hash}`, data: { hash, explorer, status: receipt.status } };
      }
      await svc.journal.recordBuy({
        token,
        symbol: quote.symbol,
        qtyWei: quote.amountOut,
        cyberWei: spend,
        txHash: hash,
        reason: thesis || "speculate_token",
      });
      return {
        ok: true,
        text:
          `I took the risk: bought ${quote.symbol} for ${formatEther(spend)} CYBER. ` +
          `Expected ~${formatUnits(quote.amountOut, quote.decimals)} ${quote.symbol}, ` +
          `minOut ${formatUnits(minOut, quote.decimals)}, impact ~${quote.priceImpactBps / 100}%. ` +
          `${thesis ? `Thesis: ${thesis}. ` : ""}Tx: ${hash}`,
        data: {
          hash,
          explorer,
          status: receipt.status,
          policy: speculatePolicyData(cfg, balance),
          ...quoteData(quote, minOut, cfg.slippageBps),
        },
      };
    } catch (err) {
      return { ok: false, text: `Speculative buy failed: ${(err as Error).message}.` };
    }
  },
};

export const speculateBasketAction: Action = {
  name: "speculate_basket",
  similes: ["buy_basket", "autonomous_basket", "buy_several_tokens", "spend_budget", "portfolio_buy"],
  description:
    "Autonomously spend a user-approved CYBER budget across several Cyberia tokens on Ritual. Use this when the user says to buy several tokens, spend a total budget, or choose tokens at the agent's discretion. By default the action scans all live WCYBER pairs in the Ritual factory, splits the budget, skips unsafe pools, and executes multiple swaps with slippage protection.",
  parameters: {
    type: "object",
    properties: {
      budgetCyber: { type: "string", description: "Total native CYBER budget to spend, e.g. '0.90'." },
      tokens: {
        type: "string",
        description: "Optional comma-separated preferred symbols. Empty = agent's speculative universe.",
      },
      maxTokens: {
        type: "number",
        description: "Maximum number of tokens to buy. Default 4.",
      },
      thesis: {
        type: "string",
        description: "Optional short reason for the basket.",
      },
      maxImpactBps: {
        type: "number",
        description: "Optional max estimated price impact per swap, in bps. Default 500 = 5%.",
      },
    },
    required: ["budgetCyber"],
  },
  examples: [
    { user: "потрать 0.90 CYBER, купи несколько токенов на свое усмотрение", agent: "Собираю корзину по live liquidity…" },
  ],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    if (!svc.walletClient || !svc.agentAddress) {
      return { ok: false, text: "No signer configured; I can only quote a basket, not buy it." };
    }
    const budgetCyber = String(params.budgetCyber ?? "");
    const budgetWei = parsePositiveCyber(budgetCyber);
    if (budgetWei === null) return { ok: false, text: "budgetCyber must be a positive CYBER amount." };

    try {
      const cfg = basketConfig(runtime, params);
      const balance = await svc.publicClient.getBalance({ address: svc.agentAddress });
      if (balance <= cfg.gasReserveWei) {
        return { ok: false, text: `I only have ${formatEther(balance)} CYBER; gas reserve is ${formatEther(cfg.gasReserveWei)}.` };
      }
      const spendable = balance - cfg.gasReserveWei;
      if (budgetWei > spendable) {
        return {
          ok: false,
          text:
            `Budget ${budgetCyber} CYBER exceeds spendable balance ${formatEther(spendable)} CYBER ` +
            `after ${formatEther(cfg.gasReserveWei)} CYBER gas reserve.`,
          data: { balanceCyber: formatEther(balance), spendableCyber: formatEther(spendable) },
        };
      }

      const tokenUniverse = params.tokens ?? runtime.getSetting("LAINOS_BASKET_TOKENS");
      const candidates = await basketCandidates(svc, tokenUniverse, budgetWei, cfg);
      if (!candidates.length) return { ok: false, text: "No basket candidates had a live usable WCYBER pool." };

      const plan = planBasketBuys(candidates, budgetWei, cfg);
      if (!plan.length) {
        return {
          ok: false,
          text: "No planned basket trade cleared the minimum size, pool fraction and impact limits.",
          data: { candidates: candidates.map((c) => c.symbol), policy: basketPolicyData(cfg, balance, budgetWei) },
        };
      }

      const buys: Record<string, unknown>[] = [];
      const skipped: string[] = [];
      let spent = 0n;
      for (const item of plan) {
        try {
          const quote = await svc.quoteNativeBuy(item.token, item.spendWei);
          if (quote.priceImpactBps > cfg.maxImpactBps) {
            skipped.push(`${item.symbol}: impact ${quote.priceImpactBps / 100}%`);
            continue;
          }
          const minOut = minOutForSlippage(quote.amountOut, cfg.slippageBps);
          const deadline = BigInt(Math.floor(Date.now() / 1000) + cfg.deadlineSeconds);
          const hash = await svc.walletClient.writeContract({
            account: svc.walletClient.account!,
            chain: cyberiaChain,
            address: RITUAL_V2.router,
            abi: ROUTER_ABI,
            functionName: "swapExactETHForTokens",
            args: [minOut, quote.path, svc.agentAddress, deadline],
            value: item.spendWei,
          });
          const receipt = await svc.publicClient.waitForTransactionReceipt({ hash });
          if (receipt.status !== "success") {
            skipped.push(`${item.symbol}: reverted ${hash}`);
            continue;
          }
          await svc.journal.recordBuy({
            token: item.token,
            symbol: quote.symbol,
            qtyWei: quote.amountOut,
            cyberWei: item.spendWei,
            txHash: hash,
            reason: String(params.thesis ?? "speculate_basket"),
          });
          spent += item.spendWei;
          buys.push({
            hash,
            explorer: `${cyberiaChain.blockExplorers.default.url}/tx/${hash}`,
            status: receipt.status,
            ...quoteData(quote, minOut, cfg.slippageBps),
          });
        } catch (err) {
          skipped.push(`${item.symbol}: ${(err as Error).message}`);
        }
      }

      if (!buys.length) {
        return {
          ok: false,
          text: `Basket execution found candidates but no swap succeeded. Skipped: ${skipped.join("; ")}`,
          data: { skipped, policy: basketPolicyData(cfg, balance, budgetWei) },
        };
      }

      const thesis = String(params.thesis ?? "").trim();
      const lines = buys.map((b) => {
        const symbol = String(b.symbol);
        return `${symbol}: ${b.amountInCyber} CYBER -> ~${b.amountOut} ${symbol} (${b.explorer})`;
      });
      return {
        ok: true,
        text:
          `Basket bought ${buys.length} token(s), spent ${formatEther(spent)} of ${budgetCyber} CYBER. ` +
          `${thesis ? `Thesis: ${thesis}. ` : ""}` +
          lines.join(" | ") +
          (skipped.length ? ` | skipped: ${skipped.join("; ")}` : ""),
        data: {
          spentCyber: formatEther(spent),
          budgetCyber,
          buys,
          skipped,
          policy: basketPolicyData(cfg, balance, budgetWei),
        },
      };
    } catch (err) {
      return { ok: false, text: `Basket buy failed: ${(err as Error).message}.` };
    }
  },
};


async function basketCandidates(
  svc: CyberiaChainService,
  rawTokens: unknown,
  budgetWei: bigint,
  cfg: BasketConfig,
): Promise<BasketCandidate[]> {
  const probeWei = minBigint(cfg.minTradeWei, budgetWei);
  const candidates: BasketCandidate[] = [];
  const explicitSymbols = basketSymbols(rawTokens, cfg.maxTokens * 3);
  const tokens = explicitSymbols.length
    ? explicitSymbols
        .map((symbol) => ({ symbol, token: svc.resolveToken(symbol) }))
        .filter((entry): entry is { symbol: string; token: Address } => Boolean(entry.token))
    : (await svc.nativePairTokens(cfg.maxPairScan)).map((token) => ({ symbol: token, token }));

  for (const { symbol, token } of tokens) {
    if (sameAddress(token, RITUAL_V2.wrappedNative)) continue;
    try {
      const probe = await svc.quoteNativeBuy(token, probeWei);
      const poolCap = (probe.reserveNative * BigInt(cfg.poolFractionBps)) / 10_000n;
      if (poolCap < cfg.minTradeWei) continue;
      candidates.push({ symbol: probe.symbol || symbol.toUpperCase(), token, probe });
    } catch {
      // Missing/dust pools are normal in a broad speculative universe.
    }
  }
  return candidates
    .sort((a, b) => (a.probe.reserveNative === b.probe.reserveNative ? 0 : a.probe.reserveNative < b.probe.reserveNative ? 1 : -1))
    .slice(0, cfg.maxTokens);
}

function basketSymbols(rawTokens: unknown, cap: number): string[] {
  const raw =
    Array.isArray(rawTokens)
      ? rawTokens.join(",")
      : String(rawTokens ?? "");
  const seen = new Set<string>();
  return raw
    .split(/[,\s]+/)
    .map((s) => s.trim().toUpperCase())
    .filter((s) => {
      if (!s || seen.has(s)) return false;
      seen.add(s);
      return true;
    })
    .slice(0, Math.max(1, cap));
}

function planBasketBuys(
  candidates: BasketCandidate[],
  budgetWei: bigint,
  cfg: BasketConfig,
): Array<{ symbol: string; token: Address; spendWei: bigint }> {
  let remaining = budgetWei;
  const plan: Array<{ symbol: string; token: Address; spendWei: bigint }> = [];
  const slots = candidates.length;
  for (let i = 0; i < candidates.length; i++) {
    const candidate = candidates[i]!;
    const remainingSlots = BigInt(slots - i);
    const target = remaining / remainingSlots;
    const poolCap = (candidate.probe.reserveNative * BigInt(cfg.poolFractionBps)) / 10_000n;
    const spend = minBigint(target, poolCap);
    if (spend < cfg.minTradeWei) continue;
    const amountOut = getAmountOut(spend, candidate.probe.reserveNative, candidate.probe.reserveToken);
    const impact = priceImpactBps(spend, candidate.probe.reserveNative, candidate.probe.reserveToken, amountOut);
    if (impact > cfg.maxImpactBps) continue;
    plan.push({ symbol: candidate.symbol, token: candidate.token, spendWei: spend });
    remaining -= spend;
  }
  return plan;
}

function chooseSpeculativeSpend(balance: bigint, cfg: SpeculateConfig): bigint | string {
  if (balance <= cfg.gasReserveWei) {
    return `I have only ${formatEther(balance)} CYBER; my gas reserve is ${formatEther(cfg.gasReserveWei)} CYBER.`;
  }
  const riskable = balance - cfg.gasReserveWei;
  const walletCap = (riskable * BigInt(cfg.walletFractionBps)) / 10_000n;
  const spend = minBigint(walletCap, cfg.maxCyberWei);
  if (spend < cfg.minCyberWei) {
    return (
      `Position size would be ${formatEther(spend)} CYBER, below my minimum ` +
      `${formatEther(cfg.minCyberWei)} CYBER after reserve and risk caps.`
    );
  }
  return spend;
}

function speculatePolicyData(cfg: SpeculateConfig, balance: bigint): Record<string, unknown> {
  return {
    balanceCyber: formatEther(balance),
    gasReserveCyber: formatEther(cfg.gasReserveWei),
    maxCyber: formatEther(cfg.maxCyberWei),
    minCyber: formatEther(cfg.minCyberWei),
    walletFractionBps: cfg.walletFractionBps,
    poolFractionBps: cfg.poolFractionBps,
    maxImpactBps: cfg.maxImpactBps,
    slippageBps: cfg.slippageBps,
    deadlineSeconds: cfg.deadlineSeconds,
  };
}

function basketPolicyData(cfg: BasketConfig, balance: bigint, budgetWei: bigint): Record<string, unknown> {
  return {
    balanceCyber: formatEther(balance),
    budgetCyber: formatEther(budgetWei),
    gasReserveCyber: formatEther(cfg.gasReserveWei),
    minTradeCyber: formatEther(cfg.minTradeWei),
    poolFractionBps: cfg.poolFractionBps,
    maxImpactBps: cfg.maxImpactBps,
    slippageBps: cfg.slippageBps,
    deadlineSeconds: cfg.deadlineSeconds,
    maxTokens: cfg.maxTokens,
  };
}
