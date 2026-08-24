/**
 * Task kinds — *what a turn is*, so the model can be chosen by what the work
 * costs and by what a wrong answer would break.
 *
 * The framework used to have one axis: capability tiers (small/medium/large),
 * picked by the character and paid for at whatever the base provider charges.
 * That is the wrong question for an operator on a budget: a news digest and a
 * trade both want "large" and only one of them is worth a paid token. So a
 * turn now carries a **kind**, and a kind carries two facts a router can act
 * on — `critical` (it acts on the world: money, code) and `cheap` (it is text
 * drudgery: digests, translation, recaps).
 *
 * Everything here is pure and dependency-free: the classifier is regex over
 * the operator's own words (ru/en), so tagging a task costs no tokens, and
 * `scripts/tasks-smoke.ts` pins the whole table.
 */
import { ModelTier } from "../types.js";

export enum TaskKind {
  /** Live conversation with the operator. */
  CHAT = "chat",
  /** Writing or changing code: the forge, patches, scripts. */
  CODE = "code",
  /** Posts, tweets, announcements, replies people will read. */
  WRITE = "write",
  /** News, roundups, "what happened" — reading a lot, saying a little. */
  DIGEST = "digest",
  /** Translating text between languages. */
  TRANSLATE = "translate",
  /** Reading numbers and saying what they mean. */
  ANALYSIS = "analysis",
  /** Anything that moves value: trades, transfers, balances, positions. */
  MONEY = "money",
  /** The agent's own housekeeping: recaps, titles, fact extraction. */
  MEMORY = "memory",
}

export interface TaskSpec {
  kind: TaskKind;
  /** One glyph, so a stamped reply is readable at a glance. */
  emoji: string;
  label: string;
  /** Operator-facing Russian label — the console and the TUI are bilingual. */
  ru: string;
  /** Capability tier this kind asks for when its route doesn't pin a model. */
  tier: ModelTier;
  /**
   * The work touches the world — money moved, code committed. A critical kind
   * is never cheapened by the blanket LAINOS_TASK_CHEAP knob; pointing one at
   * a free pool takes naming it explicitly, and is logged when it happens.
   */
  critical: boolean;
  /** Text drudgery: safe on the cheapest model that can read. */
  cheap: boolean;
  desc: string;
}

export const TASKS: Record<TaskKind, TaskSpec> = {
  [TaskKind.CHAT]: {
    kind: TaskKind.CHAT,
    emoji: "💬",
    label: "chat",
    ru: "разговор",
    tier: ModelTier.LARGE,
    critical: false,
    cheap: false,
    desc: "живой разговор с оператором",
  },
  [TaskKind.CODE]: {
    kind: TaskKind.CODE,
    emoji: "🛠",
    label: "code",
    ru: "код",
    tier: ModelTier.LARGE,
    critical: true,
    cheap: false,
    desc: "код, правки репозитория, скрипты",
  },
  [TaskKind.WRITE]: {
    kind: TaskKind.WRITE,
    emoji: "✍️",
    label: "write",
    ru: "текст",
    tier: ModelTier.LARGE,
    critical: false,
    cheap: false,
    desc: "посты, твиты, анонсы, ответы людям",
  },
  [TaskKind.DIGEST]: {
    kind: TaskKind.DIGEST,
    emoji: "📰",
    label: "digest",
    ru: "дайджест",
    tier: ModelTier.MEDIUM,
    critical: false,
    cheap: true,
    desc: "новости, сводки, обзоры",
  },
  [TaskKind.TRANSLATE]: {
    kind: TaskKind.TRANSLATE,
    emoji: "🌐",
    label: "translate",
    ru: "перевод",
    tier: ModelTier.SMALL,
    critical: false,
    cheap: true,
    desc: "перевод текста",
  },
  [TaskKind.ANALYSIS]: {
    kind: TaskKind.ANALYSIS,
    emoji: "📊",
    label: "analysis",
    ru: "аналитика",
    tier: ModelTier.MEDIUM,
    critical: false,
    cheap: true,
    desc: "разбор данных, метрики, сравнения",
  },
  [TaskKind.MONEY]: {
    kind: TaskKind.MONEY,
    emoji: "💰",
    label: "money",
    ru: "деньги",
    tier: ModelTier.LARGE,
    critical: true,
    cheap: false,
    desc: "сделки, переводы, кошелёк, позиции",
  },
  [TaskKind.MEMORY]: {
    kind: TaskKind.MEMORY,
    emoji: "🧠",
    label: "memory",
    ru: "память",
    tier: ModelTier.SMALL,
    critical: false,
    cheap: true,
    desc: "рекапы, заголовки, извлечение фактов",
  },
};

