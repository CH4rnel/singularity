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

/**
 * The chat providers an operator switches between, in preference order —
 * the single source of truth behind every switch surface (the TUI's /model,
 * the HTTP /provider endpoint, and Lain's own set_chat_provider action).
 */
export const CHAT_PROVIDER_CHOICES: { name: string; kind: string; desc: string }[] = [
  { name: "claude", kind: "claude", desc: "Claude CLI · subscription, no key" },
  { name: "codex", kind: "codex", desc: "Codex CLI · ChatGPT subscription" },
  { name: "opencode", kind: "opencode", desc: "OpenCode CLI · your own OpenCode setup" },
  { name: "claude-api", kind: "anthropic", desc: "Anthropic API key · per token" },
];

/** Operator-facing name (or a raw kind) → model-provider kind. */
export function resolveChatProviderKind(raw: string): string | undefined {
  const name = raw.trim().toLowerCase();
  const choice = CHAT_PROVIDER_CHOICES.find((c) => c.name === name || c.kind === name);
  if (choice) return choice.kind;
  // Kinds without a switch entry are still addressable by their own name.
  return ["openrouter", "mock"].includes(name) ? name : undefined;
}

/** Human label for a kind: "claude" and "anthropic" are the same models. */
export function chatProviderLabel(kind: string): string {
  if (kind === "claude") return "claude (cli)";
  if (kind === "opencode") return "opencode (cli)";
  return kind === "anthropic" ? "claude (anthropic api)" : kind;
}

export interface ChatProviderState {
  /** Active base kind, e.g. "codex" or "anthropic". */
  kind: string;
  /** Name of the active ensemble (may include a fallback suffix). */
  name: string;
  /** Model id that answers LARGE-tier (live chat) requests. */
  model: string;
  /** Base kind the environment would pick with no runtime override. */
  envKind: string;
  /** True when a runtime override (set_chat_provider) diverges from the env. */
  overridden: boolean;
}

/**
 * Mutable front for the live chat ensemble so the operator can re-route
 * replies between providers ("отвечай с помощью Claude") without a restart.
 * The runtime keeps one reference to this object; switchTo swaps what it
 * delegates to and persists the choice so self-upgrade restarts keep it.
 */
export class SwitchableModelProvider implements ModelProvider {
  private active: ModelProvider;
  private kind: string;
  private readonly envKind: string;
  private readonly assemble: (kind: string) => ModelProvider | undefined;
  private readonly persist: (kind: string | null) => void;

  constructor(opts: {
    initial: ModelProvider;
    kind: string;
    envKind: string;
    /** Rebuild the full ensemble (base + fallback + tiers) for a base kind. */
    assemble: (kind: string) => ModelProvider | undefined;
    /** Store the override; null clears it (choice matches the env default). */
    persist: (kind: string | null) => void;
  }) {
    this.active = opts.initial;
    this.kind = opts.kind;
    this.envKind = opts.envKind;
    this.assemble = opts.assemble;
    this.persist = opts.persist;
  }

  get name(): string {
    return this.active.name;
  }

  modelFor(tier: ModelTier): string {
    return this.active.modelFor(tier);
  }

  generate(request: ModelRequest): Promise<ModelResponse> {
    return this.active.generate(request);
  }

  stream(
    request: ModelRequest,
    onText: (delta: string) => void,
  ): Promise<ModelResponse> {
    return callProvider(this.active, request, onText);
  }

  /**
   * Re-route live replies to another base kind. Fails loudly (returns an
   * error string) when the kind cannot be built — a missing key or CLI must
   * never silently land the chat on the mock model.
   */
  switchTo(kind: string): ChatProviderState | string {
    const next = this.assemble(kind);
    if (!next) {
      return `provider "${kind}" is unavailable (missing API key or CLI?) — staying on ${this.kind}`;
    }
    this.active = next;
    this.kind = kind;
    try {
      this.persist(kind === this.envKind ? null : kind);
    } catch (err) {
      log.warn("could not persist chat provider override", err);
    }
    log.info(`live chat provider switched to ${kind} (${next.name})`);
    return this.state();
  }

  state(): ChatProviderState {
    return {
      kind: this.kind,
      name: this.active.name,
      model: this.active.modelFor(ModelTier.LARGE),
      envKind: this.envKind,
      overridden: this.kind !== this.envKind,
    };
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
