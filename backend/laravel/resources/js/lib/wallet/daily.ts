/**
 * The wallet's daily board: the client half.
 *
 * Everything here talks to `/api/wallet/daily`, which is a *web* route and not
 * an `/api` one — this is the single wallet surface whose subject is an account
 * rather than an address, so it carries the session the rest of the wallet
 * deliberately does without. See WalletDailyController for why there is one
 * ledger and not two.
 *
 * The pure half — the streak strip, where a quest is satisfied, how long until
 * the reset — is exported separately from the calls and pinned in
 * `tests/Frontend/WalletDailyTest.mjs`, because those are the parts that decide
 * what a person is told they have earned.
 */

import { sessionCall, signInWithWallet } from '@/lib/wallet/session';

/*
 * Signing in lives in `session.ts` now: the feed needs the same session for the
 * same reason this board does, and a helper two screens share does not belong
 * to one of them. Re-exported so the screens that already ask the board for it
 * are not made to know where it moved.
 */
export { signInWithWallet };

/** One quest, as the board sends it. */
export type DailyQuest = {
    key: string;
    period: 'daily' | 'weekly';
    /** Every language the wallet speaks; the screen picks one. */
    title: Record<string, string>;
    description: Record<string, string>;
    target: number;
    /** Null when nobody is signed in — never 0, which would read as a score. */
    progress: number | null;
    completed: boolean;
    xp: number;
    /** Action keys that advance it, which is how the row knows where to go. */
    actions: string[];
};

export type DailyStanding = {
    xp: number;
    level: number;
    title: string;
    levelFloorXp: number;
    nextLevelXp: number | null;
    progressPct: number;
    currentStreak: number;
    longestStreak: number;
    lastActiveOn: string | null;
    activeToday: boolean;
    rank: number | null;
    spendable: number;
};

export type DailyUnlock = {
    key: string;
    title: Record<string, string>;
    description: Record<string, string>;
    cost: number;
    level: number;
    state: 'owned' | 'ready' | 'level' | 'xp' | 'requires';
};

export type DailyBoardRow = {
    position: number;
    name: string;
    profile_url: string | null;
    wallet_address: string | null;
    xp: number;
    level: number;
    title: string;
    current_streak: number;
};

export type DailyBoard = {
    signedIn: boolean;
    account: {
        name: string;
        address: string | null;
        profileUrl: string | null;
    } | null;
    standing: DailyStanding | null;
    quests: DailyQuest[];
    nextUnlock: DailyUnlock | null;
    streakBonuses: Record<string, number>;
    xpPerAction: Record<string, number>;
    board: DailyBoardRow[];
    resetsAt: string;
    /** Only on a check-in: the XP that press actually granted. */
    granted?: number;
};

/* ------------------------------------------------------------ transport -- */

const call = <T>(url: string, init: RequestInit = {}): Promise<T> =>
    sessionCall<T>(url, init, 'The daily board is unreachable right now.');

export const loadDailyBoard = async (): Promise<DailyBoard> => {
    const board = await call<DailyBoard>('/api/wallet/daily');

    remember(board);

    return board;
};

export const checkInToday = async (): Promise<DailyBoard> => {
    const board = await call<DailyBoard>('/api/wallet/daily/check-in', {
        method: 'POST',
    });

    remember(board);

    return board;
};

/* --------------------------------------------------------------- cache --- */

let cached: { at: number; board: DailyBoard } | null = null;

const remember = (board: DailyBoard): void => {
    cached = { at: Date.now(), board };
};

/**
 * The board, from memory when it was read recently enough.
 *
 * The swap screen wants one line off it — the streak, and what a swap pays —
 * and it must not cost a request every time somebody opens the composer from a
 * launch row. A failure is `null` and never an exception: this is a banner, and
 * a banner is not allowed to be the reason a swap screen fails to draw.
 */
export const dailyBoardCached = async (
    maxAgeMs = 120_000,
): Promise<DailyBoard | null> => {
    if (cached !== null && Date.now() - cached.at < maxAgeMs) {
        return cached.board;
    }

    try {
        const board = await loadDailyBoard();
        remember(board);

        return board;
    } catch {
        return null;
    }
};

/* ----------------------------------------------------------------- pure -- */

/** How many days the strip shows. One week, which is what a streak is read in. */
export const STREAK_DAYS = 7;

