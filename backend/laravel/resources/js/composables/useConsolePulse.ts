import { computed, onBeforeUnmount, onMounted, readonly, ref } from 'vue';
import crm from '@/routes/crm';

/**
 * The console's heartbeat, shared by every lens.
 *
 * The console is read by three people at once — that is what it is for — so
 * none of its lenses may wait for a reload to tell the truth. A task claimed
 * on one desk, a line said in the room, a person written down: all of it has
 * to arrive on the other screens by itself, or the board gets refreshed
 * instead of read.
 *
 * One timer for the whole console, started by the shell and never by a page,
 * so five lenses do not become five pollers. What comes back is a version
 * string per lens — opaque, compared and never parsed — and the lens whose
 * version moved re-reads its own props through Inertia. Nothing else on the
 * page is touched: what somebody has typed into a composer survives, because
 * a refresh that eats a half-written sentence is worse than a stale row.
 *
 * Deliberately paused while the tab is hidden, and beaten once the moment it
 * comes back: a console on a second monitor should cost nothing while nobody
 * is looking at it, and be current the instant somebody is.
 */

/** How often to ask, while somebody is looking. */
const INTERVAL_MS = 5000;

/** Failures in a row before the top bar admits the console has gone quiet. */
const STALE_AFTER = 3;

/** The listener key for "every beat", whatever the versions say. */
const EVERY_BEAT = '*';

type Counts = {
    attention: number | null;
    tasks: number | null;
    chat: number | null;
};

type Beat = {
    at: string;
    v: Record<string, string>;
    counts: Counts;
};

/* Module state: one heartbeat per browser tab, whatever mounts. */
const versions = ref<Record<string, string>>({});
const counts = ref<Partial<Counts>>({});
const at = ref<string | null>(null);
const failures = ref(0);

const listeners = new Map<string, Set<() => void>>();

let timer: ReturnType<typeof setInterval> | null = null;
let holders = 0;
let inFlight = false;

/**
 * Which lens is on screen.
 *
 * Read off the address rather than declared by each page, because presence in
 * the room hangs off it: "seen just now" means this person's browser asked
 * the room for news, and somebody reading the numbers is not in the room.
 * `/crm/chat/files` is the file pile, which is not the room either.
 */
function lens(): string {
    const path = window.location.pathname;

    return path === '/crm/chat' ? 'chat' : 'other';
}

/** One question, and whatever it changed. */
async function beat(): Promise<void> {
    if (document.hidden || inFlight) {
        return;
    }

    inFlight = true;

    try {
        const response = await fetch(
            crm.pulse.url({ query: { lens: lens() } }),
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            },
        );

        if (!response.ok) {
            failures.value += 1;

            return;
        }

        const data = (await response.json()) as Beat;
        const first = at.value === null;

        at.value = data.at;
        failures.value = 0;

        // A count the server could not read is unknown and not zero: the
        // previous one stands until something says otherwise, the same way a
        // tile with no data is hatched rather than drawn at the bottom of its
        // axis.
        counts.value = {
            attention: data.counts.attention ?? counts.value.attention ?? null,
            tasks: data.counts.tasks ?? counts.value.tasks ?? null,
            chat: data.counts.chat ?? counts.value.chat ?? null,
        };

        const previous = versions.value;
        versions.value = data.v;

        // The first answer is a baseline, never news: the page it arrived on
        // has just been rendered from the same rows.
        if (first) {
            return;
        }

        for (const [key, version] of Object.entries(data.v)) {
            if (previous[key] === version) {
                continue;
            }

            for (const listener of listeners.get(key) ?? []) {
                listener();
            }
        }

        // And the lenses that ask their own question on every beat rather
        // than waiting to be told something moved (see `useConsoleBeat`).
        for (const listener of listeners.get(EVERY_BEAT) ?? []) {
            listener();
        }
    } catch {
        // A heartbeat that failed is a heartbeat; the next one is five
        // seconds away, and the top bar counts them.
        failures.value += 1;
    } finally {
        inFlight = false;
    }
}

function onVisible(): void {
    if (!document.hidden) {
        void beat();
    }
}

function start(): void {
    holders += 1;

    if (timer !== null) {
        return;
    }

    timer = setInterval(() => void beat(), INTERVAL_MS);
    document.addEventListener('visibilitychange', onVisible);
    void beat();
}

function stop(): void {
    holders = Math.max(0, holders - 1);

    if (holders > 0 || timer === null) {
        return;
    }

    clearInterval(timer);
    timer = null;
    document.removeEventListener('visibilitychange', onVisible);
}

/**
 * Run the heartbeat for as long as this component is mounted.
 *
 * Called by the console's shell, which outlives every lens inside it, so
 * navigating between lenses never restarts the timer.
 */
export function useConsolePulse() {
    onMounted(start);
    onBeforeUnmount(stop);

    return {
        counts: readonly(counts),
        at: readonly(at),
        /**
         * The console has stopped hearing from the server, and says so out
         * loud. A screen that quietly stopped updating is the one failure
         * this design cannot allow: it looks exactly like a quiet night.
         */
        stale: computed(() => failures.value >= STALE_AFTER),
        beat,
    };
}

/**
 * Re-read this lens whenever the material underneath it moves.
 *
 * `keys` are the versions this lens is drawn from — a dossier watches the
 * people and the notes, the queue watches its own sources. `active` is the
 * page's veto: a form open under the reader's hands is a good reason to let
 * a row be a few seconds old.
 */
export function useConsoleLive(
    keys: string | string[],
    reload: () => void,
    options: { active?: () => boolean } = {},
): void {
    const watched = Array.isArray(keys) ? keys : [keys];

    const fire = (): void => {
        if (options.active && !options.active()) {
            return;
        }

        reload();
    };

    onMounted(() => {
        for (const key of watched) {
            const set = listeners.get(key) ?? new Set<() => void>();
            set.add(fire);
            listeners.set(key, set);
        }
    });

    onBeforeUnmount(() => {
        for (const key of watched) {
            listeners.get(key)?.delete(fire);
        }
    });
}

/**
 * Run on every beat, rather than when a version moves.
 *
 * For the lens whose own question is cheaper and more exact than a version
 * can be: the room asks the server what changed since its last read, and gets
 * an answer that is right to the row. A version is a stamp built out of a
 * count and a timestamp, and this database keeps whole seconds — two writes
 * inside one second can leave the stamp where it was. The room is the one
 * place where that would be visible, and the one place nobody would forgive
 * it, so it does not rely on the stamp at all.
 */
export function useConsoleBeat(run: () => void): void {
    const fire = (): void => run();

    onMounted(() => {
        const set = listeners.get(EVERY_BEAT) ?? new Set<() => void>();
        set.add(fire);
        listeners.set(EVERY_BEAT, set);
    });

    onBeforeUnmount(() => {
        listeners.get(EVERY_BEAT)?.delete(fire);
    });
}
