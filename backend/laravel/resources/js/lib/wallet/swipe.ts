/**
 * What counts as a swipe, and which way it points.
 *
 * The wallet is a phone app on a phone, and until now the only way between its
 * five destinations was the tab bar and the only way back was a link at the top
 * of the screen — both reachable, neither what a thumb does. This decides when
 * a drag is a navigation, and it is deliberately strict: a page that navigates
 * on a lazy diagonal is a page that loses your place while you scroll.
 *
 * Four conditions, and each one exists because of what it lets through:
 *
 * - **Distance.** Under 64px is a tap that wobbled, or the start of a scroll.
 * - **Straightness.** A gesture must be mostly sideways (`RATIO`), or every
 *   flick down a long list would also count as a swipe across.
 * - **Speed.** Over 700ms it is not a flick; it is somebody resting a finger on
 *   the screen while they read, and that must never move the screen.
 * - **Where it began.** The outer `EDGE` pixels belong to the operating system:
 *   iOS reads a swipe from the left edge as "go back in the browser" and
 *   Android as "go back in the app". Taking that gesture would mean the wallet
 *   and the phone answering the same movement with two different screens.
 *
 * Pure, and pinned in `tests/Frontend/WalletSwipeTest.mjs`, because the numbers
 * are the whole feature: a threshold that is wrong by twenty pixels is either a
 * gesture nobody can perform or a screen that moves on its own.
 */

/** How far a finger has to travel before it means anything. */
export const SWIPE_MIN_PX = 64;

/** How much straighter than vertical it has to be. */
export const SWIPE_RATIO = 1.8;

/** Longer than this is a rest, not a flick. */
export const SWIPE_MAX_MS = 700;

/** The strip along each edge the operating system's own gestures own. */
export const SWIPE_EDGE_PX = 24;

export type SwipePoint = { x: number; y: number; at: number };

/**
 * `back` is a finger moving left to right — the direction a page comes back
 * from on every phone. `forward` is the opposite. `null` is everything else,
 * which is most of what a finger does.
 */
export type SwipeIntent = 'back' | 'forward' | null;

export const swipeIntent = (
    start: SwipePoint,
    end: SwipePoint,
    width: number,
): SwipeIntent => {
    if (start.x <= SWIPE_EDGE_PX || start.x >= width - SWIPE_EDGE_PX) {
        return null;
    }

    if (end.at - start.at > SWIPE_MAX_MS) {
        return null;
    }

    const dx = end.x - start.x;
    const dy = Math.abs(end.y - start.y);

    if (Math.abs(dx) < SWIPE_MIN_PX || Math.abs(dx) < dy * SWIPE_RATIO) {
        return null;
    }

    return dx > 0 ? 'back' : 'forward';
};

/**
 * Whether a gesture that started on this element is the app's to read.
 *
 * Something that scrolls sideways owns every sideways gesture inside it — the
 * network strip, a table, the token row — and so does anything a finger is
 * expected to draw on or type into. Walking up from the target rather than
 * listing components keeps this true for screens that do not exist yet; an
 * element can also say so outright with `data-no-swipe`.
 */
export const swipeAllowedFrom = (target: EventTarget | null): boolean => {
    let node = target instanceof Element ? target : null;

    while (node !== null) {
        const tag = node.tagName;

        if (
            tag === 'INPUT' ||
            tag === 'TEXTAREA' ||
            tag === 'SELECT' ||
            tag === 'CANVAS' ||
            tag === 'IFRAME' ||
            tag === 'VIDEO' ||
            node.hasAttribute('data-no-swipe') ||
            node.getAttribute('contenteditable') === 'true'
        ) {
            return false;
        }

        if (node.scrollWidth > node.clientWidth + 2) {
            const overflow = getComputedStyle(node).overflowX;

            if (overflow === 'auto' || overflow === 'scroll') {
                return false;
            }
        }

        node = node.parentElement;
    }

    return true;
};
