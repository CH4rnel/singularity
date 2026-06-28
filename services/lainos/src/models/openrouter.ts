import { createLogger } from "../logger.js";
import {
  ModelTier,
  type ModelProvider,
  type ModelRequest,
  type ModelResponse,
} from "../types.js";

const log = createLogger("model:openrouter");

/**
 * Default model per tier. `openrouter/free` is OpenRouter's "Free Models Router":
 * a $0 meta-model that randomly routes to an available free model and filters
 * for ones supporting the requested params (so tool calling keeps working for
 * the cyberia plugin's on-chain actions). One slug, no rate-limit babysitting.
 *
 * Trade-off: the underlying model varies per request, so quality is uneven.
 * Override with OPENROUTER_MODEL_* to pin a specific model, e.g.:
 *   anthropic/claude-opus-4.8 · anthropic/claude-sonnet-4.6 · anthropic/claude-haiku-4.5
 */
export const DEFAULT_OPENROUTER_MODELS: Record<ModelTier, string> = {
  [ModelTier.SMALL]: "openrouter/free",
  [ModelTier.MEDIUM]: "openrouter/free",
  [ModelTier.LARGE]: "openrouter/free",
};

export interface OpenRouterProviderOptions {
  apiKey: string;
  baseUrl?: string;
  models?: Partial<Record<ModelTier, string>>;
  /** Optional attribution headers OpenRouter shows on its dashboard. */
  referer?: string;
  title?: string;
}

// Minimal shapes of the OpenAI-compatible chat completion response.
interface ORToolCall {
  function?: { name?: string; arguments?: string };
}
interface ORMessage {
  content?: string | null;
  tool_calls?: ORToolCall[];
}
interface ORResponse {
  choices?: { message?: ORMessage }[];
  error?: { message?: string };
}

// One server-sent chunk of a streaming chat completion.
interface ORStreamChunk {
  choices?: {
    delta?: {
      content?: string | null;
      tool_calls?: { index?: number; function?: { name?: string; arguments?: string } }[];
    };
  }[];
}

/**
 * OpenRouter backend (OpenAI-compatible). One API key, many models. Tool calls
 * are translated to/from the OpenAI function-calling shape so the rest of
 * LainOS (which speaks the Anthropic-style ToolSchema) is unchanged.
 */
export class OpenRouterModelProvider implements ModelProvider {
  readonly name = "openrouter";
  private apiKey: string;
  private baseUrl: string;
  private models: Record<ModelTier, string>;
  private referer: string;
  private title: string;

  constructor(opts: OpenRouterProviderOptions) {
    this.apiKey = opts.apiKey;
    this.baseUrl = (opts.baseUrl ?? "https://openrouter.ai/api/v1").replace(/\/$/, "");
    this.models = { ...DEFAULT_OPENROUTER_MODELS, ...opts.models };
    this.referer = opts.referer ?? "https://cyberia.church";
    this.title = opts.title ?? "LainOS";
  }

  modelFor(tier: ModelTier): string {
    return this.models[tier];
  }

  async generate(request: ModelRequest): Promise<ModelResponse> {
    const model = this.modelFor(request.tier);
    log.debug(`generate via ${model}`);

    const messages = [
      { role: "system", content: request.system },
      ...request.messages.map((m) => ({ role: m.role, content: m.content })),
    ];

    const tools = request.tools?.map((t) => ({
      type: "function" as const,
      function: {
        name: t.name,
        description: t.description,
        parameters: t.input_schema,
      },
    }));

    const res = await fetch(`${this.baseUrl}/chat/completions`, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${this.apiKey}`,
        "Content-Type": "application/json",
        "HTTP-Referer": this.referer,
        "X-Title": this.title,
      },
      body: JSON.stringify({
        model,
        max_tokens: request.maxTokens ?? 1024,
        temperature: request.temperature ?? 0.8,
        messages,
        tools,
        tool_choice: tools && tools.length ? "auto" : undefined,
      }),
    });

    if (!res.ok) {
      const detail = await res.text().catch(() => "");
      throw new Error(`OpenRouter HTTP ${res.status}: ${detail.slice(0, 300)}`);
    }

    const data = (await res.json()) as ORResponse;
    if (data.error) throw new Error(`OpenRouter error: ${data.error.message}`);

    const msg = data.choices?.[0]?.message ?? {};
    const text = (msg.content ?? "") || "";
    const toolCalls = (msg.tool_calls ?? [])
      .filter((tc) => tc.function?.name)
      .map((tc) => ({
        name: tc.function!.name as string,
        input: safeParseArgs(tc.function?.arguments),
      }));

    return { text: text.trim(), toolCalls, model };
  }

  async stream(
    request: ModelRequest,
    onText: (delta: string) => void,
  ): Promise<ModelResponse> {
    const model = this.modelFor(request.tier);
    log.debug(`stream via ${model}`);

    const messages = [
      { role: "system", content: request.system },
      ...request.messages.map((m) => ({ role: m.role, content: m.content })),
    ];
    const tools = request.tools?.map((t) => ({
      type: "function" as const,
      function: { name: t.name, description: t.description, parameters: t.input_schema },
    }));

    const res = await fetch(`${this.baseUrl}/chat/completions`, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${this.apiKey}`,
        "Content-Type": "application/json",
        "HTTP-Referer": this.referer,
        "X-Title": this.title,
      },
      body: JSON.stringify({
        model,
        max_tokens: request.maxTokens ?? 1024,
        temperature: request.temperature ?? 0.8,
        messages,
        tools,
        tool_choice: tools && tools.length ? "auto" : undefined,
        stream: true,
      }),
    });

    if (!res.ok || !res.body) {
      log.debug("stream unavailable — falling back to generate");
      return this.generate(request);
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = "";
    let text = "";
    const toolAcc: Record<number, { name?: string; args: string }> = {};

    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });

      const lines = buffer.split("\n");
      buffer = lines.pop() ?? ""; // keep the partial last line for next chunk
      for (const line of lines) {
        const trimmed = line.trim();
        if (!trimmed.startsWith("data:")) continue;
        const payload = trimmed.slice(5).trim();
        if (payload === "" || payload === "[DONE]") continue;
        let chunk: ORStreamChunk;
        try {
          chunk = JSON.parse(payload) as ORStreamChunk;
        } catch {
          continue;
        }
        const delta = chunk.choices?.[0]?.delta;
        if (!delta) continue;
        if (typeof delta.content === "string" && delta.content) {
          text += delta.content;
          onText(delta.content);
        }
        for (const tc of delta.tool_calls ?? []) {
          const idx = tc.index ?? 0;
          const acc = (toolAcc[idx] ??= { args: "" });
          if (tc.function?.name) acc.name = tc.function.name;
          if (tc.function?.arguments) acc.args += tc.function.arguments;
        }
      }
    }

    const toolCalls = Object.values(toolAcc)
      .filter((t) => t.name)
      .map((t) => ({ name: t.name as string, input: safeParseArgs(t.args) }));

    return { text: text.trim(), toolCalls, model };
  }
}

function safeParseArgs(raw: string | undefined): Record<string, unknown> {
  if (!raw) return {};
  try {
    const parsed = JSON.parse(raw);
    return typeof parsed === "object" && parsed !== null ? parsed : {};
  } catch {
    return {};
  }
}
