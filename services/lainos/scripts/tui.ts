#!/usr/bin/env -S npx tsx
/** Launch the Lain agent inside the LainOS TUI. */
import React from "react";
import { render } from "ink";
import { createAgent } from "../src/index.js";
import { lain } from "../src/characters/lain.js";
import { setLogMuted } from "../src/logger.js";
import { App } from "../src/clients/tui/App.js";

async function main() {
  // The TUI owns the screen — keep stray console output from corrupting it.
  setLogMuted(true);
  const agent = await createAgent({ character: lain });

  const { waitUntilExit } = render(React.createElement(App, { runtime: agent }));
  await waitUntilExit();

  await agent.stop();
  process.exit(0);
}

main().catch((err) => {
  setLogMuted(false);
  console.error(err);
  process.exit(1);
});
