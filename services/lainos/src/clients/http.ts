import { createServer, type Server } from "node:http";
import { runCyberiaStudyNow } from "../cyberia-study.js";
import { createLogger } from "../logger.js";
import type { ForgeService } from "../plugins/forge/index.js";
import type { ScoutService } from "../plugins/scout/index.js";
import type { SentinelService } from "../plugins/sentinel/index.js";
import type { IAgentRuntime } from "../types.js";

const log = createLogger("http");

export interface HttpOptions {
  host?: string;
  port?: number;
}

/**
 * Minimal dependency-free HTTP bridge so external clients (e.g. the Wired
 * Godot game's NPCs) can talk to the agent.
 *
 *   GET  /health           -> { ok, agent }
 *   GET  /alerts            -> { alerts } (recent sentinel alerts)
 *   GET  /wishes            -> { wishes } (the forge wishboard)
 *   GET  /research          -> { topics } (the scout's subscriptions)
 *   POST /research/cyberia-study/run -> { topic, digest }
 *   POST /chat {roomId,userId,text} -> { text, actions }
 */
export function createHttpServer(runtime: IAgentRuntime, opts: HttpOptions = {}): Server {
  const host = opts.host ?? process.env.LAINOS_HTTP_HOST ?? "127.0.0.1";
  const port = Number(opts.port ?? process.env.LAINOS_HTTP_PORT ?? 7777);

  const server = createServer(async (req, res) => {
    res.setHeader("Access-Control-Allow-Origin", "*");
    res.setHeader("Access-Control-Allow-Headers", "Content-Type");
    res.setHeader("Access-Control-Allow-Methods", "GET,POST,OPTIONS");
    if (req.method === "OPTIONS") {
      res.writeHead(204).end();
      return;
    }

    if (req.method === "GET" && req.url === "/health") {
      return json(res, 200, { ok: true, agent: runtime.character.name });
    }

    if (req.method === "GET" && req.url?.startsWith("/alerts")) {
      const sentinel = runtime.getService<SentinelService>("sentinel");
      return json(res, 200, { alerts: sentinel?.recentAlerts(50) ?? [] });
    }

    if (req.method === "GET" && req.url?.startsWith("/wishes")) {
      const forge = runtime.getService<ForgeService>("forge");
      return json(res, 200, { wishes: forge?.listWishes() ?? [] });
    }

    if (req.method === "GET" && req.url?.startsWith("/research")) {
      const scout = runtime.getService<ScoutService>("scout");
      return json(res, 200, { topics: scout?.listTopics() ?? [] });
    }

    if (req.method === "POST" && req.url === "/research/cyberia-study/run") {
      const scout = runtime.getService<ScoutService>("scout");
      if (!scout) return json(res, 503, { error: "scout offline" });
      try {
        const result = await runCyberiaStudyNow(scout);
        if (!result) return json(res, 409, { error: "cyberia study disabled" });
        return json(res, 200, {
          topic: result.topic,
          digest: result.digest,
          message: result.digest ?? "study note created",
        });
      } catch (err) {
        log.error("cyberia study trigger failed", err);
        return json(res, 500, { error: "cyberia study failed" });
      }
    }

    if (req.method === "POST" && req.url === "/chat") {
      try {
        const body = await readBody(req);
        const { roomId, userId, text } = JSON.parse(body || "{}");
        if (typeof text !== "string" || !text.trim()) {
          return json(res, 400, { error: "field 'text' is required" });
        }
        const result = await runtime.handleMessage({
          roomId: typeof roomId === "string" ? roomId : "http",
          userId: typeof userId === "string" ? userId : "anon",
          text,
        });
        return json(res, 200, result);
      } catch (err) {
        log.error("chat handler failed", err);
        return json(res, 500, { error: "internal error" });
      }
    }

    json(res, 404, { error: "not found" });
  });

  server.on("error", (err: NodeJS.ErrnoException) => {
    if (err.code === "EADDRINUSE") {
      log.error(
        `port ${port} is already taken — another LainOS instance is probably running ` +
          `(try: systemctl --user status lainos.service). ` +
          `Stop it first, or set LAINOS_HTTP_PORT to a free port. ` +
          `Never run two instances of one agent: they would fight over memory files and the Telegram poller.`,
      );
      process.exit(1);
    }
    throw err;
  });
  server.listen(port, host, () => {
    log.info(`HTTP bridge for ${runtime.character.name} on http://${host}:${port}`);
  });
  return server;
}

function readBody(req: import("node:http").IncomingMessage): Promise<string> {
  return new Promise((resolve, reject) => {
    let data = "";
    req.on("data", (chunk) => {
      data += chunk;
      if (data.length > 1_000_000) reject(new Error("payload too large"));
    });
    req.on("end", () => resolve(data));
    req.on("error", reject);
  });
}

function json(res: import("node:http").ServerResponse, status: number, body: unknown) {
  const payload = JSON.stringify(body);
  res.writeHead(status, { "Content-Type": "application/json" });
  res.end(payload);
}
