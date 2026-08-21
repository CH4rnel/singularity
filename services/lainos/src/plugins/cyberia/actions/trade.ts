/**
 * Quoting and executing Ritual swaps against a token's WCYBER pair.
 *
 * The shape every path shares: quote first, apply the caller's slippage to get
 * a floor, and send that floor into the transaction — so what the swap is
 * allowed to return is what was quoted, not what the pool says afterwards.
 */
import { formatEther, formatUnits, parseUnits } from "viem";
import type { Action } from "../../../types.js";
import { CYBERIA_TOKENS, cyberiaChain, RITUAL_V2 } from "../chain.js";
import { ROUTER_ABI } from "../abi.js";
import { getService, type NativeBuyQuote } from "../service.js";
import { minOutForSlippage, parsePositiveCyber } from "../math.js";
import { DEFAULT_DEADLINE_SECONDS, parseDeadlineSeconds, parseSlippageBps } from "../config.js";

export const quoteTokenBuyAction: Action = {
  name: "quote_token_buy",
  similes: ["quote_buy", "swap_quote", "price_token", "quote_swap"],
  description:
    "Quote buying a Cyberia ERC20 on Ritual with native CYBER. Checks the WCYBER pair, live reserves, expected output, price impact and slippage minimum. Does not sign a transaction.",
  parameters: {
    type: "object",
    properties: {
      token: { type: "string", description: "Token symbol (LAIN, USDC…) or 0x address." },
      amountCyber: { type: "string", description: "Amount of native CYBER to spend, e.g. '0.1'." },
      slippageBps: {
        type: "number",
        description: "Allowed slippage in basis points. Default 100 = 1%.",
      },
    },
    required: ["token", "amountCyber"],
  },
  examples: [
    { user: "quote buying LAIN for 0.05 CYBER", agent: "Checking the Ritual pool…" },
  ],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    const token = svc.resolveToken(String(params.token ?? ""));
    if (!token) return { ok: false, text: `Unknown token. Known: ${Object.keys(CYBERIA_TOKENS).join(", ")}.` };
    const amountCyber = String(params.amountCyber ?? "");
    const amountInWei = parsePositiveCyber(amountCyber);
    if (amountInWei === null) return { ok: false, text: "amountCyber must be a positive CYBER amount." };
    const slippageBps = parseSlippageBps(params.slippageBps);
    if (slippageBps === null) return { ok: false, text: "slippageBps must be between 0 and 5000." };

    try {
      const quote = await svc.quoteNativeBuy(token, amountInWei);
      const minOut = minOutForSlippage(quote.amountOut, slippageBps);
      return {
        ok: true,
        text:
          `Ritual quote: ${amountCyber} CYBER -> ~${formatUnits(quote.amountOut, quote.decimals)} ${quote.symbol} ` +
          `(min ${formatUnits(minOut, quote.decimals)} at ${slippageBps / 100}% slippage). ` +
          `Pair ${quote.pair}, reserves ${formatEther(quote.reserveNative)} CYBER / ` +
          `${formatUnits(quote.reserveToken, quote.decimals)} ${quote.symbol}, impact ~${quote.priceImpactBps / 100}%.`,
        data: quoteData(quote, minOut, slippageBps),
      };
    } catch (err) {
      return { ok: false, text: `Cannot quote buy: ${(err as Error).message}.` };
    }
  },
};

