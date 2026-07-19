<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Contract, JsonRpcProvider } from 'ethers';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useWallet } from '@/composables/useWallet';
import {
    CYBERIA_CHAIN_ID,
    cyberiaReadRpcUrl,
    ensureCyberiaNetwork,
} from '@/lib/evmChains';

const env =
    (import.meta as { env?: Record<string, string | undefined> }).env ?? {};
// Baked-in production deploy (deployments/cyberia-pixelbattle.json); the env
// var only overrides it for staging/redeploys.
const PIXEL_CONTRACT =
    env.VITE_PIXEL_CONTRACT || '0x9BCB235E3Ab18c884e82db8679BfA5A5AC0dd45c';

const WIDTH = 64;
const HEIGHT = 64;
// Display scale: each on-chain pixel is drawn as SCALE×SCALE screen pixels.
const SCALE = 9;

// Fixed 16-colour palette. A pixel byte is an index into this; index 0 is the
// blank-canvas background. Must match what painters see — colours are NOT stored
// on-chain, only the index. (Classic r/place 2017 palette.)
const PALETTE = [
    '#ffffff',
    '#e4e4e4',
    '#888888',
    '#222222',
    '#ffa7d1',
    '#e50000',
    '#e59500',
    '#a06a42',
    '#e5d900',
    '#94e044',
    '#02be01',
    '#00d3dd',
    '#0083c7',
    '#0000ea',
    '#cf6ee4',
    '#820080',
];

const PIXEL_ABI = [
    'function paint(uint16 x, uint16 y, uint8 color)',
    'function getCanvas() view returns (bytes)',
    'function totalPaints() view returns (uint256)',
    'event Painted(uint16 indexed x, uint16 indexed y, uint8 color, address indexed painter)',
];

const wallet = useWallet();

const canvasRef = ref<HTMLCanvasElement | null>(null);
const selectedColor = ref(5); // start on red
const hover = ref<{ x: number; y: number } | null>(null);
const totalPaints = ref<number | null>(null);
const loading = ref(false);
const painting = ref(false);
const status = ref<string | null>(null);
const error = ref<string | null>(null);

const isConfigured = computed(() => !!PIXEL_CONTRACT);

const readProvider = new JsonRpcProvider(cyberiaReadRpcUrl(), {
    chainId: CYBERIA_CHAIN_ID,
    name: 'cyberia',
});

const drawPixel = (x: number, y: number, colorIndex: number): void => {
    const ctx = canvasRef.value?.getContext('2d');

    if (!ctx) {
        return;
    }

    ctx.fillStyle = PALETTE[colorIndex] ?? PALETTE[0];
    ctx.fillRect(x * SCALE, y * SCALE, SCALE, SCALE);
};

const renderCanvas = (hex: string): void => {
    const ctx = canvasRef.value?.getContext('2d');

    if (!ctx) {
        return;
    }

    const body = hex.startsWith('0x') ? hex.slice(2) : hex;

    for (let i = 0; i < WIDTH * HEIGHT; i++) {
        const byte = parseInt(body.slice(i * 2, i * 2 + 2), 16) || 0;
        const x = i % WIDTH;
        const y = Math.floor(i / WIDTH);
        ctx.fillStyle = PALETTE[byte] ?? PALETTE[0];
        ctx.fillRect(x * SCALE, y * SCALE, SCALE, SCALE);
    }
};

const loadCanvas = async (): Promise<void> => {
    if (!isConfigured.value) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        const c = new Contract(PIXEL_CONTRACT, PIXEL_ABI, readProvider);
        const [hex, paints] = await Promise.all([
            c.getCanvas(),
            c.totalPaints(),
        ]);
        renderCanvas(hex as string);
        totalPaints.value = Number(paints);
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        loading.value = false;
    }
};

const coordsFromEvent = (e: MouseEvent): { x: number; y: number } | null => {
    const canvas = canvasRef.value;

    if (!canvas) {
        return null;
    }

    const rect = canvas.getBoundingClientRect();
    const x = Math.floor(((e.clientX - rect.left) / rect.width) * WIDTH);
    const y = Math.floor(((e.clientY - rect.top) / rect.height) * HEIGHT);

    if (x < 0 || x >= WIDTH || y < 0 || y >= HEIGHT) {
        return null;
    }

    return { x, y };
};

const onMove = (e: MouseEvent): void => {
    hover.value = coordsFromEvent(e);
};

const onLeave = (): void => {
    hover.value = null;
};

