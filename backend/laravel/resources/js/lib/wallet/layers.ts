/**
 * The layers under Settings.
 *
 * The wallet's face is a plain wallet: no experience, no streak, nothing to
 * level. Somebody who opens Settings and keeps scrolling already wanted
 * something, so that is where progress starts, and it goes *down*:
 *
 *   Layer 02 — Developer settings, at the bottom of Settings.
 *   Layer 03 — Experiments: strange switches, and a terminal.
 *   Layer 07 — the gallery: generative net art, mintable as NFTs.
 *
 * Experience still comes from the one ledger the site keeps
 * (`GamificationService`); what is new is *where* it is shown and what it
 * opens. Secrets are phrases hidden around the wallet and the site, typed into
 * the terminal, checked by the server against hashes it never sends. Opening a
 * layer is itself a secret, with a code the wallet sends by itself.
 *
 * The pure half — what is unlocked, what the terminal answers — is pinned in
 * `tests/Frontend/WalletLayersTest.mjs`.
 */

import type { DailyBoard, DailySecret } from '@/lib/wallet/daily';
import { sessionCall } from '@/lib/wallet/session';

/** The level the gallery opens at. Mirrors the hint on `layer_07`. */
export const GALLERY_LEVEL = 3;

export type LayerId = '02' | '03' | '07';

/** What the wallet sends when a layer is opened. Public on purpose. */
export const layerCode = (layer: LayerId): string => `layer:${layer}`;

export const layerSecretKey = (layer: LayerId): string => `layer_${layer}`;

/* ------------------------------------------------------------ unlocks --- */

/** What a switch or a picture asks for: a level, or a particular find. */
export type Requirement = { level: number } | { secret: string };

/**
 * Whether somebody standing here may have it.
 *
 * Nobody signed in is level 1 with nothing found: the first rung of
 * everything is open to whoever got this far, and the rest says what it
 * wants rather than being hidden.
 */
export const isUnlocked = (
    requirement: Requirement,
    level: number | null,
    found: readonly string[],
): boolean =>
    'level' in requirement
        ? (level ?? 1) >= requirement.level
        : found.includes(requirement.secret);

/** Keys of everything found, from the board. */
export const foundKeys = (
    secrets: readonly DailySecret[] | undefined,
): string[] =>
    (secrets ?? [])
        .filter((secret) => secret.found)
        .map((secret) => secret.key);

/* ----------------------------------------------------------- experiments - */

/**
 * The switches on layer 03. Each one is cosmetic and local to this device —
 * stored next to the theme, applied by `WalletExperimentsLayer.vue` — and
 * each one is gated, because finding things is the point of the layer.
 */
export const EXPERIMENTS = [
    { id: 'crt', requires: { level: 1 } },
    { id: 'rain', requires: { level: 2 } },
    { id: 'whispers', requires: { secret: 'present' } },
    { id: 'glitch', requires: { level: 3 } },
    { id: 'psyche', requires: { level: 4 } },
    { id: 'invert', requires: { secret: 'robots' } },
] as const satisfies readonly { id: string; requires: Requirement }[];

export type ExperimentId = (typeof EXPERIMENTS)[number]['id'];

/* ------------------------------------------------------------- terminal --- */

/** What one line typed into the terminal does. */
export type TerminalAction =
    | { kind: 'print'; lines: string[] }
    | { kind: 'clear' }
    | { kind: 'open'; target: 'gallery' }
    | { kind: 'claim'; code: string }
    | { kind: 'none' };

const HIDDEN_FILE = '.navi';

/**
 * Read a line. Everything that is not a command is a guess.
 *
 * The files are fixed and tiny on purpose: `ls -a` shows one more than `ls`,
 * and that one is a secret. `who` is the address or nobody.
 */
export const runCommand = (
    input: string,
    context: { who: string | null; readme: string[]; help: string[] },
): TerminalAction => {
    const line = input.trim();

    if (line === '') {
        return { kind: 'none' };
    }

    const [command, ...args] = line.split(/\s+/);
    const argument = args.join(' ');

    switch (command.toLowerCase()) {
        case 'help':
        case '?':
            return { kind: 'print', lines: context.help };
        case 'clear':
        case 'cls':
            return { kind: 'clear' };
        case 'whoami':
            return { kind: 'print', lines: [context.who ?? 'anonymous'] };
        case 'ls':
            return {
                kind: 'print',
                lines: [
                    [
                        'gallery/',
                        'readme.txt',
                        ...(args.includes('-a') || args.includes('-la')
                            ? [HIDDEN_FILE]
                            : []),
                    ].join('  '),
                ],
            };
        case 'cat':
            if (argument === 'readme.txt') {
                return { kind: 'print', lines: context.readme };
            }

            if (argument === HIDDEN_FILE) {
                return {
                    kind: 'print',
                    lines: ['NAVI//boot', 'password: navi'],
                };
            }

            return {
                kind: 'print',
                lines: [`cat: ${argument || '?'}: no such file`],
            };
        case 'cd':
            if (argument.replace(/\/$/, '') === 'gallery') {
                return { kind: 'open', target: 'gallery' };
            }

            return {
                kind: 'print',
                lines: [`cd: ${argument || '~'}: no such layer`],
            };
        default:
            return { kind: 'claim', code: line };
    }
};

/**
 * Lines the Wired answers a wrong guess with. Picked by the guess itself so
 * the same wrong word gets the same answer, which reads as a reply rather
 * than a random number generator.
 */
export const MISS_LINES = [
    'no such protocol.',
    'the wired does not know that word.',
    'signal lost.',
    'nobody answers.',
    'try listening first.',
    'that is not a door.',
] as const;

export const missLine = (guess: string): string => {
    let sum = 0;

    for (const char of guess) {
        sum = (sum * 31 + char.charCodeAt(0)) >>> 0;
    }

    return MISS_LINES[sum % MISS_LINES.length];
};

/* ------------------------------------------------------------ transport -- */

export type SecretClaim = {
    found: boolean;
    fresh: boolean;
    granted: number;
    secret: {
        key: string;
        kind: 'layer' | 'word';
        xp: number;
        title: Record<string, string>;
    } | null;
};

export const claimSecret = (
    code: string,
): Promise<DailyBoard & { claim: SecretClaim }> =>
    sessionCall<DailyBoard & { claim: SecretClaim }>(
        '/api/wallet/daily/secret',
        { method: 'POST', body: JSON.stringify({ code }) },
        'The Wired is unreachable right now.',
    );

/**
 * Mark a layer as opened, once.
 *
 * Only asks when the board says it is still unfound, so walking back and
 * forth through Settings does not spend the terminal's throttle. Signed out
 * it does nothing at all: there is nobody to credit.
 */
export const discoverLayer = async (
    layer: LayerId,
    board: DailyBoard | null,
): Promise<(DailyBoard & { claim: SecretClaim }) | null> => {
    if (board === null || !board.signedIn) {
        return null;
    }

    const known = board.secrets?.find(
        (secret) => secret.key === layerSecretKey(layer),
    );

    if (known === undefined || known.found) {
        return null;
    }

    try {
        return await claimSecret(layerCode(layer));
    } catch {
        return null;
    }
};