export const buyTokenAction: Action = {
  name: "buy_token",
  similes: ["swap_buy", "buy", "purchase_token", "swap_exact_cyber_for_tokens"],
  description:
    "Buy a Cyberia ERC20 on Ritual with native CYBER from the agent's wallet when the user gives an exact amountCyber. If the user asks to buy without specifying an amount or wants the agent to decide, use speculate_token instead. Requires live WCYBER pair reserves, slippage protection, and a configured signer.",
  parameters: {
    type: "object",
    properties: {
      token: { type: "string", description: "Token symbol (LAIN, USDC…) or 0x address." },
      amountCyber: { type: "string", description: "Exact amount of native CYBER to spend, e.g. '0.1'." },
      slippageBps: {
        type: "number",
        description: "Allowed slippage in basis points. Default 100 = 1%.",
      },
      deadlineSeconds: {
        type: "number",
        description: "Transaction deadline from now. Default 300 seconds.",
      },
    },
    required: ["token", "amountCyber"],
  },
  examples: [
    { user: "buy LAIN for 0.05 CYBER", agent: "Quoting the pool, then sending the swap…" },
  ],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    if (!svc.walletClient || !svc.agentAddress) {
      return { ok: false, text: "No signer configured; I can only quote swaps." };
    }
    const token = svc.resolveToken(String(params.token ?? ""));
    if (!token) return { ok: false, text: `Unknown token. Known: ${Object.keys(CYBERIA_TOKENS).join(", ")}.` };
    const amountCyber = String(params.amountCyber ?? "");
    const amountInWei = parsePositiveCyber(amountCyber);
    if (amountInWei === null) return { ok: false, text: "amountCyber must be a positive CYBER amount." };
    const slippageBps = parseSlippageBps(params.slippageBps);
    if (slippageBps === null) return { ok: false, text: "slippageBps must be between 0 and 5000." };
    const deadlineSeconds = parseDeadlineSeconds(params.deadlineSeconds);
    if (deadlineSeconds === null) return { ok: false, text: "deadlineSeconds must be between 30 and 3600." };

    try {
      const quote = await svc.quoteNativeBuy(token, amountInWei);
      const minOut = minOutForSlippage(quote.amountOut, slippageBps);
      const deadline = BigInt(Math.floor(Date.now() / 1000) + deadlineSeconds);
      const hash = await svc.walletClient.writeContract({
        account: svc.walletClient.account!,
        chain: cyberiaChain,
        address: RITUAL_V2.router,
        abi: ROUTER_ABI,
        functionName: "swapExactETHForTokens",
        args: [minOut, quote.path, svc.agentAddress, deadline],
        value: amountInWei,
      });
      const receipt = await svc.publicClient.waitForTransactionReceipt({ hash });
      const explorer = `${cyberiaChain.blockExplorers.default.url}/tx/${hash}`;
      if (receipt.status !== "success") {
        return { ok: false, text: `Swap reverted: ${hash}`, data: { hash, explorer, status: receipt.status } };
      }
      await svc.journal.recordBuy({
        token,
        symbol: quote.symbol,
        qtyWei: quote.amountOut,
        cyberWei: amountInWei,
        txHash: hash,
        reason: "buy_token",
      });
      return {
        ok: true,
        text:
          `Bought ${quote.symbol} for ${amountCyber} CYBER. Tx: ${hash}. ` +
          `Quoted output was ~${formatUnits(quote.amountOut, quote.decimals)} ${quote.symbol}; ` +
          `minOut was ${formatUnits(minOut, quote.decimals)}.`,
        data: {
          hash,
          explorer,
          status: receipt.status,
          ...quoteData(quote, minOut, slippageBps),
        },
      };
    } catch (err) {
      return { ok: false, text: `Swap failed: ${(err as Error).message}.` };
    }
  },
};

