#!/usr/bin/env -S npx tsx
/**
 * Run the Lain agent as a persistent daemon:
 *   - HTTP bridge on LAINOS_HTTP_PORT (consumed by the Wired game),
 *   - Telegram bot when TELEGRAM_BOT_TOKEN is set (long polling),
 *   - sentinel alerts pushed to every known Telegram chat.
 */
import { createHttpServer } from "../src/clients/http.js";
import { TelegramClient } from "../src/clients/telegram.js";
import { ensureCyberiaStudyGoal, findCyberiaStudyTopic } from "../src/cyberia-study.js";
import { createAgent } from "../src/index.js";
import { lain } from "../src/characters/lain.js";
import type { ForgeService } from "../src/plugins/forge/index.js";
import type { ScoutService } from "../src/plugins/scout/index.js";
import type { SentinelService } from "../src/plugins/sentinel/index.js";

async function main() {
  const agent = await createAgent({ character: lain });
  const server = createHttpServer(agent);

  const telegram = new TelegramClient(agent);
  const telegramUp = await telegram.start();

  const sentinel = agent.getService<SentinelService>("sentinel");
  if (sentinel && telegramUp) {
    sentinel.onAlert((alert) => {
      void telegram.broadcast(`⚠ ${alert.text}`);
    });
  }

  // Forge progress goes to the wish's reporter when known, otherwise to all.
  const forge = agent.getService<ForgeService>("forge");
  if (forge && telegramUp) {
    forge.onEvent((ev) => {
      if (ev.chatId !== undefined) void telegram.sendTo(ev.chatId, ev.text);
      else void telegram.broadcast(ev.text);
    });
  }

  // Research digests go to the topic's subscriber when known, otherwise to all.
  const scout = agent.getService<ScoutService>("scout");
  if (scout && telegramUp) {
    scout.onEvent((ev) => {
      if (ev.chatId !== undefined) void telegram.sendTo(ev.chatId, ev.text);
      else void telegram.broadcast(ev.text);
    });
  }
  if (scout) {
    const hadCyberiaStudy = Boolean(findCyberiaStudyTopic(scout));
    const topic = await ensureCyberiaStudyGoal(scout);
    if (topic && telegramUp && !hadCyberiaStudy) {
      const text =
        `первая цель включена: изучаю Cyberia (${topic.id}). ` +
        `буду писать сюда, когда узнаю что-то важное.`;
      if (topic.chatId !== undefined) void telegram.sendTo(topic.chatId, text);
      else void telegram.broadcast(text);
    }
  }

  const shutdown = async () => {
    server.close();
    await telegram.stop();
    await agent.stop();
    process.exit(0);
  };
  process.on("SIGINT", shutdown);
  process.on("SIGTERM", shutdown);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
