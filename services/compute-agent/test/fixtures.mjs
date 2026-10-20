export const DIGEST = "a".repeat(64);

export function manifest(overrides = {}) {
  return {
    schema: "cyberia.compute/v1",
    service: "cyberia-dns",
    tier: "public",
    image: `ghcr.io/cyberia-temple/cyberia-dns@sha256:${DIGEST}`,
    command: ["node", "/app/src/server.mjs"],
    env: { DNS_PORT: "53", CYBERIA_RPC_URL: "https://rpc.cyberia.church" },
    resources: { cpus: 0.5, memoryMb: 256, pids: 128 },
    ports: [{ container: 53, host: 53, protocol: "udp" }],
    health: { port: 8053, path: "/health" },
    ...overrides,
  };
}

export function lease(overrides = {}) {
  return {
    rate: 1_000_000_000n,
    balance: 86_400n * 1_000_000_000n,
    settledAt: 1_000_000n,
    pausedAt: 0n,
    closed: false,
    specHash: "0xspec",
    specURI: "ipfs://cid",
    ...overrides,
  };
}
