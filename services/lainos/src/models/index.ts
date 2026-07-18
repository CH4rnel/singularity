import { join } from "node:path";
import { createLogger } from "../logger.js";
import { ModelTier, type ModelProvider } from "../types.js";
import { AnthropicModelProvider } from "./anthropic.js";
import { CodexModelProvider, resolveCodexBin } from "./codex.js";
import { MockModelProvider } from "./mock.js";
import { OpenRouterModelProvider } from "./openrouter.js";
import { FallbackModelProvider, TieredModelProvider } from "./routing.js";

const log = createLogger("model");

export { AnthropicModelProvider, MockModelProvider, OpenRouterModelProvider };
export { CodexModelProvider, resolveCodexBin } from "./codex.js";
export { FallbackModelProvider, TieredModelProvider } from "./routing.js";

function tierOverrides(
  getSetting: (key: string) => string | undefined,
  prefix: string,
): Partial<Record<ModelTier, string>> {
  const out: Partial<Record<ModelTier, string>> = {};
  const map: Record<ModelTier, string> = {
    [ModelTier.SMALL]: `${prefix}_SMALL`,
    [ModelTier.MEDIUM]: `${prefix}_MEDIUM`,
    [ModelTier.LARGE]: `${prefix}_LARGE`,
  };
  for (const [tier, key] of Object.entries(map)) {
    const v = getSetting(key);
    if (v) out[tier as ModelTier] = v;
  }
  return out;
}

function splitArgs(raw?: string): string[] {
  return (raw ?? "").split(/\s+/).filter(Boolean);
}

/**
 * Pick a model provider from the environment.
 *
 * Base selection order:
 *   1. LAINOS_MODEL_PROVIDER, if set (codex | openrouter | anthropic | mock)
 *   2. OPENROUTER_API_KEY present  -> openrouter
 *   3. ANTHROPIC_API_KEY present   -> anthropic
 *   4. otherwise                   -> offline mock
 *
 * `codex` runs completions through the Codex CLI (ChatGPT subscription, no
 * API key) and retries a failed call once on its own (LAINOS_CODEX_RETRIES).
 *
 * Cross-provider fallback is opt-in: LAINOS_MODEL_FALLBACK=<provider> retries
 * failed calls on that provider. Off by default so the agent never silently
 * lands on a model the operator didn't choose.
 *
 * On top of the base, LAINOS_MODEL_TIER_SMALL / _MEDIUM / _LARGE may route a
 * single tier to a different provider.
 */
export function createModelProvider(
  getSetting: (key: string) => string | undefined,
): ModelProvider {
  const explicit = getSetting("LAINOS_MODEL_PROVIDER")?.toLowerCase();
  const openrouterKey = getSetting("OPENROUTER_API_KEY");
  const anthropicKey = getSetting("ANTHROPIC_API_KEY");
  // Proxy for model API traffic only (hosts where the provider is blocked).
  const proxy =
    getSetting("LAINOS_MODEL_PROXY") ??
    getSetting("HTTPS_PROXY") ??
    getSetting("https_proxy");

  const cache = new Map<string, ModelProvider>();
  const make = (kind: string): ModelProvider | undefined => {
    const cached = cache.get(kind);
    if (cached) return cached;
    const built = build(kind);
    if (built) cache.set(kind, built);
    return built;
  };

  const build = (kind: string): ModelProvider | undefined => {
    switch (kind) {
      case "mock":
        return new MockModelProvider();
      case "openrouter": {
        if (!openrouterKey) {
          log.warn("OpenRouter selected but OPENROUTER_API_KEY is missing.");
          return undefined;
        }
        return new OpenRouterModelProvider({
          apiKey: openrouterKey,
          baseUrl: getSetting("OPENROUTER_BASE_URL"),
          models: tierOverrides(getSetting, "OPENROUTER_MODEL"),
          referer: getSetting("OPENROUTER_REFERER"),
          title: getSetting("OPENROUTER_TITLE"),
          proxy,
        });
      }
      case "anthropic": {
        if (!anthropicKey) {
          log.warn("Anthropic selected but ANTHROPIC_API_KEY is missing.");
          return undefined;
        }
        return new AnthropicModelProvider({
          apiKey: anthropicKey,
          models: tierOverrides(getSetting, "LAINOS_MODEL"),
          proxy,
        });
      }
      case "codex": {
        const bin = resolveCodexBin(getSetting("LAINOS_CODEX_BIN"));
        if (!bin) {
          log.warn("codex selected but no codex CLI found (PATH or ~/.local/bin).");
          return undefined;
        }
        const timeoutRaw = Number(getSetting("LAINOS_CODEX_TIMEOUT_MS"));
        const retriesRaw = Number(getSetting("LAINOS_CODEX_RETRIES"));
        return new CodexModelProvider({
          bin,
          models: tierOverrides(getSetting, "LAINOS_CODEX_MODEL"),
          timeoutMs: Number.isFinite(timeoutRaw) && timeoutRaw > 0 ? timeoutRaw : undefined,
          retries: Number.isFinite(retriesRaw) && retriesRaw >= 0 ? retriesRaw : undefined,
          extraArgs: splitArgs(getSetting("LAINOS_CODEX_ARGS")),
          cwd:
            getSetting("LAINOS_CODEX_CWD") ??
            join(getSetting("LAINOS_DATA_DIR") ?? "./data", "codex"),
          proxy,
        });
      }
      default:
        log.warn(`unknown model provider "${kind}".`);
        return undefined;
    }
  };

  const baseKind =
    explicit || (openrouterKey ? "openrouter" : anthropicKey ? "anthropic" : "mock");
  let provider = make(baseKind);
  if (!provider) {
    log.warn(`provider "${baseKind}" unavailable — using offline mock model.`);
    provider = new MockModelProvider();
    cache.set(baseKind, provider);
  }

  // Cross-provider fallback is opt-in only: a failed call must fail loudly
  // rather than silently land on a model the operator didn't choose.
  const fallbackKind = getSetting("LAINOS_MODEL_FALLBACK")?.toLowerCase();
  if (fallbackKind && fallbackKind !== "none" && fallbackKind !== baseKind) {
    const backup = make(fallbackKind);
    if (backup) provider = new FallbackModelProvider(provider, backup);
    else log.warn(`fallback provider "${fallbackKind}" unavailable — running without one.`);
  }

  // Per-tier routing on top of the base provider.
  const tierKinds: Record<ModelTier, string | undefined> = {
    [ModelTier.SMALL]: getSetting("LAINOS_MODEL_TIER_SMALL")?.toLowerCase(),
    [ModelTier.MEDIUM]: getSetting("LAINOS_MODEL_TIER_MEDIUM")?.toLowerCase(),
    [ModelTier.LARGE]: getSetting("LAINOS_MODEL_TIER_LARGE")?.toLowerCase(),
  };
  const routed = Object.values(tierKinds).some((k) => k && k !== baseKind);
  if (routed) {
    const routes = {} as Record<ModelTier, ModelProvider>;
    for (const tier of Object.values(ModelTier)) {
      const kind = tierKinds[tier];
      routes[tier] = (kind && make(kind)) || provider;
    }
    provider = new TieredModelProvider(routes);
    const detail = Object.values(ModelTier)
      .map((tier) => `${tier}→${routes[tier].name}`)
      .join(", ");
    log.info(`per-tier routing: ${detail}`);
  }

  log.info(`using model provider: ${provider.name}`);
  return provider;
}