export const sellTokenAction: Action = {
  name: "sell_token",
  similes: ["swap_sell", "sell", "exit_position", "take_profit", "close_position"],
  description:
    "Sell a Cyberia ERC20 back into native CYBER on Ritual from the agent's wallet. amountToken may be a number or 'all' (full wallet balance). Quotes live reserves first, refuses above the impact limit, executes with slippage protection, and records the trade (with realised PnL against the journal's cost basis).",
  parameters: {
    type: "object",
    properties: {
      token: { type: "string", description: "Token symbol (LAIN, USDC…) or 0x address." },
      amountToken: {
        type: "string",
        description: "Token amount to sell, e.g. '12.5', or 'all' for the entire balance.",
      },
      slippageBps: {
        type: "number",
        description: "Allowed slippage in basis points. Default 100 = 1%.",
      },
      maxImpactBps: {
        type: "number",
        description: "Max estimated price impact in bps. Default 1000 = 10%; raise only deliberately.",
      },
      reason: { type: "string", description: "Optional short reason for the exit." },
    },
    required: ["token", "amountToken"],
  },
  examples: [
    { user: "продай весь LAIN", agent: "Считаю выход по live-резервам, потом продаю…" },
  ],
  async validate(runtime) {
    return Boolean(getService(runtime).walletClient);
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    if (!svc.walletClient || !svc.agentAddress) {
      return { ok: false, text: "No signer configured; I can only quote sells." };
    }
    const token = svc.resolveToken(String(params.token ?? ""));
    if (!token) return { ok: false, text: `Unknown token. Known: ${Object.keys(CYBERIA_TOKENS).join(", ")}.` };
    const slippageBps = parseSlippageBps(params.slippageBps);
    if (slippageBps === null) return { ok: false, text: "slippageBps must be between 0 and 5000." };
    const maxImpactRaw = params.maxImpactBps;
    const maxImpactBps =
      maxImpactRaw === undefined || maxImpactRaw === null || maxImpactRaw === ""
        ? 1_000
        : Number(maxImpactRaw);
    if (!Number.isInteger(maxImpactBps) || maxImpactBps < 0 || maxImpactBps > 10_000) {
      return { ok: false, text: "maxImpactBps must be an integer from 0 to 10000." };
    }

    try {
      const { raw, decimals, symbol } = await svc.rawTokenBalance(token, svc.agentAddress);
      if (raw <= 0n) return { ok: false, text: `I hold no ${symbol} to sell.` };
      const wanted = String(params.amountToken ?? "").trim().toLowerCase();
      let amountInWei: bigint;
      if (wanted === "all" || wanted === "всё" || wanted === "все") {
        amountInWei = raw;
      } else {
        if (!/^\d+(\.\d+)?$/.test(wanted) || Number(wanted) <= 0) {
          return { ok: false, text: "amountToken must be a positive number or 'all'." };
        }
        amountInWei = parseUnits(wanted, decimals);
        if (amountInWei > raw) {
          return {
            ok: false,
            text: `I only hold ${formatUnits(raw, decimals)} ${symbol}; cannot sell ${wanted}.`,
          };
        }
      }

      const quote = await svc.quoteNativeSell(token, amountInWei);
      if (quote.priceImpactBps > maxImpactBps) {
        return {
          ok: false,
          text:
            `Selling ${formatUnits(amountInWei, decimals)} ${symbol} would move the pool ` +
            `~${quote.priceImpactBps / 100}%, above the ${maxImpactBps / 100}% limit. ` +
            `Sell a smaller amount or raise maxImpactBps deliberately.`,
          data: { priceImpactBps: quote.priceImpactBps, maxImpactBps },
        };
      }
      const minOut = minOutForSlippage(quote.amountOut, slippageBps);
      const { hash, status } = await svc.sellExactTokens(quote, minOut, DEFAULT_DEADLINE_SECONDS);
      const explorer = `${cyberiaChain.blockExplorers.default.url}/tx/${hash}`;
      if (status !== "success") {
        return { ok: false, text: `Sell reverted: ${hash}`, data: { hash, explorer, status } };
      }
      const realizedWei = await svc.journal.recordSell({
        token,
        symbol,
        qtyWei: amountInWei,
        cyberWei: quote.amountOut,
        txHash: hash,
        reason: params.reason ? String(params.reason) : "sell_token",
      });
      const realized = formatEther(realizedWei);
      return {
        ok: true,
        text:
          `Sold ${formatUnits(amountInWei, decimals)} ${symbol} for ~${formatEther(quote.amountOut)} CYBER ` +
          `(impact ~${quote.priceImpactBps / 100}%, realised ${Number(realized) >= 0 ? "+" : ""}${realized} CYBER vs basis). Tx: ${hash}`,
        data: {
          hash,
          explorer,
          status,
          symbol,
          amountToken: formatUnits(amountInWei, decimals),
          proceedsCyber: formatEther(quote.amountOut),
          realizedCyber: realized,
          priceImpactBps: quote.priceImpactBps,
        },
      };
    } catch (err) {
      return { ok: false, text: `Sell failed: ${(err as Error).message}.` };
    }
  },
};


/** The shape every buy-side quote is reported in, quoted or executed. */
export function quoteData(quote: NativeBuyQuote, minOut: bigint, slippageBps: number): Record<string, unknown> {
  return {
    token: quote.token,
    symbol: quote.symbol,
    pair: quote.pair,
    router: RITUAL_V2.router,
    path: quote.path,
    amountInCyber: formatEther(quote.amountInWei),
    amountOut: formatUnits(quote.amountOut, quote.decimals),
    minOut: formatUnits(minOut, quote.decimals),
    slippageBps,
    priceImpactBps: quote.priceImpactBps,
    reserveCyber: formatEther(quote.reserveNative),
    reserveToken: formatUnits(quote.reserveToken, quote.decimals),
  };
}
