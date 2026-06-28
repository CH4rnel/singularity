import Anthropic from "@anthropic-ai/sdk";
import { createLogger } from "../logger.js";
import {
  ModelTier,
  type ModelProvider,
  type ModelRequest,
  type ModelResponse,
} from "../types.js";

const log = createLogger("model:anthropic");

/** Default Claude snapshots per tier (latest as of the 4.x family). */
export const DEFAULT_MODELS: Record<ModelTier, string> = {
  [ModelTier.SMALL]: "claude-haiku-4-5-20251001",
  [ModelTier.MEDIUM]: "claude-sonnet-4-6",
  [ModelTier.LARGE]: "claude-opus-4-8",
};

export interface AnthropicProviderOptions {
  apiKey: string;
  models?: Partial<Record<ModelTier, string>>;
}

/** Anthropic Claude backend implementing the LainOS ModelProvider contract. */
export class AnthropicModelProvider implements ModelProvider {
  readonly name = "anthropic";
  private client: Anthropic;
  private models: Record<ModelTier, string>;

  constructor(opts: AnthropicProviderOptions) {
    this.client = new Anthropic({ apiKey: opts.apiKey });
    this.models = { ...DEFAULT_MODELS, ...opts.models };
  }

  modelFor(tier: ModelTier): string {
    return this.models[tier];
  }

  async generate(request: ModelRequest): Promise<ModelResponse> {
    const model = this.modelFor(request.tier);
    log.debug(`generate via ${model} (${request.messages.length} msgs)`);

    const res = await this.client.messages.create({
      model,
      max_tokens: request.maxTokens ?? 1024,
      temperature: request.temperature ?? 0.8,
      system: request.system,
      messages: request.messages.map((m) => ({
        role: m.role,
        content: m.content,
      })),
      tools: request.tools?.map((t) => ({
        name: t.name,
        description: t.description,
        input_schema: t.input_schema,
      })),
    });

    let text = "";
    const toolCalls: ModelResponse["toolCalls"] = [];
    for (const block of res.content) {
      if (block.type === "text") {
        text += block.text;
      } else if (block.type === "tool_use") {
        toolCalls.push({
          name: block.name,
          input: (block.input as Record<string, unknown>) ?? {},
        });
      }
    }

    return { text: text.trim(), toolCalls, model };
  }

  async stream(
    request: ModelRequest,
    onText: (delta: string) => void,
  ): Promise<ModelResponse> {
    const model = this.modelFor(request.tier);
    log.debug(`stream via ${model} (${request.messages.length} msgs)`);

    const stream = this.client.messages.stream({
      model,
      max_tokens: request.maxTokens ?? 1024,
      temperature: request.temperature ?? 0.8,
      system: request.system,
      messages: request.messages.map((m) => ({ role: m.role, content: m.content })),
      tools: request.tools?.map((t) => ({
        name: t.name,
        description: t.description,
        input_schema: t.input_schema,
      })),
    });

    stream.on("text", (delta) => onText(delta));

    const final = await stream.finalMessage();
    let text = "";
    const toolCalls: ModelResponse["toolCalls"] = [];
    for (const block of final.content) {
      if (block.type === "text") {
        text += block.text;
      } else if (block.type === "tool_use") {
        toolCalls.push({
          name: block.name,
          input: (block.input as Record<string, unknown>) ?? {},
        });
      }
    }

    return { text: text.trim(), toolCalls, model };
  }
}
