import { ModelTier } from "../types.js";
import { OpenRouterModelProvider } from "./openrouter.js";

export const CYBERIA_AI_BASE_URL = "https://cyberia.church/api/ai/v1";

export const DEFAULT_CYBERIA_MODELS: Record<ModelTier, string> = {
  [ModelTier.SMALL]: "lain-free",
  [ModelTier.MEDIUM]: "lain-free",
  [ModelTier.LARGE]: "lain-free",
};

export interface CyberiaProviderOptions {
  apiKey: string;
  baseUrl?: string;
  models?: Partial<Record<ModelTier, string>>;
  proxy?: string;
}

/** Cyberia's free OpenAI-compatible inference grant for installed LainOS. */
export class CyberiaModelProvider extends OpenRouterModelProvider {
  constructor(opts: CyberiaProviderOptions) {
    super({
      apiKey: opts.apiKey,
      name: "cyberia",
      baseUrl: opts.baseUrl ?? CYBERIA_AI_BASE_URL,
      models: { ...DEFAULT_CYBERIA_MODELS, ...opts.models },
      referer: "https://cyberia.church",
      title: "LainOS · Cyberia free",
      proxy: opts.proxy,
    });
  }
}
