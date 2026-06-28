#!/usr/bin/env -S npx tsx
/** Run the Lain agent as an HTTP service (consumed by the Wired game). */
import { createHttpServer } from "../src/clients/http.js";
import { createAgent } from "../src/index.js";
import { lain } from "../src/characters/lain.js";

async function main() {
  const agent = await createAgent({ character: lain });
  const server = createHttpServer(agent);

  const shutdown = async () => {
    server.close();
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
