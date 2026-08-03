# skills/ — Lain's hot-loaded tools

Each `*.mjs` file here is one tool, hot-loaded into the running agent (no
restart). Lain writes these herself with `create_skill`; the forge and the
operator may also drop files in — the directory is watched.

Module contract (see `uptime.mjs` for a live example):

```js
export default {
  name: "snake_case_name",        // becomes the tool name; must match the file
  description: "What it does — the model reads this to decide when to call it.",
  parameters: {                    // JSON schema for the tool input (optional)
    type: "object",
    properties: { text: { type: "string", description: "…" } },
    required: ["text"],
  },
  async handler(runtime, state, params) {
    // runtime.getService("cyberia-chain"), runtime.getSetting(...), etc.
    return { ok: true, text: "result", data: { anything: "structured" } };
  },
};
```

Some skills reach outside the workspace on purpose, because the built-in tools
cannot: `android_apk.mjs` builds `frontend/mobile` in the monorepo (only the
project's own npm/gradle commands, in that one directory — it is not a shell
escape) and delivers the APK to the operator as a Telegram document, falling
back to the artifact from `.github/workflows/apps.yml` when the host has no
Android SDK. Skills that use a secret must use it and never return it: read it
with `runtime.getSetting`, and scrub it from every error string.

A skill that spends money must be two-step. `launch_token.mjs` issues a token on
the Cyberia launchpad (`LaunchpadNative`, native CYBER burned into permanently
locked liquidity): called without `execute` it only reads the chain and returns
a plan — launchpad identity and router check, token parameters, exact CYBER and
gas, balance left afterwards, and what can never be undone — ending in a
confirmation phrase that encodes the symbol, the supply and the CYBER amount.
Signing needs `execute: true` plus that phrase repeated back, so changing any
term of the plan invalidates an earlier confirmation, and every launch is
appended to `data/launches.json`. The private key is never read: signing goes
through the `cyberia-chain` wallet client. Its safety behaviour is covered by
`npm run smoke` (`launch needs confirmation`).

Node builtins and installed dependencies (`viem`, `undici`) may be imported.
Broken modules are rejected at load with the error message. Built-in tools can
never be shadowed by a skill. These files are part of the repo on purpose:
Lain's learning is versioned and committed like any other code.
