#!/usr/bin/env -S npx tsx
/**
 * TUI smoke test: renders the ink App against fake stdio and drives the
 * keyboard — message round-trip, /copy (OSC 52), /clear, and the idle blink
 * pause that keeps terminal text selectable. Run: npm run tui:smoke
 */
import { EventEmitter } from "node:events";
import { mkdtempSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import React from "react";
import { render } from "ink";

// Dead RPC + fresh prefs dir so nothing but the blink timer repaints.
process.env.CYBERIA_RPC_URL = "http://127.0.0.1:9";
process.env.LAINOS_DATA_DIR = mkdtempSync(join(tmpdir(), "lainos-tui-"));

import { App } from "../src/clients/tui/App.js";
import { AgentRuntime } from "../src/runtime.js";
import { FileMemoryStore } from "../src/memory/store.js";
import { SwitchableModelProvider } from "../src/models/routing.js";
import { lain } from "../src/characters/lain.js";
import type { ModelRequest, ModelResponse } from "../src/types.js";

const REPLY = "the wired says: privet";

class FakeStdout extends EventEmitter {
  isTTY = true;
  columns = 100;
  rows = 40;
  writes: string[] = [];
  write(data: string): boolean {
    this.writes.push(String(data));
    return true;
  }
  off(ev: string, fn: (...a: unknown[]) => void) {
    return this.removeListener(ev, fn);
  }
}

class FakeStdin extends EventEmitter {
  isTTY = true;
  private chunks: string[] = [];
  setEncoding() {}
  setRawMode() {}
  resume() {}
  pause() {}
  ref() {}
  unref() {}
  write(data: string) {
    this.chunks.push(data);
    this.emit("readable");
  }
  read(): string | null {
    return this.chunks.shift() ?? null;
  }
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

async function main() {
  const model = {
    name: "stub",
    modelFor: () => "stub-model",
    async generate(_req: ModelRequest): Promise<ModelResponse> {
      return { text: REPLY, toolCalls: [], model: "stub" };
    },
  };
  // The daemon's chat model is switchable (/model claude|codex), so the probe
  // wraps the stub the same way — "anthropic" stands in for a route that has
  // neither a key nor a CLI on this machine.
  const switchable = new SwitchableModelProvider({
    initial: model as never,
    kind: "codex",
    envKind: "codex",
    assemble: (kind) =>
      kind === "anthropic"
        ? undefined
        : ({ ...model, name: kind, modelFor: () => `${kind}-model` } as never),
    persist: () => {},
  });
  const runtime = new AgentRuntime({
    character: lain,
    memory: new FileMemoryStore(mkdtempSync(join(tmpdir(), "lainos-tui-mem-"))),
    model: switchable as never,
    settings: {},
  });
  // A fat skill list (the real daemon registers 30+ actions) once made the
  // boot card wider than the terminal and shredded the <Static> frame.
  runtime.use({
    name: "smoke-skills",
    description: "boot-card overflow probe",
    actions: Array.from({ length: 24 }, (_, i) => ({
      name: `padded_out_probe_skill_${i}`,
      similes: [],
      description: "probe",
      examples: [],
      validate: async () => true,
      handler: async () => ({ ok: true }),
    })),
  });

  const stdout = new FakeStdout();
  const stdin = new FakeStdin();
  const app = render(<App runtime={runtime} />, {
    stdout: stdout as never,
    stdin: stdin as never,
    exitOnCtrlC: false,
    patchConsole: false,
  });

  const anyWrite = (needle: string, from = 0) =>
    stdout.writes.slice(from).some((w) => w.includes(needle));
  const type = async (s: string) => {
    for (const ch of s) {
      stdin.write(ch);
      await sleep(15);
    }
  };

  await sleep(300);
  const results: [string, boolean][] = [];

  // 0) the tab/window title is claimed via OSC 0
  results.push(["sets terminal title  ", anyWrite("\x1b]0;Lain OS")]);

  // 1) a normal message round-trip lands in the transcript
  await type("hi\r");
  await sleep(700);
  results.push(["reply shows in feed  ", anyWrite(REPLY)]);
  // …with the provenance marker (which model answered) in the turn header.
  results.push(["reply names model    ", anyWrite("· stub")]);

  // The transcript is printed once into scrollback (<Static>): later repaints
  // (cursor blink etc.) must never rewrite it — that is what lets the user
  // select and copy text while the app is running.
  const afterReply = stdout.writes.length;
  await sleep(2000);
  results.push(["transcript is static ", !anyWrite(REPLY, afterReply)]);

  // 1.5) the composer edits like a readline prompt: ink's own key parsing
  // drops home/end/delete/ctrl+←→ entirely, so we parse the raw sequences.
  const beforeEdit = stdout.writes.length;
  await type("hello world");
  const press = async (seq: string) => {
    stdin.write(seq);
    await sleep(40);
  };
  await press("\x1b[H"); // home → start of line
  await type("X"); // "Xhello world"
  await press("\x1b[1;5C"); // ctrl+→ → past "hello"
  await press("\x1b[3~"); // delete → eats the space, "Xhelloworld"
  await press("\x1b[F"); // end
  await type("!");
  results.push(["home/end/del/ctrl+→  ", anyWrite("Xhelloworld!", beforeEdit)]);
  await press("\x15"); // ctrl+u from the end wipes the line again
  await sleep(100);
  results.push(["line cleared         ", anyWrite("ask lain…", beforeEdit)]);

  // 2) /copy emits OSC 52 with the reply, confirms in the feed
  const beforeCopy = stdout.writes.length;
  await type("/copy");
  await sleep(30);
  await type("\r"); // menu is narrowed to /copy; enter commits it
  await sleep(300);
  const osc = stdout.writes.find((w) => w.includes("\x1b]52;c;"));
  let oscOk = false;
  if (osc) {
    const b64 = osc.split("\x1b]52;c;")[1]?.split("\x07")[0] ?? "";
    oscOk = Buffer.from(b64, "base64").toString("utf8") === REPLY;
  }
  results.push(["/copy OSC52 payload  ", oscOk]);
  results.push(["/copy confirmation   ", anyWrite("copied lain's last reply", beforeCopy)]);

  // 3) /clear wipes screen + scrollback and reprints the boot card
  const beforeClear = stdout.writes.length;
  await type("/clear");
  await sleep(30);
  await type("\r");
  await sleep(300);
  results.push(["/clear wipes screen  ", anyWrite("\x1b[3J", beforeClear)]);
  results.push(["/clear shows boot    ", anyWrite("welcome to the wired", beforeClear)]);
  results.push(["/clear drops history ", !anyWrite(REPLY, beforeClear)]);

  // 4) picking a new /skin wipes and reprints the transcript in the new theme
  await type("/skin");
  await sleep(30);
  await type("\r"); // menu narrowed to /skin; enter opens the picker
  await sleep(200);
  stdin.write("\x1b[B"); // ↓ highlights "matrix" (preview recolors the bottom)
  await sleep(150);
  const beforePick = stdout.writes.length;
  stdin.write("\r"); // pick it
  await sleep(300);
  const matrixPrimary = "38;2;57;255;20"; // THEMES.matrix.primary #39ff14
  const skinReprint = stdout.writes.slice(beforePick).find((w) => w.includes("welcome to the wired"));
  results.push(["/skin wipes screen   ", anyWrite("\x1b[3J", beforePick)]);
  results.push(["/skin reprints theme ", !!skinReprint && skinReprint.includes(matrixPrimary)]);

  // 4.7) /model re-routes the replies in place, and a route that cannot be
  // built says so instead of silently dropping the chat on another model.
  const beforeModel = stdout.writes.length;
  await type("/model claude\r");
  await sleep(300);
  results.push(["/model switches      ", anyWrite("replies now go through claude (cli)", beforeModel)]);
  const beforeBadModel = stdout.writes.length;
  await type("/model claude-api\r");
  await sleep(300);
  results.push(["/model failure loud  ", anyWrite("unavailable", beforeBadModel)]);

  // 4.5) nothing ever draws wider than the terminal — an overflowing line is
  // hard-wrapped by the terminal itself and shreds the static frames.
  const stripAnsi = (s: string) =>
    s.replace(/\x1b\[[0-9;?]*[A-Za-z]/g, "").replace(/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\)/g, "");
  const wideLine = stdout.writes
    .flatMap((w) => stripAnsi(w).split("\n"))
    .find((l) => [...l].length > stdout.columns);
  results.push(["fits terminal width  ", wideLine === undefined]);

  // 5) a width change repaints the transcript once the resize settles
  const beforeResize = stdout.writes.length;
  stdout.columns = 80;
  stdout.emit("resize");
  await sleep(600); // repaint debounce is 200ms
  results.push([
    "resize repaints      ",
    anyWrite("\x1b[3J", beforeResize) && anyWrite("welcome to the wired", beforeResize),
  ]);

  // 6) blink repaints happen right after typing…
  const n1 = stdout.writes.length;
  await sleep(2500);
  const blinkFrames = stdout.writes.length - n1;
  results.push([`blinks while active  `, blinkFrames >= 2]);

  // …and stop once the keyboard has been idle >10s (selection survives)
  await sleep(9000);
  const n2 = stdout.writes.length;
  await sleep(3000);
  const idleFrames = stdout.writes.length - n2;
  results.push([`idle stops repaints  `, idleFrames === 0]);

  app.unmount();
  let ok = true;
  for (const [name, pass] of results) {
    console.log(`${name}: ${pass ? "PASS" : "FAIL"}`);
    ok &&= pass;
  }
  console.log(ok ? "TUI PROBE OK" : "TUI PROBE FAILED");
  process.exit(ok ? 0 : 1);
}

void main();
