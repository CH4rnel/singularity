// Solana / web3 libraries reference Node's `Buffer` global at module-eval time;
// in the browser that global doesn't exist, so they throw "Buffer is not
// defined" (e.g. when the lazily-loaded bridge chunk evaluates on a hard
// refresh of /bridge).
//
// This module is imported as the very first thing in app.ts so the global is
// installed *synchronously*, before any other module — static or lazily
// loaded — is evaluated. Previously this ran via a top-level
// `await import('buffer')` in the app entry body, which only resolved after the
// initial page chunk had already started evaluating, leaving a race.
//
// SSR runs in Node, which already provides Buffer, so we only assign when the
// global is actually missing.
import { Buffer } from 'buffer';

if (typeof globalThis.Buffer === 'undefined') {
    globalThis.Buffer = Buffer;
}
