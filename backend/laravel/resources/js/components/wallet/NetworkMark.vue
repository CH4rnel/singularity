<script setup lang="ts">
import { computed } from 'vue';
import type { WalletChainId } from '@/lib/wallet';

/**
 * The identity tile for a network: hue, shape and a two-letter mono tag, all
 * three at once. Colour alone would collapse for a colour-blind reader and
 * would also collide with the amber/green/red the page spends on transaction
 * status, so the shape is load-bearing rather than decorative.
 */

const props = withDefaults(
    defineProps<{
        chain: WalletChainId;
        size?: number;
        /** A bare dot, for legends and inline chips. */
        dot?: boolean;
    }>(),
    { size: 32, dot: false },
);

/**
 * Shape encodes the key family, hue and tile encode the network. Every square
 * mark is an EVM chain, and every EVM chain holds the same address — so the
 * shape is telling the truth about what a user would see if they compared two
 * of these addresses.
 *
 * The hues stay out of the amber/green/red family reserved for transaction
 * status. BNB keeps its gold because a network mark is an outlined tile with
 * two letters while a status is a filled 5px dot with a caption — the two are
 * never the same object on screen.
 */
const MARKS: Record<
    WalletChainId,
    { tag: string; color: string; radius: string; rotate: boolean }
> = {
    cyberia: {
        tag: 'CY',
        color: 'var(--cw-net-cyberia)',
        radius: '0',
        rotate: false,
    },
    robinhood: {
        tag: 'RH',
        color: 'var(--cw-net-robinhood)',
        radius: '0',
        rotate: false,
    },
    bnb: {
        tag: 'BN',
        color: 'var(--cw-net-bnb)',
        radius: '0',
        rotate: false,
    },
    base: {
        tag: 'BA',
        color: 'var(--cw-net-base)',
        radius: '0',
        rotate: false,
    },
    solana: {
        tag: 'SO',
        color: 'var(--cw-net-solana)',
        radius: '50%',
        rotate: false,
    },
    monero: {
        tag: 'XM',
        color: 'var(--cw-net-monero)',
        radius: '0',
        rotate: true,
    },
};

const mark = computed(() => MARKS[props.chain]);
</script>

<template>
    <span
        v-if="dot"
        :style="{
            width: `${size}px`,
            height: `${size}px`,
            background: mark.color,
            borderRadius: mark.radius,
            transform: mark.rotate ? 'rotate(45deg)' : undefined,
            display: 'inline-block',
            flex: 'none',
        }"
    />
    <span
        v-else
        :style="{
            width: `${size}px`,
            height: `${size}px`,
            border: `1px solid ${mark.color}`,
            color: mark.color,
            borderRadius: mark.radius,
            transform: mark.rotate ? 'rotate(45deg)' : undefined,
            display: 'flex',
            flex: 'none',
            alignItems: 'center',
            justifyContent: 'center',
            font: `500 ${Math.round(size / 3)}px/1 var(--cw-mono)`,
            letterSpacing: '0.05em',
        }"
    >
        <span :style="mark.rotate ? { transform: 'rotate(-45deg)' } : {}">
            {{ mark.tag }}
        </span>
    </span>
</template>
