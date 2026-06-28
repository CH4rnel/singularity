import { createInterface } from "node:readline/promises";
import { stdin, stdout } from "node:process";
import { createLogger } from "../logger.js";
import type { IAgentRuntime } from "../types.js";

const log = createLogger("cli");

/** Interactive REPL against a running agent. */
export async function runCli(runtime: IAgentRuntime, roomId = "cli"): Promise<void> {
  const rl = createInterface({ input: stdin, output: stdout });
  const name = runtime.character.name;
  log.info(`Talking to ${name}. Type /exit to quit, /reset for a new room.`);

  let room = roomId;
  for (;;) {
    const line = (await rl.question("\x1b[36myou>\x1b[0m ")).trim();
    if (!line) continue;
    if (line === "/exit" || line === "/quit") break;
    if (line === "/reset") {
      room = `cli-${Date.now()}`;
      log.info(`new room: ${room}`);
      continue;
    }

    try {
      const { text, actions } = await runtime.handleMessage({
        roomId: room,
        userId: "user",
        text: line,
      });
      if (actions.length) {
        const summary = actions.map((a) => `${a.name}:${a.result.ok ? "ok" : "fail"}`).join(", ");
        log.debug(`actions -> ${summary}`);
      }
      stdout.write(`\x1b[35m${name}>\x1b[0m ${text}\n`);
    } catch (err) {
      log.error("turn failed", err);
    }
  }
  rl.close();
}
