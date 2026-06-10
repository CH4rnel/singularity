<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';

import { useBridge } from '@/composables/useBridge';
import { useBridgeAnalytics } from '@/composables/useBridgeAnalytics';
import { useBridgeFlow } from '@/composables/useBridgeFlow';
import { useSolanaWallet } from '@/composables/useSolanaWallet';
import { useWallet } from '@/composables/useWallet';
import type { BridgeDirection } from '@/lib/addressValidation';
import type { BridgeFeeConfig } from '@/lib/bridgeFee';
import { BRIDGE_TOKENS } from '@/lib/bridgeTokens';
import StepConfigure from './StepConfigure.vue';
import StepDirection from './StepDirection.vue';
import StepReview from './StepReview.vue';
import StepSigning from './StepSigning.vue';
import StepTracking from './StepTracking.vue';

const props = withDefaults(
    defineProps<{
        relayerEvmAddress?: string | null;
        cyberSolUsd?: number | null;
        feeConfig?: BridgeFeeConfig;
        gasDropConfig?: { enabled: boolean; amount: string };
    }>(),
    {
        relayerEvmAddress: null,
        cyberSolUsd: null,
        feeConfig: () => ({ flatUsd: 0.1, rateBps: 0 }),
        gasDropConfig: () => ({ enabled: true, amount: '0.01' }),
    },
);

const gasDropPlanned = ref(false);

const checkEvmRecipientNeedsGas = async (
    recipient: string,
): Promise<boolean> => {
    if (!props.gasDropConfig.enabled) {
        return false;
    }

    try {
        const response = await fetch('/api/rpc/cyberia', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                jsonrpc: '2.0',
                id: 1,
                method: 'eth_getBalance',
                params: [recipient, 'latest'],
            }),
        });
        const json = await response.json();
        const hex = json.result;

        if (typeof hex !== 'string' || !hex.startsWith('0x')) {
            return false;
        }

        return BigInt(hex) === 0n;
    } catch {
        return false;
    }
};

const flow = useBridgeFlow();
const bridge = useBridge();
const evmWallet = useWallet();
const solanaWallet = useSolanaWallet();
const analytics = useBridgeAnalytics();

onMounted(() => {
    analytics.track('page_view');
});

const sourceWalletConnected = computed(() =>
    flow.context.direction === 'sol_to_evm'
        ? solanaWallet.isConnected.value
        : evmWallet.isConnected.value,
);

const sourceWalletAddress = computed(() =>
    flow.context.direction === 'sol_to_evm'
        ? solanaWallet.address.value
        : evmWallet.address.value,
);

const sourceWalletConnecting = computed(() =>
    flow.context.direction === 'sol_to_evm'
        ? solanaWallet.isConnecting.value
        : evmWallet.isConnecting.value,
);

const sourceBalance = computed(() => {
    if (!flow.context.direction) {
        return null;
    }

    const chain: 'evm' | 'solana' =
        flow.context.direction === 'sol_to_evm' ? 'solana' : 'evm';

    if (flow.context.token === 'CYBER.sol') {
        return chain === 'solana'
            ? bridge.solanaCyberBalance.value
            : bridge.cyberSolBalance.value;
    }

    return bridge.getTokenBalance(flow.context.token, chain);
});

const refreshSourceBalance = () => {
    if (!flow.context.direction) {
        return;
    }

    const token = flow.context.token;

    if (flow.context.direction === 'sol_to_evm' && solanaWallet.address.value) {
        if (token === 'CYBER.sol') {
            bridge.fetchSolanaCyberBalance(solanaWallet.address.value);
        } else {
            bridge.fetchTokenBalanceSolana(token, solanaWallet.address.value);
        }
    } else if (
        flow.context.direction === 'evm_to_sol' &&
        evmWallet.address.value
    ) {
        if (token === 'CYBER.sol') {
            bridge.fetchCyberSolBalance(evmWallet.address.value);
        } else {
            bridge.fetchTokenBalanceEvm(token, evmWallet.address.value);
        }
    }
};

