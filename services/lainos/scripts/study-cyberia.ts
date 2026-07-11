#!/usr/bin/env -S npx tsx
/** Trigger Lain's Cyberia research sweep in the running daemon. */

async function main() {
  const host = process.env.LAINOS_HTTP_HOST ?? "127.0.0.1";
  const port = Number(process.env.LAINOS_HTTP_PORT ?? 7777);
  const url = `http://${host}:${port}/research/cyberia-study/run`;
  const res = await fetch(url, { method: "POST" });
  const body = (await res.json()) as { digest?: string | null; message?: string; error?: string };
  if (!res.ok) {
    throw new Error(body.error ?? `HTTP ${res.status}`);
  }
  console.log(body.digest ?? body.message ?? "study note created");
}

main().catch((err) => {
  console.error(`cyberia study trigger failed: ${(err as Error).message}`);
  process.exit(1);
});