/** Display order: what the operator reads first, cheapest housekeeping last. */
export const TASK_ORDER: readonly TaskKind[] = [
  TaskKind.CHAT,
  TaskKind.CODE,
  TaskKind.MONEY,
  TaskKind.WRITE,
  TaskKind.ANALYSIS,
  TaskKind.DIGEST,
  TaskKind.TRANSLATE,
  TaskKind.MEMORY,
];

export function isTaskKind(raw: unknown): raw is TaskKind {
  return typeof raw === "string" && raw in TASKS;
}

export function taskSpec(kind: TaskKind): TaskSpec {
  return TASKS[kind];
}

/** `📰 digest` — the stamp that goes on a reply and into a session record. */
export function taskTag(kind: TaskKind): string {
  const spec = TASKS[kind];
  return `${spec.emoji} ${spec.label}`;
}

/** Env key holding this kind's route, e.g. LAINOS_TASK_DIGEST. */
export function taskEnvKey(kind: TaskKind): string {
  return `LAINOS_TASK_${kind.toUpperCase()}`;
}

// ------------------------------------------------------------------ routes

/** A route is a provider kind and, optionally, one pinned model id. */
export interface TaskRoute {
  provider: string;
  model?: string;
}

/**
 * `openrouter:deepseek/deepseek-chat-v3:free` → provider + model.
 *
 * Split on the *first* colon only: model ids carry colons of their own
 * (`:free` is a whole tier of OpenRouter's catalogue), and splitting on the
 * last one silently routes to a model that does not exist.
 */
export function parseTaskRoute(raw: string): TaskRoute | undefined {
  const value = raw.trim();
  if (!value || value.toLowerCase() === "none") return undefined;
  const cut = value.indexOf(":");
  if (cut < 0) return { provider: value.toLowerCase() };
  const provider = value.slice(0, cut).trim().toLowerCase();
  const model = value.slice(cut + 1).trim();
  if (!provider) return undefined;
  return model ? { provider, model } : { provider };
}

export function formatTaskRoute(route: TaskRoute): string {
  return route.model ? `${route.provider}:${route.model}` : route.provider;
}

// -------------------------------------------------------------- classifier

export interface Classification {
  kind: TaskKind;
  /** The words that decided it — printed when an operator asks why. */
  signal: string;
}

/**
 * Word edges that hold for Russian.
 *
 * JavaScript's `\b` is defined on ASCII word characters, so `\bпост\b` matches
 * *nothing at all* — the boundary it looks for cannot exist between a space
 * and a Cyrillic letter. Every rule below therefore fences its words with
 * Unicode lookarounds instead, and every pattern is compiled with `u`.
 */
const LETTER = String.raw`[\p{L}\p{N}_]`;
const LEFT = String.raw`(?<!${LETTER})`;
const RIGHT = String.raw`(?!${LETTER})`;

/** Whole words, both edges fenced: `word("пост(?:а|ы)?", "tweet")`. */
const word = (...forms: string[]): string => `${LEFT}(?:${forms.join("|")})${RIGHT}`;
/** A stem: fenced on the left, free to carry any inflection on the right. */
const stem = (...stems: string[]): string => `${LEFT}(?:${stems.join("|")})\\p{L}*`;
const rx = (...parts: string[]): RegExp => new RegExp(parts.join("|"), "iu");

/**
 * Ordered rules: the first match wins, and the order is the policy.
 *
 * Two collisions decide that order. **Writing about money is not money**:
 * "напиши пост про новый кошелёк" is a post, so a verb-plus-thing write
 * intent is read before anything else. And **moving money is not translating
 * it**: "переведи 5 CYBER на 0x…" is a transfer, so the money rule asks for a
 * number and runs before translation, which in turn refuses a digit after the
 * verb.
 */
