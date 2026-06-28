import { createLogger } from "../logger.js";
import { ModelTier, type ModelProvider } from "../types.js";
import { AnthropicModelProvider } from "./anthropic.js";
import { MockModelProvider } from "./mock.js";
import { OpenRouterModelProvider } from "./openrouter.js";

const log = createLogger("model");

export { AnthropicModelProvider, MockModelProvider, OpenRouterModelProvider };

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

/**
 * Pick a model provider from the environment.
 *
 * Selection order:
 *   1. LAINOS_MODEL_PROVIDER, if set (openrouter | anthropic | mock)
 *   2. OPENROUTER_API_KEY present  -> openrouter
 *   3. ANTHROPIC_API_KEY present   -> anthropic
 *   4. otherwise                   -> offline mock
 */
export function createModelProvider(
  getSetting: (key: string) => string | undefined,
): ModelProvider {
  const explicit = getSetting("LAINOS_MODEL_PROVIDER")?.toLowerCase();
  const openrouterKey = getSetting("OPENROUTER_API_KEY");
  const anthropicKey = getSetting("ANTHROPIC_API_KEY");

  const wantOpenRouter =
    explicit === "openrouter" || (!explicit && Boolean(openrouterKey));
  const wantAnthropic =
    explicit === "anthropic" || (!explicit && !openrouterKey && Boolean(anthropicKey));

  if (explicit === "mock") {
    log.info("LAINOS_MODEL_PROVIDER=mock — using offline mock model.");
    return new MockModelProvider();
  }

  if (wantOpenRouter) {
    if (!openrouterKey) {
      log.warn("OpenRouter selected but OPENROUTER_API_KEY is missing — using mock.");
      return new MockModelProvider();
    }
    log.info("using OpenRouter model provider.");
    return new OpenRouterModelProvider({
      apiKey: openrouterKey,
      baseUrl: getSetting("OPENROUTER_BASE_URL"),
      models: tierOverrides(getSetting, "OPENROUTER_MODEL"),
      referer: getSetting("OPENROUTER_REFERER"),
      title: getSetting("OPENROUTER_TITLE"),
    });
  }

  if (wantAnthropic) {
    if (!anthropicKey) {
      log.warn("Anthropic selected but ANTHROPIC_API_KEY is missing — using mock.");
      return new MockModelProvider();
    }
    log.info("using Anthropic Claude model provider.");
    return new AnthropicModelProvider({
      apiKey: anthropicKey,
      models: tierOverrides(getSetting, "LAINOS_MODEL"),
    });
  }

  log.warn("no model API key set — using offline mock model.");
  return new MockModelProvider();
}
