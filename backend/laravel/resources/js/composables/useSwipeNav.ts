import { ref } from 'vue';
import { swipeAllowedFrom, swipeIntent } from '@/lib/wallet/swipe';
import type { SwipeIntent, SwipePoint } from '@/lib/wallet/swipe';

/**
 * Reading a swipe off the screen the wallet is drawn on.
 *
 * Touch events, and deliberately not pointer events — which is the opposite of
 * what the rest of this app uses and was found the hard way. The moment a
 * finger moves inside something that scrolls, the compositor claims the
 * gesture and the browser fires `pointercancel`: no `pointerup` ever arrives,
 * so a swipe read through pointer events is a swipe that silently never
 * finishes. The touch stream survives that hand-over, which is why every swipe
 * implementation on the web is written on it.
 *
 * Nothing is prevented and nothing is captured. The decision happens at the end
 * of the gesture, so scrolling behaves exactly as it did: a drag that turns out
 * to be a scroll has already scrolled, and one that turns out to be a swipe has
 * moved nothing.
 */
export const useSwipeNav = (act: (intent: SwipeIntent) => void) => {
    const from = ref<SwipePoint | null>(null);

    const onTouchStart = (event: TouchEvent): void => {
        const touch = event.touches[0];

        from.value =
            event.touches.length === 1 &&
            touch &&
            swipeAllowedFrom(event.target)
                ? {
                      x: touch.clientX,
                      y: touch.clientY,
                      at: event.timeStamp,
                  }
                : null;
    };

    const onTouchEnd = (event: TouchEvent): void => {
        const start = from.value;
        const touch = event.changedTouches[0];
        from.value = null;

        // A second finger means a pinch, a two-finger scroll or a mis-hold —
        // never a swipe, and the first finger's path is not evidence of one.
        if (start === null || !touch || event.touches.length > 0) {
            return;
        }

        const intent = swipeIntent(
            start,
            { x: touch.clientX, y: touch.clientY, at: event.timeStamp },
            window.innerWidth,
        );

        if (intent !== null) {
            act(intent);
        }
    };

    /** The gesture was taken away — a system edge swipe, a call arriving. */
    const onTouchCancel = (): void => {
        from.value = null;
    };

    return { onTouchStart, onTouchEnd, onTouchCancel };
};
