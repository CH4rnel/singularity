<script setup lang="ts">
import { computed } from 'vue';
import { walletChain } from '@/lib/wallet';
import type { WalletChainId, WalletMark } from '@/lib/wallet';

/**
 * The identity tile for a network: hue, shape and a two-letter mono tag, all
 * three at once. Colour alone would collapse for a colour-blind reader and
 * would also collide with the amber/green/red the page spends on transaction
 * status, so the shape is load-bearing rather than decorative.
 *
 * Shape encodes the account model — square for an EVM chain, circle for
 * Solana, diamond for Monero, soft square for the Bitcoin family. Every square
 * mark holds the same address, so the shape is telling the truth about what a
 * user would see if they compared two of these strings.
 *
 * A network the user added is violet and dashed whatever its family, because
 * the one thing worth reading at a glance about it is that nobody verified the
 * endpoint behind it.
 */

const props = withDefaults(
    defineProps<{
        chain: WalletChainId;
        size?: number;
        /** A bare dot, for legends and inline chips. */
        dot?: boolean;
        /** Override, for a mark drawn before its network exists. */
        mark?: WalletMark;
    }>(),
    { size: 32, dot: false, mark: undefined },
);

const FALLBACK: WalletMark = {
    tag: '??',
    hue: 'var(--cw-net-custom)',
    shape: 'square',
    unverified: true,
};

const mark = computed<WalletMark>(() => {
    if (props.mark) {
        return props.mark;
    }

    try {
        return walletChain(props.chain).mark;
    } catch {
        // A chain removed while a screen still points at it — draw the tile
        // rather than take the page down with it.
        return FALLBACK;
    }
});

const radius = computed(() =>
    mark.value.shape === 'circle'
        ? '50%'
        : mark.value.shape === 'rounded'
          ? `${Math.max(2, Math.round(props.size / 12))}px`
          : '0',
);

const rotate = computed(() => mark.value.shape === 'diamond');

const line = computed(() => (mark.value.unverified ? 'dashed' : 'solid'));
</script>

<template>
    <span
        v-if="dot"
        :style="{
            width: `${size}px`,
            height: `${size}px`,
            background: mark.unverified ? 'transparent' : mark.hue,
            border: mark.unverified ? `1px dashed ${mark.hue}` : undefined,
            borderRadius: radius,
            transform: rotate ? 'rotate(45deg)' : undefined,
            display: 'inline-block',
            flex: 'none',
        }"
    />
    <span
        v-else
        :style="{
            width: `${size}px`,
            height: `${size}px`,
            border: `1px ${line} ${mark.hue}`,
            color: mark.hue,
            borderRadius: radius,
            transform: rotate ? 'rotate(45deg)' : undefined,
            display: 'flex',
            flex: 'none',
            alignItems: 'center',
            justifyContent: 'center',
            font: `500 ${Math.round(size / 3)}px/1 var(--cw-mono)`,
            letterSpacing: '0.05em',
        }"
    >
        <span :style="rotate ? { transform: 'rotate(-45deg)' } : {}">
            {{ mark.tag }}
        </span>
    </span>
</template>
