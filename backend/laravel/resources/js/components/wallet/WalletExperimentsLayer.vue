<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { useWalletExperiments } from '@/composables/useWalletExperiments';

/**
 * What the switches on layer 03 actually do, drawn over the shell.
 *
 * Every effect is an overlay that never takes a click: a filter on the shell
 * itself would make it the containing block of every fixed sheet inside it
 * and move the send composer. `backdrop-filter` changes how things look
 * without changing where they are.
 *
 * Nothing here runs when nothing is on, and the moving ones stop for anybody
 * whose system asks for reduced motion.
 */

const { isOn } = useWalletExperiments();

const reducedMotion =
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---------------------------------------------------------------- rain --- */

const rainCanvas = ref<HTMLCanvasElement | null>(null);
let rainFrame: number | null = null;

const GLYPHS = 'ｱｲｳｴｵｶｷｸｹｺｻｼｽｾｿﾀﾁﾂﾃﾄ0123456789ABCDEF'.split('');

const stopRain = (): void => {
    if (rainFrame !== null) {
        cancelAnimationFrame(rainFrame);
        rainFrame = null;
    }
};

const startRain = (): void => {
    const canvas = rainCanvas.value;
    const ctx = canvas?.getContext('2d');

    if (!canvas || !ctx || reducedMotion) {
        return;
    }

    const box = canvas.getBoundingClientRect();
    canvas.width = Math.max(1, Math.floor(box.width));
    canvas.height = Math.max(1, Math.floor(box.height));

    const step = 16;
    const columns = Math.ceil(canvas.width / step);
    const drops = Array.from(
        { length: columns },
        () => Math.random() * -canvas.height,
    );
    let last = 0;

    const frame = (time: number): void => {
        rainFrame = requestAnimationFrame(frame);

        // Twenty frames a second is rain; sixty is a fan spinning up.
        if (time - last < 50) {
            return;
        }

        last = time;
        ctx.fillStyle = 'rgba(0, 0, 0, 0.12)';
        ctx.globalCompositeOperation = 'destination-out';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.globalCompositeOperation = 'source-over';
        ctx.font = `13px ui-monospace, monospace`;
        ctx.fillStyle = 'rgba(114, 220, 207, 0.9)';

        drops.forEach((y, column) => {
            ctx.fillText(
                GLYPHS[Math.floor(Math.random() * GLYPHS.length)],
                column * step,
                y,
            );
            drops[column] =
                y > canvas.height && Math.random() > 0.975 ? 0 : y + step;
        });
    };

    rainFrame = requestAnimationFrame(frame);
};

watch(
    () => isOn('rain'),
    (on) => {
        stopRain();

        if (on) {
            // The canvas mounts with the flag; draw once it exists.
            requestAnimationFrame(startRain);
        }
    },
    { immediate: true },
);

/* ------------------------------------------------------------ whispers --- */

/**
 * Lines from the Wired, once in a while, at the bottom of the frame. Some of
 * them are hints, which is why the switch costs a find to turn on.
 */
const WHISPERS = [
    'Present day. Present time.',
    'No matter where you go, everyone is connected.',
    'Close the world. Open the nExt.',
    'Let’s all love Lain.',
    'The Wired is not a place.',
    'Who are you?',
    'You are not alone.',
    'ls -a',
    'Look into the noise.',
    'Robots are told things people are not.',
];

const whisper = ref<string | null>(null);
let whisperTimer: ReturnType<typeof setTimeout> | null = null;

const stopWhispers = (): void => {
    if (whisperTimer !== null) {
        clearTimeout(whisperTimer);
        whisperTimer = null;
    }

    whisper.value = null;
};

const nextWhisper = (delay: number): void => {
    whisperTimer = setTimeout(() => {
        whisper.value = WHISPERS[Math.floor(Math.random() * WHISPERS.length)];
        whisperTimer = setTimeout(() => {
            whisper.value = null;
            nextWhisper(25_000 + Math.random() * 35_000);
        }, 6_000);
    }, delay);
};

watch(
    () => isOn('whispers'),
    (on) => {
        stopWhispers();

        if (on) {
            nextWhisper(4_000);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    stopRain();
    stopWhispers();
});
</script>

<template>
    <canvas
        v-if="isOn('rain')"
        ref="rainCanvas"
        class="cw-x cw-x-rain"
        aria-hidden="true"
    />
    <div v-if="isOn('crt')" class="cw-x cw-x-crt" aria-hidden="true" />
    <div v-if="isOn('psyche')" class="cw-x cw-x-psyche" aria-hidden="true" />
    <div v-if="isOn('invert')" class="cw-x cw-x-invert" aria-hidden="true" />
    <div v-if="whisper" class="cw-x-whisper" aria-live="polite">
        {{ whisper }}
    </div>
</template>
