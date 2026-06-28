import { createServer, type Server } from "node:http";
import { createLogger } from "../logger.js";
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