const RULES: { kind: TaskKind; re: RegExp }[] = [
  {
    kind: TaskKind.WRITE,
    re: rx(
      `${word(
        "напиши(?:те)?",
        "напис(?:ать|ал|ала)",
        "сочини",
        "набросай",
        "подготов(?:ь|ить)",
        "состав(?:ь|ить)",
        "write",
        "draft",
        "compose",
      )}[\\s\\S]{0,24}?${word(
        "пост(?:а|у|ом|е|ы|ов|ам|ах)?",
        "твит(?:а|у|ом|е|ы|ов)?",
        "анонс(?:а|у|ом|е|ы|ов)?",
        "стать(?:я|ю|и|е|ей)",
        "заголов(?:ок|ка|ки|ков)",
        "текст(?:а|у|ом|е|ы|ов)?",
        "описани(?:е|я|ю|ем)",
        "черновик(?:а|и|ов)?",
        "tweet",
        "thread",
        "post",
        "article",
        "announcement",
        "caption",
        "headline",
        "changelog",
        "newsletter",
      )}`,
    ),
  },
  {
    kind: TaskKind.MONEY,
    re: rx(
      word(
        "куп(?:и|ить|лю|им|ите)",
        "прода(?:й|ть|м|ю|дим|йте)",
        "своп(?:а|ы|ов|нуть|ни)?",
        "баланс(?:а|у|ом|е|ы|ов)?",
        "позици(?:я|и|ю|ей|ями|ях|й)",
        "портфел(?:ь|я|ю|ем|е)",
        "транзакци(?:я|и|ю|ей|ями|ях|й)",
        "стейк(?:инг|а|ов)?",
        "ликвидност(?:ь|и|ью)",
        "апрув(?:ить|нуть)?",
        "газ(?:а|у|ом|е)?",
        "swap",
        "buy",
        "sell",
        "trade",
        "portfolio",
        "balance",
        "stake",
        "staking",
        "approve",
        "liquidity",
        "tx",
      ),
      // Only with an amount: a bare "переведи" is a translation.
      `${word("отправ(?:ь|ить|лю|им)", "перевед(?:и|ти|ите)", "send", "transfer")}\\s+\\d`,
    ),
  },
  {
    kind: TaskKind.CODE,
    re: rx(
      word(
        "код(?:а|е|у|ом|ы|ов)?",
        "баг(?:а|и|ов|у)?",
        "почин(?:и|ить)",
        "исправ(?:ь|ить|ляй)",
        "рефактор(?:инг|ить)?",
        "тест(?:ы|ов|ами|а)?",
        "скрипт(?:а|ы|ов)?",
        "деплой(?:ить|нуть)?",
        "патч(?:а|и|ей)?",
        "репозитори(?:й|я|и|ев)",
        "реализуй",
        "bug",
        "fix",
        "debug",
        "refactor",
        "deploy",
        "git",
        "commit",
        "merge",
        "patch",
        "script",
        "repo",
        "typescript",
        "python",
        "solidity",
        "implement",
        "tests?",
      ),
      "pull request",
      stem("компил"),
    ),
  },
  {
    kind: TaskKind.TRANSLATE,
    re: rx(
      `${word("перевед(?:и|ите)", "translate")}\\s+(?!\\d)`,
      word("translate", "translation"),
      `${word("перевод(?:а|у|ом)?")}\\s+${stem("текст", "стать", "пост", "сообщ")}`,
      `${word("на")}\\s+${stem("английск", "русск", "китайск", "japanese", "english", "russian")}`,
      "in english",
      "по-английски",
      "по-русски",
    ),
  },
  {
    kind: TaskKind.WRITE,
    re: rx(
      word(
        "пост(?:а|у|ом|е|ы|ов|ам|ах)?",
        "твит(?:а|у|ом|е|ы|ов)?",
        "анонс(?:а|у|ом|е|ы|ов)?",
        "стать(?:я|ю|и|е|ей)",
        "заголов(?:ок|ка|ки|ков)",
        "черновик(?:а|и|ов)?",
        "рассылк(?:а|у|и)",
        "tweet",
        "thread",
        "announcement",
        "article",
        "caption",
        "headline",
        "changelog",
        "newsletter",
      ),
    ),
  },
  {
    kind: TaskKind.DIGEST,
    re: rx(
      word(
        "дайджест(?:а|ы|ов)?",
        "новост(?:ь|и|ей|ям|ями)",
        "сводк(?:а|у|и|ой)",
        "подытож(?:ь|ить)",
        "обзор(?:а|ы|ов)?",
        "лент(?:а|у|ы)",
        "digest",
        "news",
        "roundup",
      ),
      stem("summar"),
      "краткое содержание",
      "что нового",
    ),
  },
  {
    kind: TaskKind.ANALYSIS,
    re: rx(
      word(
        "анализ(?:а|у|ом)?",
        "статистик(?:а|у|и|ой)",
        "метрик(?:а|и|ах|ами)",
        "отч[ёе]т(?:а|ы|ов|е)?",
        "сравни(?:ть|те)?",
        "посчитай",
        "подсчитай",
        "график(?:а|и|ов)?",
        "данны(?:е|х|ми)",
        "metrics",
        "report",
        "compare",
        "calculate",
        "chart",
        "stats",
      ),
      stem("проанализ", "analy"),
    ),
  },
];

/**
 * What kind of work is this message? Pure regex over the operator's own words,
 * so tagging costs nothing and is the same answer every time. Anything that
 * matches nothing is a conversation — the default that never surprises.
 */
export function classifyTask(text: string): Classification {
  const body = (text ?? "").trim();
  if (!body) return { kind: TaskKind.CHAT, signal: "" };
  for (const rule of RULES) {
    const hit = body.match(rule.re);
    if (hit) return { kind: rule.kind, signal: hit[0].toLowerCase().trim() };
  }
  return { kind: TaskKind.CHAT, signal: "" };
}