watch(sourceWalletConnected, (connected) => {
    if (!connected || !flow.context.direction) {
        return;
    }

    if (flow.context.direction === 'sol_to_evm' && solanaWallet.address.value) {
        flow.context.sourceAddress = solanaWallet.address.value;
    } else if (
        flow.context.direction === 'evm_to_sol' &&
        evmWallet.address.value
    ) {
        flow.context.sourceAddress = evmWallet.address.value;
    }

    refreshSourceBalance();
});

watch(
    () => flow.context.token,
    () => refreshSourceBalance(),
);

const handleDirection = (direction: BridgeDirection) => {
    flow.chooseDirection(direction);
    analytics.track('direction_selected', { direction });

    if (direction === 'sol_to_evm' && solanaWallet.address.value) {
        flow.context.sourceAddress = solanaWallet.address.value;
    } else if (direction === 'evm_to_sol' && evmWallet.address.value) {
        flow.context.sourceAddress = evmWallet.address.value;
    }

    refreshSourceBalance();
};

const handleConnectSource = async () => {
    if (!flow.context.direction) {
        return;
    }

    if (flow.context.direction === 'sol_to_evm') {
        const addr = await solanaWallet.connect();

        if (addr) {
            analytics.track('solana_wallet_connected', {
                source_address: addr,
            });
        }
    } else {
        const addr = await evmWallet.connect();

        if (addr) {
            analytics.track('evm_wallet_connected', { source_address: addr });
        }
    }
};

const handleConfigureNext = async () => {
    analytics.track('destination_entered', {
        direction: flow.context.direction!,
        destination_address: flow.context.destinationAddress,
        amount: flow.context.amount,
    });

    gasDropPlanned.value =
        flow.context.direction === 'sol_to_evm'
            ? await checkEvmRecipientNeedsGas(flow.context.destinationAddress)
            : false;

    flow.proceedToReview();
};

const handleConfirm = async () => {
    if (!flow.context.direction) {
        return;
    }

    flow.beginSigning();
    analytics.track('lock_tx_submitted', {
        direction: flow.context.direction,
        amount: flow.context.amount,
        metadata: { token: flow.context.token },
    });

    const tokenInfo = BRIDGE_TOKENS[flow.context.token];

    try {
        let result: { txHash: string; nonce: number } | null;

        if (tokenInfo.model === 'native') {
            // CYBER — through CyberBridge contract
            result =
                flow.context.direction === 'evm_to_sol'
                    ? await bridge.redeemCyberSolOnEvm(
                          flow.context.amount,
                          flow.context.destinationAddress,
                      )
                    : await bridge.lockNativeOnSolana(
                          flow.context.amount,
                          flow.context.destinationAddress,
                      );
        } else if (flow.context.direction === 'evm_to_sol') {
            if (!props.relayerEvmAddress) {
                throw new Error(
                    'Bridge relayer address not configured on the server. Run `php artisan bridge:show-relayer` and add the printed BRIDGE_RELAYER_ADDRESS to .env.',
                );
            }

            result = await bridge.erc20TransferToRelayer(
                flow.context.token,
                flow.context.amount,
                props.relayerEvmAddress,
            );
        } else {
            result = await bridge.splTransferToHotWallet(
                flow.context.token,
                flow.context.amount,
            );
        }

        if (!result) {
            throw new Error('Transaction cancelled');
        }

        flow.context.sourceTxHash = result.txHash;
        flow.context.sourceNonce = result.nonce;

        analytics.track('lock_tx_confirmed', {
            direction: flow.context.direction,
            amount: flow.context.amount,
            source_address: flow.context.sourceAddress,
            destination_address: flow.context.destinationAddress,
            metadata: { tx_hash: result.txHash, nonce: result.nonce },
        });
    } catch (err) {
        const message = err instanceof Error ? err.message : 'Signing failed';

        analytics.track('lock_tx_rejected', {
            direction: flow.context.direction,
            amount: flow.context.amount,
            error_message: message,
        });
        flow.markFailed(message);

        return;
    }

    flow.beginSubmitting();
    analytics.track('bridge_submitted', {
        direction: flow.context.direction,
        amount: flow.context.amount,
        source_address: flow.context.sourceAddress,
        destination_address: flow.context.destinationAddress,
    });

    try {
        const csrfToken = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1];
        const response = await fetch('/bridge/submit', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                direction: flow.context.direction,
                token: flow.context.token,
                source_tx_hash: flow.context.sourceTxHash,
                source_nonce: flow.context.sourceNonce,
                sender_address: flow.context.sourceAddress,
                recipient_address: flow.context.destinationAddress,
                amount: flow.context.amount,
                session_id: analytics.sessionId,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            const message =
                data.message ?? `Submit failed (HTTP ${response.status})`;

            analytics.track('bridge_submit_failed', {
                direction: flow.context.direction,
                error_message: message,
            });
            flow.markFailed(message);

            return;
        }

        const br = data.bridge_request;

        flow.beginTracking(br.id);
        analytics.track('tracking_started', {
            direction: flow.context.direction,
            bridge_request_id: br.id,
        });

        if (br.status === 'completed') {
            flow.markSucceeded(br.destination_tx_hash ?? null);
        } else if (br.status === 'failed') {
            flow.markFailed(br.error_message ?? 'Bridge failed');
        }
    } catch (err) {
        const message = err instanceof Error ? err.message : 'Submit failed';

        analytics.track('bridge_submit_failed', {
            direction: flow.context.direction,
            error_message: message,
        });
        flow.markFailed(message);
    }
};

