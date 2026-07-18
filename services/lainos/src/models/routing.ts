import { createLogger } from "../logger.js";
import {
  ModelTier,
  type ModelProvider,
  type ModelRequest,
  type ModelResponse,
} from "../types.js";

const log = createLogger("model:routing");

/**
 * Try the primary provider, and when a call fails, retry it on the backup so
 * one dead subscription/CLI never silences the agent. Built for codex (a CLI
 * that can hit rate limits or an expired login) backed by OpenRouter.
 */
export class FallbackModelProvider implements ModelProvider {
  readonly name: string;

  constructor(
    private primary: ModelProvider,
    private backup: ModelProvider,
  ) {
    this.name = `${primary.name}+${backup.name}`;
  }

  modelFor(tier: ModelTier): string {
    return this.primary.modelFor(tier);
  }

  async generate(request: ModelRequest): Promise<ModelResponse> {
    try {
      return await this.primary.generate(request);
    } catch (err) {
      log.warn(`${this.primary.name} failed — falling back to ${this.backup.name}`, err);
      return this.backup.generate(request);
    }
  }

  async stream(
    request: ModelRequest,
    onText: (delta: string) => void,
  ): Promise<ModelResponse> {
    // Once the primary has emitted visible deltas a silent switch would
    // duplicate text, so only fall back on failures that produced nothing.
    let emitted = false;
    const guarded = (delta: string) => {
      emitted = true;
      onText(delta);
    };
    try {
      return await callProvider(this.primary, request, guarded);
    } catch (err) {
      if (emitted) throw err;
      log.warn(`${this.primary.name} failed — falling back to ${this.backup.name}`, err);
      return callProvider(this.backup, request, onText);
    }
  }
}

/**
 * Route each {@link ModelTier} to its own provider, e.g. the main chat (LARGE)
 * through codex while cheap background work (scout digests use MEDIUM) stays
 * on OpenRouter. Configured via LAINOS_MODEL_TIER_* in createModelProvider.
 */
export class TieredModelProvider implements ModelProvider {
  readonly name: string;

  constructor(private routes: Record<ModelTier, ModelProvider>) {
    // The LARGE route answers the main chat, so it names the ensemble; a
    // joined all-tiers name overflows the TUI boot card. createModelProvider
    // logs the full per-tier map instead.
    this.name = routes[ModelTier.LARGE].name;
  }

  modelFor(tier: ModelTier): string {
    return this.routes[tier].modelFor(tier);
  }

  generate(request: ModelRequest): Promise<ModelResponse> {
    return this.routes[request.tier].generate(request);
  }

  stream(
    request: ModelRequest,
    onText: (delta: string) => void,
  ): Promise<ModelResponse> {
    return callProvider(this.routes[request.tier], request, onText);
  }
}

/** Stream when the provider can, otherwise generate and emit the text whole. */
async function callProvider(
  provider: ModelProvider,
  request: ModelRequest,
  onText: (delta: string) => void,
): Promise<ModelResponse> {
  if (provider.stream) return provider.stream(request, onText);
  const res = await provider.generate(request);
  if (res.text) onText(res.text);
  return res;
}
