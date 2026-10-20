// The service manifest: what a lease pays for, byte for byte.
//
// A lease commits to keccak256 of the manifest's canonical JSON, so the
// provider cannot run a different image than the one paid for without the
// difference being provable — the hash is on chain and the manifest is public.
// Canonical means sorted keys and no whitespace; anything else would let two
// spellings of one manifest hash differently.

import { keccak256, toUtf8Bytes } from "ethers";

export const SCHEMA = "cyberia.compute/v1";

/** Tiers: who may run it. A `public` service holds no secret, so any approved
 *  provider can; a `trusted` one (a relayer key, a bot token, a hot wallet) runs
 *  only on a provider the DAO marked trusted, because a container's operator
 *  can read everything inside it. */
export const TIERS = ["public", "trusted"];

const IMAGE_DIGEST = /^[a-z0-9][a-z0-9._\/-]*(:[A-Za-z0-9._-]+)?@sha256:[0-9a-f]{64}$/;
const NAME = /^[a-z0-9][a-z0-9-]{0,62}$/;
const ENV_KEY = /^[A-Z_][A-Z0-9_]*$/;

export function canonicalize(value) {
  if (Array.isArray(value)) return `[${value.map(canonicalize).join(",")}]`;
  if (value && typeof value === "object") {
    const keys = Object.keys(value).filter((k) => value[k] !== undefined).sort();
    return `{${keys.map((k) => `${JSON.stringify(k)}:${canonicalize(value[k])}`).join(",")}}`;
  }
  if (typeof value === "number" && !Number.isFinite(value)) throw new Error("non-finite number");
  return JSON.stringify(value);
}

export function specHash(manifest) {
  return keccak256(toUtf8Bytes(canonicalize(manifest)));
}

/** Every reason a manifest cannot be run, empty when it can. */
export function validateManifest(m) {
  const errors = [];
  if (!m || typeof m !== "object") return ["not an object"];
  if (m.schema !== SCHEMA) errors.push(`schema must be ${SCHEMA}`);
  if (!NAME.test(m.service ?? "")) errors.push("service must be a DNS-style name");
  // A tag is a promise anyone can re-point; a digest is the image.
  if (!IMAGE_DIGEST.test(m.image ?? "")) errors.push("image must be pinned by @sha256 digest");
  if (!TIERS.includes(m.tier)) errors.push(`tier must be one of ${TIERS.join(", ")}`);

  const r = m.resources ?? {};
  if (!(r.cpus > 0 && r.cpus <= 16)) errors.push("resources.cpus must be in (0, 16]");
  if (!(Number.isInteger(r.memoryMb) && r.memoryMb >= 16)) errors.push("resources.memoryMb must be an integer ≥ 16");
  if (r.pids !== undefined && !(Number.isInteger(r.pids) && r.pids > 0)) errors.push("resources.pids must be a positive integer");

  for (const [k, v] of Object.entries(m.env ?? {})) {
    if (!ENV_KEY.test(k)) errors.push(`env ${k} is not an env name`);
    if (typeof v !== "string") errors.push(`env ${k} must be a string`);
  }
  const secrets = m.secrets ?? [];
  if (!Array.isArray(secrets) || secrets.some((s) => !ENV_KEY.test(s))) errors.push("secrets must be env names");
  // A manifest is public; a secret's *value* is never in it, only its name.
  if (secrets.length > 0 && m.tier !== "trusted") errors.push("a public-tier service cannot declare secrets");

  for (const p of m.ports ?? []) {
    if (!(Number.isInteger(p.container) && p.container > 0 && p.container < 65536)) errors.push("ports[].container must be a port");
    if (p.host !== undefined && !(Number.isInteger(p.host) && p.host > 0 && p.host < 65536)) errors.push("ports[].host must be a port");
    if (!["tcp", "udp"].includes(p.protocol ?? "tcp")) errors.push("ports[].protocol must be tcp or udp");
  }
  for (const v of m.volumes ?? []) {
    if (!NAME.test(v.name ?? "")) errors.push("volumes[].name must be a DNS-style name");
    if (!(typeof v.path === "string" && v.path.startsWith("/"))) errors.push("volumes[].path must be absolute");
  }
  if (m.health !== undefined) {
    if (!(Number.isInteger(m.health.port) && typeof m.health.path === "string")) errors.push("health needs port and path");
  }
  return errors;
}

/** `docker run` arguments for a lease. Everything a container could use to
 *  reach the host — privileges, the host network, bind mounts, devices — is
 *  simply not expressible in a manifest, so it cannot be asked for. */
export function dockerRunArgs(m, { leaseId, hash, env = {} }) {
  const name = `cyberia-lease-${leaseId}`;
  const r = m.resources;
  const args = [
    "run", "--detach",
    "--name", name,
    "--label", `cyberia.lease=${leaseId}`,
    "--label", `cyberia.spec=${hash}`,
    "--label", `cyberia.service=${m.service}`,
    "--restart", "unless-stopped",
    "--cpus", String(r.cpus),
    "--memory", `${r.memoryMb}m`,
    "--memory-swap", `${r.memoryMb}m`,
    "--pids-limit", String(r.pids ?? 256),
    "--cap-drop", "ALL",
    "--security-opt", "no-new-privileges",
    "--log-driver", "json-file", "--log-opt", "max-size=20m", "--log-opt", "max-file=3",
  ];
  if (m.readOnly !== false) args.push("--read-only", "--tmpfs", "/tmp:rw,size=64m");
  for (const p of m.ports ?? []) {
    const proto = p.protocol ?? "tcp";
    if (p.host !== undefined) args.push("--publish", `${p.host}:${p.container}/${proto}`);
  }
  for (const v of m.volumes ?? []) args.push("--volume", `cyberia-lease-${leaseId}-${v.name}:${v.path}`);
  for (const [k, v] of Object.entries(m.env ?? {})) args.push("--env", `${k}=${v}`);
  // Secret values come from the provider's own environment and only exist on a
  // trusted provider; the name is passed, never the value, so it stays out of
  // `ps` and out of this function's return value.
  for (const s of m.secrets ?? []) {
    if (env[s] === undefined) throw new Error(`secret ${s} is not set on this provider`);
    args.push("--env", s);
  }
  args.push(m.image, ...(m.command ?? []));
  return args;
}
