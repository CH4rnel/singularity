<script setup lang="ts">
import { formatEther, parseEther } from 'ethers';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import HoldButton from '@/components/wallet/HoldButton.vue';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { arenaHasOpponent, readArenaGame } from '@/lib/wallet';
import type { ArenaGame, ArenaMove } from '@/lib/wallet';

const props = defineProps<{
    wallet: MultiWallet;
    config: {
        enabled: boolean;
        contractAddress: string;
        rpcUrl: string;
        explorerUrl: string;
    };
}>();
const emit = defineEmits<{ back: [] }>();

const query = new URLSearchParams(window.location.search);
const gameId = ref(query.get('game') ?? '');
const stake = ref('0.01');
const move = ref<ArenaMove>(1);
const game = ref<ArenaGame | null>(null);
const message = ref('');
const loading = ref(false);
const account = computed(
    () =>
        props.wallet.accounts.value.find((row) => row.family === 'evm')
            ?.address ?? '',
);
const me = computed(() => account.value.toLowerCase());
const isPlayer = computed(
    () =>
        game.value &&
        [game.value.playerOne, game.value.playerTwo].some(
            (a) => a.toLowerCase() === me.value,
        ),
);
const committed = computed(
    () =>
        game.value &&
        (game.value.playerOne.toLowerCase() === me.value
            ? game.value.playerOneCommitted
            : game.value.playerTwoCommitted),
);
const revealed = computed(
    () =>
        game.value &&
        (game.value.playerOne.toLowerCase() === me.value
            ? game.value.playerOneMove
            : game.value.playerTwoMove) !== 0,
);
const expired = computed(
    () => !!game.value && Date.now() / 1000 > game.value.deadline,
);
const states = [
    '—',
    'Waiting for opponent',
    'Commit moves',
    'Reveal moves',
    'Resolved',
    'Cancelled',
];
const moves: { id: ArenaMove; glyph: string; name: string }[] = [
    { id: 1, glyph: '◆', name: 'Rock' },
    { id: 2, glyph: '▰', name: 'Paper' },
    { id: 3, glyph: '✂', name: 'Scissors' },
];

const refresh = async (): Promise<void> => {
    if (!props.config.enabled || !gameId.value || !account.value) return;
    loading.value = true;
    try {
        game.value = await readArenaGame(
            props.config.contractAddress,
            BigInt(gameId.value),
            account.value,
            props.config.rpcUrl,
        );
        message.value = '';
    } catch (error) {
        message.value =
            error instanceof Error ? error.message : 'Unable to read game';
    } finally {
        loading.value = false;
    }
};
const run = async (
    action: () => Promise<unknown>,
    success: string,
): Promise<void> => {
    loading.value = true;
    message.value = '';
    try {
        await action();
        message.value = success;
        await refresh();
    } catch (error) {
        message.value =
            error instanceof Error ? error.message : 'Transaction failed';
    } finally {
        loading.value = false;
    }
};
const create = () =>
    run(async () => {
        const result = await props.wallet.arenaCreate(
            props.config.contractAddress,
            parseEther(stake.value),
        );
        if (result.gameId) {
            gameId.value = result.gameId.toString();
            history.replaceState(
                {},
                '',
                `/wallet?screen=arena&game=${gameId.value}`,
            );
        }
    }, 'Game created. Share its number with an opponent.');
const join = () =>
    run(
        () =>
            props.wallet.arenaJoin(
                props.config.contractAddress,
                BigInt(gameId.value),
                game.value!.stake,
            ),
        'Joined. Both players can now seal a move.',
    );
const commit = () =>
    run(
        () =>
            props.wallet.arenaCommit(
                props.config.contractAddress,
                BigInt(gameId.value),
                account.value,
                move.value,
            ),
        'Move sealed on Cyberia; its reveal secret remains encrypted in this vault.',
    );
const reveal = () =>
    run(
        () =>
            props.wallet.arenaReveal(
                props.config.contractAddress,
                BigInt(gameId.value),
                account.value,
            ),
        'Move revealed.',
    );
const settle = (method: 'resolveGame' | 'cancelExpiredGame' | 'claimPayout') =>
    run(
        () =>
            props.wallet.arenaSettle(
                props.config.contractAddress,
                BigInt(gameId.value),
                method,
            ),
        method === 'claimPayout' ? 'Payout claimed.' : 'Game settled.',
    );
