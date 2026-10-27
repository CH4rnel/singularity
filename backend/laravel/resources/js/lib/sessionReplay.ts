/**
 * The Inertia half of session recording (Microsoft Clarity).
 *
 * The tag, the notice and the refusal live in
 * `resources/views/partials/session-replay.blade.php`, because the static
 * landing page has no bundle and must behave exactly like every other page.
 * This module only talks to the `window.cyberiaReplay` that partial defines —
 * and when the server decided this visit is not recorded (an operator, a store
 * build, Do Not Track, no project configured) there is no such object and
 * every function here does nothing.
 *
 * What it adds is what only the app knows: which screen is open, so recording
 * pauses on the ones that hold secrets and resumes after them; who is looking,
 * as tags an operator can filter recordings by; and the product events, so a
 * recording can be found by "the swap failed" rather than by guessing.
 */
import { nativeShell } from '@/lib/native';

type CyberiaReplay = {
    paused: string[];
    recording(): boolean;
    call(method: string, ...args: unknown[]): void;
    start(): void;
    pause(): void;
    stop(): void;
};

declare global {
    interface Window {
        cyberiaReplay?: CyberiaReplay;
    }
}

const replay = (): CyberiaReplay | undefined =>
    typeof window === 'undefined' ? undefined : window.cyberiaReplay;

type ReplayPage = {
    component: string;
    props: {
        auth?: { user?: { id?: number | string } | null };
    };
};

/**
 * Follow a navigation: pause on a secret screen, otherwise record and say
 * which screen this is.
 *
 * The account is sent as `user:<id>` and never as a name or an address — on
 * this site a name is very often the wallet address it signed in with.
 */
export const followReplayPage = (
    page: ReplayPage,
    installation: string | null,
): void => {
    const recorder = replay();

    if (!recorder) {
        return;
    }

    if (recorder.paused.some((prefix) => page.component.startsWith(prefix))) {
        recorder.pause();

        return;
    }

    recorder.start();
    recorder.call('set', 'page', page.component);
    recorder.call('set', 'shell', nativeShell() ?? 'browser');

    if (installation) {
        recorder.call('set', 'install', installation);
    }

    const user = page.props.auth?.user?.id;

    if (user !== undefined && user !== null) {
        recorder.call('identify', `user:${user}`);
    }
};

/** A product event, by name only: its properties stay with our own tables. */
export const replayEvent = (name: string): void => {
    replay()?.call('event', name);
};

/** Wallet → Security's switch, which covers recordings too. */
export const setReplayEnabled = (on: boolean): void => {
    const recorder = replay();

    if (!recorder) {
        return;
    }

    if (on) {
        recorder.start();

        return;
    }

    recorder.stop();
};

/** The refusal given on the notice, which only the partial sees. */
export const onReplayRefused = (handler: () => void): void => {
    if (typeof window !== 'undefined') {
        window.addEventListener('cyberia:replay-refused', handler);
    }
};