const handleReset = () => {
    flow.reset();
};
</script>

<template>
    <div class="w-full">
        <StepDirection
            v-if="flow.step.value === 'idle'"
            @select="handleDirection"
        />

        <StepConfigure
            v-else-if="
                flow.step.value === 'configuring' && flow.context.direction
            "
            :direction="flow.context.direction"
            v-model:token="flow.context.token"
            v-model:amount="flow.context.amount"
            v-model:destination-address="flow.context.destinationAddress"
            :source-wallet-connected="sourceWalletConnected"
            :source-wallet-address="sourceWalletAddress"
            :source-wallet-connecting="sourceWalletConnecting"
            :source-balance="sourceBalance"
            :recent="flow.recentForDirection.value"
            @connect-source="handleConnectSource"
            @next="handleConfigureNext"
            @back="handleReset"
        />

        <StepReview
            v-else-if="
                flow.step.value === 'reviewing' && flow.context.direction
            "
            :direction="flow.context.direction"
            :token="flow.context.token"
            :amount="flow.context.amount"
            :source-address="flow.context.sourceAddress"
            :destination-address="flow.context.destinationAddress"
            :cyber-sol-usd="props.cyberSolUsd"
            :fee-config="props.feeConfig"
            :gas-drop-planned="gasDropPlanned"
            :gas-drop-amount="props.gasDropConfig.amount"
            v-model:confirmed="flow.context.confirmed"
            @confirm="handleConfirm"
            @back="flow.backToConfigure"
        />

        <StepSigning
            v-else-if="
                (flow.step.value === 'signing' ||
                    flow.step.value === 'submitting') &&
                flow.context.direction
            "
            :direction="flow.context.direction"
            :phase="flow.step.value === 'signing' ? 'signing' : 'submitting'"
        />

        <StepTracking
            v-else-if="
                (flow.step.value === 'tracking' ||
                    flow.step.value === 'succeeded' ||
                    flow.step.value === 'failed') &&
                flow.context.direction &&
                flow.context.bridgeRequestId
            "
            :direction="flow.context.direction"
            :token="flow.context.token"
            :bridge-request-id="flow.context.bridgeRequestId"
            :source-tx-hash="flow.context.sourceTxHash"
            :destination-address="flow.context.destinationAddress"
            @succeeded="flow.markSucceeded"
            @failed="flow.markFailed"
            @reset="handleReset"
        />

        <div
            v-else-if="flow.step.value === 'failed'"
            class="flex flex-col items-center gap-3 py-8 text-center"
        >
            <p
                class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-600 dark:text-red-400"
            >
                {{ flow.context.error || 'Bridge failed' }}
            </p>
            <button
                type="button"
                class="rounded-lg border border-[#19140035] px-4 py-2 text-sm text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                @click="handleReset"
            >
                Try again
            </button>
        </div>
    </div>
</template>