const onCanvasClick = async (e: MouseEvent): Promise<void> => {
    if (painting.value) {
        return;
    }

    const cell = coordsFromEvent(e);

    if (!cell) {
        return;
    }

    if (!wallet.isConnected.value) {
        await wallet.connect();

        if (!wallet.isConnected.value) {
            return;
        }
    }

    await paintPixel(cell.x, cell.y, selectedColor.value);
};

const paintPixel = async (
    x: number,
    y: number,
    color: number,
): Promise<void> => {
    painting.value = true;
    error.value = null;
    status.value = 'Confirm paint() in your wallet…';
    // Optimistic: show it immediately; a failed tx is corrected on the next poll.
    drawPixel(x, y, color);

    try {
        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();
        const c = new Contract(PIXEL_CONTRACT, PIXEL_ABI, signer);
        const tx = await c.paint(x, y, color);
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Painted (${x}, ${y}).`;
        await loadCanvas();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
        status.value = null;
        await loadCanvas(); // revert the optimistic pixel
    } finally {
        painting.value = false;
    }
};

let poll: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    void loadCanvas();
    poll = setInterval(() => {
        if (!painting.value) {
            void loadCanvas();
        }
    }, 7000);
});

onUnmounted(() => {
    if (poll) {
        clearInterval(poll);
    }
});
</script>

<template>
    <Head title="Cyberia Pixel Battle" />

    <div class="pixel-page">
        <div class="pixel">
            <header class="intro">
                <h1>Pixel Battle</h1>
                <p>
                    A shared 64×64 canvas on Cyberia (chain 49406). Pick a
                    colour and click a cell — each pixel is one on-chain
                    transaction (you only pay gas). The board is whatever
                    everyone paints it into.
                </p>
            </header>

            <div v-if="!isConfigured" class="banner banner--warn">
                Set <code>VITE_PIXEL_CONTRACT</code> in <code>.env</code> and
                rebuild the frontend.
            </div>

            <template v-else>
                <div class="palette">
                    <button
                        v-for="(c, i) in PALETTE"
                        :key="i"
                        class="swatch"
                        :class="{ 'swatch--active': selectedColor === i }"
                        :style="{ backgroundColor: c }"
                        :title="`Colour ${i}`"
                        @click="selectedColor = i"
                    />
                </div>

                <div class="board">
                    <canvas
                        ref="canvasRef"
                        :width="WIDTH * SCALE"
                        :height="HEIGHT * SCALE"
                        class="canvas"
                        :class="{ 'canvas--busy': painting }"
                        @click="onCanvasClick"
                        @mousemove="onMove"
                        @mouseleave="onLeave"
                    />
                </div>

                <div class="meta">
                    <Button
                        v-if="!wallet.isConnected.value"
                        @click="wallet.connect()"
                    >
                        Connect wallet
                    </Button>
                    <span v-else class="addr">{{ wallet.address.value }}</span>

                    <span v-if="hover"
                        >cursor: ({{ hover.x }}, {{ hover.y }})</span
                    >
                    <span v-if="totalPaints !== null"
                        >paints: {{ totalPaints }}</span
                    >
                    <span v-if="loading">syncing…</span>
                </div>

                <p v-if="status" class="status">{{ status }}</p>
                <p v-if="error" class="status status--error">{{ error }}</p>
            </template>
        </div>
    </div>
</template>

<style scoped>
.pixel {
    max-width: 760px;
    margin: 0 auto;
    padding: 1.5rem 1rem 3rem;
}

.intro h1 {
    font-size: 1.6rem;
    font-weight: 700;
}

.intro p {
    color: var(--muted-foreground, #888);
    margin-top: 0.25rem;
}

.banner {
    margin-top: 1rem;
    padding: 0.75rem 1rem;
    border-radius: 0.5rem;
}

.banner--warn {
    background: #3a2a00;
    color: #ffd479;
}

.palette {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 1.25rem 0;
}

.swatch {
    width: 30px;
    height: 30px;
    border-radius: 4px;
    border: 2px solid transparent;
    cursor: pointer;
}

.swatch--active {
    border-color: #fff;
    outline: 1px solid #000;
}

.board {
    display: inline-block;
    line-height: 0;
    border: 1px solid var(--border, #333);
}

.canvas {
    width: 576px;
    max-width: 100%;
    height: auto;
    image-rendering: pixelated;
    cursor: crosshair;
    touch-action: none;
}

.canvas--busy {
    cursor: progress;
    opacity: 0.85;
}

.meta {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 1rem;
    color: var(--muted-foreground, #888);
    font-size: 0.9rem;
}

.addr {
    font-family: monospace;
    word-break: break-all;
}

.status {
    margin-top: 0.75rem;
}

.status--error {
    color: #ff6b6b;
}
</style>