let timer = 0;
onMounted(() => {
    void refresh();
    timer = window.setInterval(refresh, 5000);
});
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <section>
        <button class="cw-back" type="button" @click="emit('back')">
            ← Wallet
        </button>
        <p class="cw-eyebrow">CYBERIA ARENA · PROTOTYPE 01</p>
        <h1 class="cw-title">Rock · Paper · Scissors</h1>
        <p class="cw-note">
            A real two-player duel settled by the Cyberia chain. Moves remain
            hidden until both commitments are recorded.
        </p>

        <div
            v-if="!config.enabled"
            class="cw-card"
            style="margin-top: 20px; padding: 18px"
        >
            Interface ready. Set <code>ARENA_CONTRACT_ADDRESS</code> after
            deploying the contract to enable play.
        </div>
        <template v-else>
            <div class="cw-card" style="margin-top: 20px; padding: 18px">
                <label class="cw-label">GAME ID</label>
                <div style="display: flex; gap: 8px; margin-top: 8px">
                    <input
                        v-model="gameId"
                        class="cw-input"
                        inputmode="numeric"
                        placeholder="1"
                    /><button
                        class="cw-btn cw-btn-secondary"
                        :disabled="loading"
                        @click="refresh"
                    >
                        Open
                    </button>
                </div>
                <template v-if="game">
                    <div class="cw-row" style="margin-top: 16px">
                        <span>{{ states[game.state] }}</span
                        ><span>{{ formatEther(game.stake) }} CYBER</span>
                    </div>
                    <p class="cw-note">
                        P1 {{ game.playerOne }}<br />P2
                        {{
                            arenaHasOpponent(game) ? game.playerTwo : 'waiting…'
                        }}
                    </p>
                </template>
            </div>

            <div
                v-if="!game"
                class="cw-card"
                style="margin-top: 10px; padding: 18px"
            >
                <label class="cw-label">STAKE, CYBER</label
                ><input
                    v-model="stake"
                    class="cw-input"
                    style="margin: 8px 0 14px"
                    inputmode="decimal"
                />
                <p class="cw-note">
                    You will escrow {{ stake }} CYBER to create a public duel.
                </p>
                <HoldButton
                    label="HOLD TO CREATE GAME"
                    :disabled="loading"
                    @complete="create"
                />
            </div>

            <div
                v-else-if="game.state === 1 && !isPlayer"
                class="cw-card"
                style="margin-top: 10px; padding: 18px"
            >
                <p class="cw-note">
                    Joining escrows exactly {{ formatEther(game.stake) }} CYBER.
                </p>
                <HoldButton
                    label="HOLD TO JOIN"
                    :disabled="loading"
                    @complete="join"
                />
            </div>

            <div
                v-else-if="game.state === 2 && isPlayer"
                class="cw-card"
                style="margin-top: 10px; padding: 18px"
            >
                <p class="cw-label">CHOOSE A CARD</p>
                <div
                    style="
                        display: grid;
                        grid-template-columns: repeat(3, 1fr);
                        gap: 8px;
                        margin: 12px 0;
                    "
                >
                    <button
                        v-for="card in moves"
                        :key="card.id"
                        type="button"
                        class="cw-tile"
                        :style="
                            move === card.id
                                ? { borderColor: 'var(--cw-accent)' }
                                : {}
                        "
                        @click="move = card.id"
                    >
                        <strong style="font-size: 24px">{{ card.glyph }}</strong
                        ><span>{{ card.name }}</span>
                    </button>
                </div>
                <p class="cw-note">
                    The move and random reveal secret are encrypted locally
                    before signing.
                </p>
                <HoldButton
                    label="HOLD TO SEAL MOVE"
                    :disabled="loading || !!committed"
                    @complete="commit"
                />
            </div>

            <div
                v-else-if="game.state === 3 && isPlayer"
                class="cw-card"
                style="margin-top: 10px; padding: 18px"
            >
                <p class="cw-note">
                    Reveal the move stored in this encrypted vault.
                </p>
                <HoldButton
                    label="HOLD TO REVEAL"
                    :disabled="loading || !!revealed"
                    @complete="reveal"
                />
                <button
                    v-if="game.playerOneMove && game.playerTwoMove"
                    class="cw-btn cw-btn-secondary"
                    style="margin-top: 8px; width: 100%"
                    @click="settle('resolveGame')"
                >
                    Resolve duel
                </button>
            </div>
            <button
                v-if="expired && (game?.state ?? 5) < 4"
                class="cw-btn cw-btn-secondary"
                style="margin-top: 10px; width: 100%"
                @click="settle('cancelExpiredGame')"
            >
                Settle expired phase
            </button>
            <div
                v-if="(game?.payout ?? 0n) > 0n"
                class="cw-card"
                style="margin-top: 10px; padding: 18px"
            >
                <p class="cw-note">
                    Available payout:
                    {{ formatEther(game?.payout ?? 0n) }} CYBER
                </p>
                <HoldButton
                    label="HOLD TO CLAIM"
                    :disabled="loading"
                    @complete="settle('claimPayout')"
                />
            </div>
        </template>
        <p v-if="message" class="cw-note" style="margin-top: 12px">
            {{ message }}
        </p>
    </section>
</template>
