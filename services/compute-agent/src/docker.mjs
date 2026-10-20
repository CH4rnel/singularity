// The docker CLI, and nothing else. No socket library: `docker` is what the
// operator already uses on the host, and what it prints is what gets tested.

import { execFile } from "node:child_process";
import { promisify } from "node:util";

const run = promisify(execFile);

export function makeDocker({ dryRun = false, log = console.log } = {}) {
  async function docker(args, { mutates = false, env } = {}) {
    if (dryRun && mutates) {
      log(`[dry-run] docker ${args.join(" ")}`);
      return "";
    }
    const { stdout } = await run("docker", args, { maxBuffer: 16 * 1024 * 1024, env: env ?? process.env });
    return stdout;
  }

  return {
    /** Containers this agent started, running or not. */
    async leaseContainers() {
      const out = await docker([
        "ps", "--all", "--filter", "label=cyberia.lease",
        "--format", '{{json .}}',
      ]);
      return out.split("\n").filter(Boolean).map((line) => {
        const c = JSON.parse(line);
        const labels = Object.fromEntries(
          String(c.Labels ?? "").split(",").filter(Boolean).map((kv) => kv.split("=")),
        );
        return {
          name: c.Names,
          leaseId: Number(labels["cyberia.lease"]),
          spec: labels["cyberia.spec"],
          running: c.State === "running",
        };
      });
    },
    async stats(names) {
      if (names.length === 0) return "";
      return docker(["stats", "--no-stream", "--format", "{{json .}}", ...names]);
    },
    pull: (image) => docker(["pull", image], { mutates: true }),
    run: (args, env) => docker(args, { mutates: true, env }),
    start: (name) => docker(["start", name], { mutates: true }),
    stop: (name) => docker(["stop", "--time", "20", name], { mutates: true }),
    remove: (name) => docker(["rm", "--force", name], { mutates: true }),
  };
}
