import { ref } from 'vue';
import {
    SWIPE_AXIS_PX,
    SWIPE_MAX_DRAG_PX,
    swipeAllowedFrom,
    swipeDrag,
    swipeIntent,
} from '@/lib/wallet/swipe';
import type { SwipeIntent, SwipePoint } from '@/lib/wallet/swipe';

/**
 * Reading a swipe off the screen the wallet is drawn on, and reporting it while
 * it happens.
 *
 * Touch events, and deliberately not pointer events — which is the opposite of
 * what the rest of this app uses and was found by watching the event stream:
 * the moment a finger moves inside something that scrolls, the compositor
 * claims the gesture and the browser fires `pointercancel`, so `pointerup`
 * never arrives and a swipe read that way silently never finishes. The touch
 * stream survives that hand-over.
 *
 * Nothing is prevented and nothing is captured. Until the gesture has declared
 * an axis this reports nothing at all, so a scroll scrolls exactly as it did;
 * once it is sideways, `drag` is called on every move with the offset the
 * screen should be holding, and `act` at the end when it went far enough.
 */
export const useSwipeNav = (options: {
    /** Somewhere to go that way — false makes the edge resist instead. */
    can: (intent: SwipeIntent) => boolean;
    act: (intent: SwipeIntent) => void;
    /** Where the screen should sit right now; `live` is "the finger is down". */
    drag: (px: number, live: boolean) => void;
}) => {
    const from = ref<SwipePoint | null>(null);
    const axis = ref<'unknown' | 'x' | 'y'>('unknown');

    const onTouchStart = (event: TouchEvent): void => {
        const touch = event.touches[0];

        axis.value = 'unknown';
        from.value =
            event.touches.length === 1 &&
            touch &&
            swipeAllowedFrom(event.target)
                ? { x: touch.clientX, y: touch.clientY, at: event.timeStamp }
                : null;
    };

    const onTouchMove = (event: TouchEvent): void => {
        const start = from.value;
        const touch = event.touches[0];

        if (start === null || !touch || axis.value === 'y') {
            return;
        }

        const dx = touch.clientX - start.x;
        const dy = touch.clientY - start.y;

        /*
         * The axis is decided once, after the first few pixels, and never
         * revisited: a gesture that starts as a scroll stays a scroll however
         * it wanders, or the screen would jump sideways under a thumb that is
         * reading a list.
         */
        if (axis.value === 'unknown') {
            if (Math.abs(dx) < SWIPE_AXIS_PX && Math.abs(dy) < SWIPE_AXIS_PX) {
                return;
            }

            axis.value = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';

            if (axis.value === 'y') {
                return;
            }
        }

        options.drag(
            swipeDrag(dx, options.can(dx > 0 ? 'back' : 'forward')),
            true,
        );
    };

    const finish = (event: TouchEvent): void => {
        const start = from.value;
        const touch = event.changedTouches[0];
        const sideways = axis.value === 'x';

        from.value = null;
        axis.value = 'unknown';

        // A second finger means a pinch or a two-finger scroll — never a
        // swipe, and the first finger's path is not evidence of one.
        if (start === null || !touch || event.touches.length > 0) {
            if (sideways) {
                options.drag(0, false);
            }

            return;
        }

        const intent = swipeIntent(
            start,
            { x: touch.clientX, y: touch.clientY, at: event.timeStamp },
            window.innerWidth,
        );

        if (sideways && intent !== null && options.can(intent)) {
            options.act(intent);

            return;
        }

        if (sideways) {
            // Not far enough, or nowhere to go: back where it came from, which
            // is the answer that tells somebody the gesture was understood and
            // refused rather than ignored.
            options.drag(0, false);
        }
    };

    /** The gesture was taken away — a system edge swipe, a call arriving. */
    const onTouchCancel = (): void => {
        const sideways = axis.value === 'x';

        from.value = null;
        axis.value = 'unknown';

        if (sideways) {
            options.drag(0, false);
        }
    };

    return {
        onTouchStart,
        onTouchMove,
        onTouchEnd: finish,
        onTouchCancel,
        MAX: SWIPE_MAX_DRAG_PX,
    };
};