export type StreakCell = {
    /** 1-based day of the run this cell stands for. */
    day: number;
    /** Kept: behind us and paid. Today: the day this press decides. */
    state: 'kept' | 'today' | 'ahead';
    /** Milestone XP this day pays, or 0 when it pays only the visit. */
    bonus: number;
};

/**
 * The seven-day strip.
 *
 * It is a window on the *current run*, not a calendar: a person on day 23 is
 * shown days 17-23 rather than seven boxes that all say "kept" forever. The
 * window ends on today when today is already checked in, and one day past the
 * run when it is not — that empty box is what the button fills.
 */
export const streakStrip = (
    currentStreak: number,
    activeToday: boolean,
    bonuses: Record<string, number> = {},
): StreakCell[] => {
    const run = Math.max(0, Math.floor(currentStreak));
    // The day this press would be. Checked in already: today is the last kept
    // day. Not yet: today is the day after the run, whatever the run is.
    const today = activeToday ? Math.max(run, 1) : run + 1;
    const last = Math.max(today, STREAK_DAYS);
    const first = last - STREAK_DAYS + 1;

    return Array.from({ length: STREAK_DAYS }, (_, index) => {
        const day = first + index;

        return {
            day,
            state: day < today ? 'kept' : day === today ? 'today' : 'ahead',
            bonus: Number(bonuses[String(day)] ?? 0),
        };
    });
};

/** The next milestone ahead of this run, or null when none is left. */
export const nextStreakBonus = (
    currentStreak: number,
    bonuses: Record<string, number> = {},
): { day: number; xp: number } | null => {
    const run = Math.max(0, Math.floor(currentStreak));

    const ahead = Object.entries(bonuses)
        .map(([day, xp]) => ({ day: Number(day), xp: Number(xp) }))
        .filter((entry) => Number.isFinite(entry.day) && entry.day > run)
        .sort((a, b) => a.day - b.day);

    return ahead[0] ?? null;
};

/** Where a quest is satisfied: a wallet screen, or a page on the site. */
export type QuestDestination =
    | { kind: 'overlay'; overlay: 'swap' }
    | { kind: 'section'; section: string }
    | { kind: 'link'; href: string }
    | null;

/**
 * Where to send somebody who wants to finish a quest.
 *
 * Keyed off the quest's *actions* rather than its key, so renaming a quest in
 * `config/gamification.php` cannot silently strand its button. The first
 * action that has a home wins, which is why the on-chain quests list the one
 * the wallet can actually do first.
 *
 * A quest whose action has no home in this build returns null and the row draws
 * no button — a dead link is worse than an honest absence.
 */
export const questDestination = (
    actions: readonly string[],
): QuestDestination => {
    for (const action of actions) {
        switch (action) {
            case 'swap':
            case 'convert':
                return { kind: 'overlay', overlay: 'swap' };
            case 'bridge':
                return { kind: 'section', section: 'bridge' };
            case 'staking':
                return { kind: 'section', section: 'earn' };
            case 'liquidity':
                return { kind: 'link', href: '/liquidity' };
            case 'lending':
                return { kind: 'link', href: '/lending' };
            case 'post':
            case 'reaction':
                return { kind: 'section', section: 'feed' };
            case 'vote':
            case 'proposal':
            case 'comment':
                return { kind: 'section', section: 'dao' };
            case 'launchpad':
                return { kind: 'section', section: 'launchpad' };
            default:
                break;
        }
    }

    return null;
};

/** Whole hours and minutes until the daily reset, floored, never negative. */
export const untilReset = (
    resetsAt: string,
    now: number = Date.now(),
): { hours: number; minutes: number } => {
    const target = Date.parse(resetsAt);

    if (!Number.isFinite(target)) {
        return { hours: 0, minutes: 0 };
    }

    const left = Math.max(0, target - now);

    return {
        hours: Math.floor(left / 3_600_000),
        minutes: Math.floor((left % 3_600_000) / 60_000),
    };
};

/**
 * How far into the current level, as a percentage.
 *
 * The server sends this already; it is recomputed here only so the bar can
 * move the moment a check-in returns new XP without waiting for a second read.
 * Capped at 100 and floored at 0: the maximum level has no ceiling to divide by.
 */
export const levelPct = (standing: DailyStanding): number => {
    if (standing.nextLevelXp === null) {
        return 100;
    }

    const span = standing.nextLevelXp - standing.levelFloorXp;

    if (span <= 0) {
        return 100;
    }

    return Math.max(
        0,
        Math.min(100, ((standing.xp - standing.levelFloorXp) / span) * 100),
    );
};
