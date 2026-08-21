<script setup lang="ts">
import { CircleCheck, Fuel, Loader } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { analytics } from '@/lib/analytics';
import { formatUnits } from '@/lib/wallet';
import type { WalletChainId } from '@/lib/wallet';
import {
    canAskForGas,
    dripCovers,
    gasSponsorStatus,
    requestGas,
    sponsorReasonKey,
} from '@/lib/wallet/gas';
import type { SponsorStatus } from '@/lib/wallet/gas';
import { walletMessages } from '@/lib/walletMessages';

/**
 * The offer to pay someone's fee, and the honest answer when it cannot be made.
 *
 * Shown only where the wallet has already discovered it cannot pay: a screen
 * that has enough gas never asks the server anything. What is offered is a
 * transfer to the user's own address, so nothing here signs, unlocks or
 * exposes a key — the transaction the user was building is still theirs to
 * sign afterwards, unchanged.
 *
 * Refusals are rendered rather than swallowed. "You own nothing on this chain
 * yet" and "the tank is empty" send a person to completely different places,
 * and a single greyed-out button would send them to neither.
 */

const props = defineProps<{
    chain: WalletChainId;
    /** The account that would receive the gas. Absent on a watch-only account. */
    address: string | undefined;
    /** The fee this screen quoted, and the coin balance meant to pay it. */
    fee: bigint | null;
    gasBalance: bigint | null;
    symbol: string;
    decimals: number;
}>();

const emit = defineEmits<{ funded: [] }>();

const { t } = useLocale(walletMessages);

type Phase = 'idle' | 'asking' | 'sent';

const status = ref<SponsorStatus | null>(null);
const phase = ref<Phase>('idle');
/** A refusal from the claim itself, which outranks the one from the status. */
const refusal = ref<string | null>(null);

const relevant = computed(() =>
    canAskForGas(props.chain, props.fee, props.gasBalance),
);

/** The address the answer in `status` is about, so it is never read for another. */
const answeredFor = ref<string | null>(null);

/**
 * Only ever asked when the wallet is actually short — never on page load.
 *
 * Keyed on the address rather than on "have we asked", because switching
 * accounts switches the subject: a cooldown belongs to one address, and showing
 * the previous account's answer under the new one would be a lie.
 */
watch(
    [relevant, () => props.address],
    async ([needed, address]) => {
        if (!needed || !address || answeredFor.value === address) {
            return;
        }

        answeredFor.value = address;
        refusal.value = null;
        phase.value = 'idle';
        status.value = await gasSponsorStatus(address);
    },
    { immediate: true },
);

const eligibility = computed(() => status.value?.address ?? null);

const enabled = computed(() => status.value?.enabled === true);

/** Offering a drip that leaves the fee unpaid would be worse than silence. */
const enough = computed(() =>
    dripCovers(status.value?.drip, props.fee, props.gasBalance),
);

const offered = computed(
    () =>
        enabled.value &&
        refusal.value === null &&
        eligibility.value?.ok === true &&
        enough.value,
);

const drip = computed(() =>
    status.value?.drip
        ? formatUnits(BigInt(status.value.drip), props.decimals, 6)
        : null,
);

/**
 * The reason to print, or null to print nothing.
 *
 * 'hasGas' is deliberately silent: it means the chain believes this address
 * can already pay, which contradicts the note this sits under, and the two
 * disagreeing on screen helps nobody.
 */
const reason = computed(() => {
    if (!enabled.value || offered.value || phase.value === 'sent') {
        return null;
    }

    const code = refusal.value ?? eligibility.value?.reason ?? null;

    if (code === null || code === 'hasGas') {
        return null;
    }

    // Qualifies, but one drip would not cover this particular fee.
    if (code === 'ok') {
        return enough.value ? null : 'sponsorTooSmall';
    }

    return sponsorReasonKey(code as never);
});

const hours = computed(() =>
    Math.max(1, Math.round((eligibility.value?.cooldownRemaining ?? 0) / 3600)),
);

const ask = async (): Promise<void> => {
    if (!props.address || phase.value === 'asking') {
        return;
    }

    phase.value = 'asking';
    refusal.value = null;

    const startedAt = Date.now();

    /*
     * The sponsorship funnel — asked, granted, refused and why.
     *
     * What a drip *cost* is deliberately absent from these events: the server
     * that signed it writes the amount the contract actually released into
     * `gas_sponsorships`, which is the only number a spend report may be built
     * on. A browser could neither know that figure nor be trusted with it, and
     * a resent event must never be able to add a cent to it.
     */
    analytics.track('gas_sponsorship_requested', {
        chain: props.chain,
        grounds: eligibility.value?.grounds as
            | 'tokens'
            | 'nft'
            | 'account'
            | 'open'
            | undefined,
    });

    const outcome = await requestGas(props.address);

    if (!outcome.ok) {
        // The refusal outranks the eligibility read that preceded it: it is the
        // later answer, and it came from the attempt itself.
        refusal.value = outcome.reason;
        phase.value = 'idle';

        // The station's own vocabulary, reused verbatim as the error code, so
        // a refusal reads the same on the dashboard as it does on this screen
        // and in the server log.
        analytics.track('gas_sponsorship_failed', {
            chain: props.chain,
            // 'ok' is not a refusal the station can actually return here,
            // but the type allows it — an unexplained failure is `unknown`.
            error_code: outcome.reason === 'ok' ? 'unknown' : outcome.reason,
            duration_ms: Date.now() - startedAt,
        });

        return;
    }

    phase.value = 'sent';

    analytics.track('gas_sponsorship_completed', {
        chain: props.chain,
        duration_ms: Date.now() - startedAt,
    });

    emit('funded');
};
</script>

<template>
    <div v-if="relevant && (offered || reason)" style="margin-top: 10px">
        <p v-if="phase === 'sent'" class="cw-note" style="color: var(--cw-ok)">
            <CircleCheck :size="14" aria-hidden="true" style="flex: none" />
            <span>{{ t('sponsorSent', { symbol }) }}</span>
        </p>

        <template v-else-if="offered">
            <p class="cw-note">
                <span>
                    <strong style="display: block">{{
                        t('sponsorTitle')
                    }}</strong>
                    {{
                        t('sponsorBody', {
                            amount: drip ?? '—',
                            symbol,
                        })
                    }}
                </span>
            </p>
            <button
                type="button"
                class="cw-btn cw-btn-secondary"
                style="margin-top: 8px; height: 44px"
                :disabled="phase === 'asking'"
                @click="ask"
            >
                <Loader
                    v-if="phase === 'asking'"
                    :size="15"
                    aria-hidden="true"
                    class="cw-spin"
                />
                <Fuel v-else :size="15" aria-hidden="true" />
                {{ t(phase === 'asking' ? 'sponsorAsking' : 'sponsorAction') }}
            </button>
        </template>

        <p v-else-if="reason" class="cw-note cw-note-warn">
            <span>{{ t(reason, { symbol, hours: String(hours) }) }}</span>
        </p>
    </div>
</template>
