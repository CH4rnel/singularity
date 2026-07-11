import type { ScoutService, Topic } from "./plugins/scout/index.js";

export const CYBERIA_STUDY_QUERY =
  "Cyberia cyberia.church chain 49406 CYBER Ritual DEX bridge LainOS";

export const CYBERIA_STUDY_NOTE =
  "Первая цель Lain: изучай Cyberia — сеть, сайт, мост, Ritual DEX, Blockscout, LainOS, Wired, токен CYBER и публичные упоминания cyberia.church. Пиши мне в Telegram только о конкретных находках: что узнала, почему это важно, и ссылку/факт. Отсекай посторонний шум про другие Cyberia.";

export interface CyberiaStudyOptions {
  enabled?: boolean;
  chatId?: number;
  intervalMs?: number;
  runImmediately?: boolean;
}

export interface CyberiaStudyRun {
  topic: Topic;
  digest: string | null;
}

export function cyberiaStudyIntervalMs(hoursRaw = process.env.LAINOS_CYBERIA_STUDY_INTERVAL_HOURS): number {
  const hours = Number(hoursRaw ?? 1);
  return Number.isFinite(hours) && hours > 0 ? hours * 3_600_000 : 3_600_000;
}

export function cyberiaStudyChatId(): number | undefined {
  const raw =
    process.env.LAINOS_CYBERIA_STUDY_CHAT_ID ??
    process.env.TELEGRAM_REPORT_CHAT_ID ??
    process.env.TELEGRAM_ALLOWED_CHATS?.split(",").find((value) => value.trim());
  const trimmed = raw?.trim();
  if (!trimmed) return undefined;
  const id = Number(trimmed);
  return Number.isFinite(id) ? id : undefined;
}

export function findCyberiaStudyTopic(scout: ScoutService): Topic | undefined {
  return scout
    .listTopics()
    .find((topic) => topic.query === CYBERIA_STUDY_QUERY || topic.note === CYBERIA_STUDY_NOTE);
}

export async function ensureCyberiaStudyGoal(
  scout: ScoutService,
  opts: CyberiaStudyOptions = {},
): Promise<Topic | null> {
  if (opts.enabled === false || process.env.LAINOS_CYBERIA_STUDY === "0") return null;

  const intervalMs = opts.intervalMs ?? cyberiaStudyIntervalMs();
  const chatId = opts.chatId ?? cyberiaStudyChatId();
  const existing = findCyberiaStudyTopic(scout);
  if (existing) {
    if (existing.intervalMs !== intervalMs || (chatId !== undefined && existing.chatId !== chatId)) {
      await scout.updateTopic(existing.id, { chatId, intervalMs });
      return scout.getTopic(existing.id) ?? existing;
    }
    return existing;
  }

  return scout.addTopic({
    query: CYBERIA_STUDY_QUERY,
    note: CYBERIA_STUDY_NOTE,
    reporter: "system:cyberia-study",
    chatId,
    intervalMs,
    runImmediately: opts.runImmediately,
  });
}

export async function runCyberiaStudyNow(
  scout: ScoutService,
  opts: { chatId?: number } = {},
): Promise<CyberiaStudyRun | null> {
  const topic = await ensureCyberiaStudyGoal(scout, { chatId: opts.chatId, runImmediately: false });
  if (!topic) return null;
  const digest = await scout.runTopic(topic, {
    deliver: false,
    includeSeen: true,
    noteOnEmpty: true,
  });
  return { topic, digest };
}
