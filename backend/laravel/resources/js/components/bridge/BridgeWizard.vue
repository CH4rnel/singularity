<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';

import { useBridge } from '@/composables/useBridge';
import { useBridgeAnalytics } from '@/composables/useBridgeAnalytics';
import { useBridgeFlow } from '@/composables/useBridgeFlow';
import { useSolanaWallet } from '@/composables/useSolanaWallet';
import { useWallet } from '@/composables/useWallet';
import { bridgeRoute, isManualBridgeRoute } from '@/lib/addressValidation';
import type { BridgeDirection } from '@/lib/addressValidation';
import {
    bridgeChainInfo,
    bridgeDepositAddress,
    tokenOnChain,
    tokensForRoute,
} from '@/lib/bridgeConfig';
import type { BridgeFeeConfig } from '@/lib/bridgeFee';
import { BRIDGE_TOKENS } from '@/lib/bridgeTokens';
import type { BridgeTokenSymbol } from '@/lib/bridgeTokens';
import StepConfigure from './StepConfigure.vue';
import StepDirection from './StepDirection.vue';
import StepReview from './StepReview.vue';
import StepSigning from './StepSigning.vue';
import StepTracking from './StepTracking.vue';

const props = withDefaults(
    defineProps<{
        relayerEvmAddress?: string | null;
        availableDirections?: string[];
        yentenDepositAddress?: string | null;
        cyberSolUsd?: number | null;
        feeConfig?: BridgeFeeConfig;
        gasDropConfig?: { enabled: boolean; amount: string };
        convertConfig?: { enabled: boolean; rate: number };
    }>(),
    {
        relayerEvmAddress: null,
        availableDirections: () => [],
        yentenDepositAddress: null,
        cyberSolUsd: null,
        feeConfig: () => ({ flatUsd: 0.1, rateBps: 0 }),
        gasDropConfig: () => ({ enabled: true, amount: '0.01' }),
        convertConfig: () => ({ enabled: true, rate: 1000 }),
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

const sourceWalletConnected = computed(() => {
    if (!flow.context.direction) {
        return false;
    }

    const sourceWallet = bridgeRoute(flow.context.direction).sourceWallet;

    if (sourceWallet === 'manual') {
        return true;
    }

    return sourceWallet === 'solana'
        ? solanaWallet.isConnected.value
        : evmWallet.isConnected.value;
});

const sourceWalletAddress = computed(() => {
    if (!flow.context.direction) {
        return null;
    }

    return bridgeRoute(flow.context.direction).sourceWallet === 'solana'
        ? solanaWallet.address.value
        : evmWallet.address.value;
});

const sourceWalletConnecting = computed(() =>
    flow.context.direction &&
    bridgeRoute(flow.context.direction).sourceWallet === 'solana'
        ? solanaWallet.isConnecting.value
        : evmWallet.isConnecting.value,
);

const sourceBalance = computed(() => {
    if (!flow.context.direction) {
        return null;
    }

    const source = bridgeRoute(flow.context.direction).source;
    const sourceToken = tokenOnChain(flow.context.token, source);

    if (bridgeChainInfo(source)?.type === 'evm' && sourceToken?.native) {
        return bridge.getEvmNativeBalance(source);
    }

    if (source !== 'solana' && source !== 'cyberia') {
        // Manual-chain balances (TON, Yenten) aren't available through the
        // connected EVM/Solana wallets.
        return null;
    }

    const chain: 'evm' | 'solana' = source === 'solana' ? 'solana' : 'evm';

    if (flow.context.token === 'CYBER.sol') {
        return chain === 'solana'
            ? bridge.solanaCyberBalance.value
            : bridge.cyberSolBalance.value;
    }

    return bridge.getTokenBalance(flow.context.token, chain);
});

const sourceMaxAmount = computed(() => {
    if (!flow.context.direction) {
        return null;
    }

    const source = bridgeRoute(flow.context.direction).source;
    const sourceToken = tokenOnChain(flow.context.token, source);

    return bridgeChainInfo(source)?.type === 'evm' && sourceToken?.native
        ? bridge.getEvmNativeMaxAmount(source)
        : sourceBalance.value;
});

const refreshSourceBalance = () => {
    if (!flow.context.direction) {
        return;
    }

    const token = flow.context.token;

    const source = bridgeRoute(flow.context.direction).source;
    const sourceToken = tokenOnChain(token, source);

    if (
        bridgeChainInfo(source)?.type === 'evm' &&
        sourceToken?.native &&
        evmWallet.address.value
    ) {
        bridge.fetchEvmNativeBalance(source, evmWallet.address.value);
    } else if (source === 'solana' && solanaWallet.address.value) {
        if (token === 'CYBER.sol') {
            bridge.fetchSolanaCyberBalance(solanaWallet.address.value);
        } else {
            bridge.fetchTokenBalanceSolana(token, solanaWallet.address.value);
        }
    } else if (source === 'cyberia' && evmWallet.address.value) {
        if (token === 'CYBER.sol') {
            bridge.fetchCyberSolBalance(evmWallet.address.value);
        } else {
            bridge.fetchTokenBalanceEvm(token, evmWallet.address.value, source);
        }
    }
};

watch(sourceWalletConnected, (connected) => {
    if (!connected || !flow.context.direction) {
        return;
    }

    const sourceWallet = bridgeRoute(flow.context.direction).sourceWallet;

    if (sourceWallet === 'solana' && solanaWallet.address.value) {
        flow.context.sourceAddress = solanaWallet.address.value;
    } else if (sourceWallet === 'evm' && evmWallet.address.value) {
        flow.context.sourceAddress = evmWallet.address.value;
    }

    refreshSourceBalance();
});

watch(
    () => flow.context.token,
    (token) => {
        // Conversion only exists for CYBER.sol — drop the flag on token switch.
        if (token !== 'CYBER.sol') {
            flow.context.convertToNative = false;
        }

        refreshSourceBalance();
    },
);

const sourceDepositAddress = computed(() => {
    if (
        !flow.context.direction ||
        !isManualBridgeRoute(flow.context.direction)
    ) {
        return null;
    }

    const source = bridgeRoute(flow.context.direction).source;

    return (
        bridgeDepositAddress(source) ??
        (source === 'yenten' ? props.yentenDepositAddress : null)
    );
});

const handleDirection = (direction: BridgeDirection) => {
    flow.chooseDirection(direction);
    analytics.track('direction_selected', { direction });

    const available = tokensForRoute(direction);

    if (available.length > 0 && !available.includes(flow.context.token)) {
        flow.context.token = available[0] as BridgeTokenSymbol;
    }

    const sourceWallet = bridgeRoute(direction).sourceWallet;

    if (sourceWallet === 'solana' && solanaWallet.address.value) {
        flow.context.sourceAddress = solanaWallet.address.value;
    } else if (sourceWallet === 'evm' && evmWallet.address.value) {
        flow.context.sourceAddress = evmWallet.address.value;
    }

    refreshSourceBalance();
};

const handleConnectSource = async () => {
    if (!flow.context.direction) {
        return;
    }

    if (bridgeRoute(flow.context.direction).sourceWallet === 'solana') {
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
        bridgeRoute(flow.context.direction!).destination === 'cyberia'
            ? await checkEvmRecipientNeedsGas(flow.context.destinationAddress)
            : false;

    flow.proceedToReview();
};

const handleConfirm = async () => {
    if (!flow.context.direction) {
        return;
    }

    const manualSource = isManualBridgeRoute(flow.context.direction);

    if (!manualSource) {
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
            } else if (
                bridgeChainInfo(bridgeRoute(flow.context.direction).source)
                    ?.type === 'evm'
            ) {
                const sourceChain = bridgeRoute(flow.context.direction).source;
                const relayer =
                    bridgeDepositAddress(sourceChain) ??
                    props.relayerEvmAddress;

                if (!relayer) {
                    throw new Error(
                        'Bridge relayer address not configured on the server. Run `php artisan bridge:show-relayer` and add the printed BRIDGE_RELAYER_ADDRESS to .env.',
                    );
                }

                const sourceToken = tokenOnChain(
                    flow.context.token,
                    sourceChain,
                );

                result = sourceToken?.native
                    ? await bridge.nativeTransferToRelayer(
                          sourceChain,
                          flow.context.amount,
                          relayer,
                      )
                    : await bridge.erc20TransferToRelayer(
                          flow.context.token,
                          flow.context.amount,
                          relayer,
                          sourceChain,
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
            const message =
                err instanceof Error ? err.message : 'Signing failed';

            analytics.track('lock_tx_rejected', {
                direction: flow.context.direction,
                amount: flow.context.amount,
                error_message: message,
            });
            flow.markFailed(message);

            return;
        }
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
                convert_to_native: flow.context.convertToNative,
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

// --- Yenten one-time-address flow (prepare → claim) ---------------------
const preparing = ref(false);

const postJson = async (url: string, body: Record<string, unknown>) => {
    const csrfToken = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1];
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    return { response, data: await response.json() };
};

const handlePrepare = async () => {
    if (!flow.context.direction || preparing.value) {
        return;
    }

    preparing.value = true;

    try {
        const { response, data } = await postJson('/bridge/prepare', {
            direction: flow.context.direction,
            token: flow.context.token,
            recipient_address: flow.context.destinationAddress,
            session_id: analytics.sessionId,
        });

        if (!response.ok) {
            flow.markFailed(
                data.message ?? `Could not reserve a deposit address (HTTP ${response.status})`,
            );

            return;
        }

        const br = data.bridge_request;
        flow.context.bridgeRequestId = br.id;
        flow.context.depositAddress = br.deposit_address;
        flow.context.depositExpiresAt = br.expires_at ?? null;
    } catch (err) {
        flow.markFailed(err instanceof Error ? err.message : 'Prepare failed');
    } finally {
        preparing.value = false;
    }
};

const handleClaim = async () => {
    if (!flow.context.bridgeRequestId) {
        return;
    }

    claimError.value = null;

    try {
        const { response, data } = await postJson('/bridge/claim', {
            id: flow.context.bridgeRequestId,
            session_id: analytics.sessionId,
        });

        if (!response.ok) {
            // Retryable (no deposit yet / unconfirmed): keep the deposit panel.
            claimError.value =
                data.message ?? `Check failed (HTTP ${response.status})`;

            // The deposit window closed on an empty address — release the
            // dead address so the user can start a fresh transfer.
            if (data.expired) {
                flow.context.bridgeRequestId = null;
                flow.context.depositAddress = null;
                flow.context.depositExpiresAt = null;
            }

            return;
        }

        const br = data.bridge_request;
        flow.beginTracking(br.id);
        analytics.track('tracking_started', {
            direction: flow.context.direction!,
            bridge_request_id: br.id,
        });

        if (br.status === 'completed') {
            flow.markSucceeded(br.destination_tx_hash ?? null);
        } else if (br.status === 'failed') {
            flow.markFailed(br.error_message ?? 'Bridge failed');
        }
    } catch (err) {
        claimError.value = err instanceof Error ? err.message : 'Claim failed';
    }
};

const claimError = ref<string | null>(null);

const handleReset = () => {
    flow.reset();
    claimError.value = null;
};
</script>

<template>
    <div class="w-full">
        <StepDirection
            v-if="flow.step.value === 'idle'"
            :available-directions="props.availableDirections"
            @select="handleDirection"
        />

        <StepConfigure
            v-else-if="
                flow.step.value === 'configuring' && flow.context.direction
            "
            :direction="flow.context.direction"
            v-model:token="flow.context.token"
            v-model:amount="flow.context.amount"
            v-model:source-tx-hash="flow.context.sourceTxHash"
            v-model:source-address="flow.context.sourceAddress"
            v-model:destination-address="flow.context.destinationAddress"
            v-model:convert-to-native="flow.context.convertToNative"
            :convert-enabled="props.convertConfig.enabled"
            :convert-rate="props.convertConfig.rate"
            :source-wallet-connected="sourceWalletConnected"
            :source-wallet-address="sourceWalletAddress"
            :source-wallet-connecting="sourceWalletConnecting"
            :source-balance="sourceBalance"
            :source-max-amount="sourceMaxAmount"
            :source-deposit-address="sourceDepositAddress"
            :prepared-deposit-address="flow.context.depositAddress"
            :deposit-expires-at="flow.context.depositExpiresAt"
            :preparing="preparing"
            :recent="flow.recentForDirection.value"
            @connect-source="handleConnectSource"
            @prepare="handlePrepare"
            @claim="handleClaim"
            @next="handleConfigureNext"
            @back="handleReset"
        />

        <p
            v-if="claimError && flow.step.value === 'configuring'"
            class="mt-3 rounded-lg border border-yellow-500/30 bg-yellow-500/10 px-4 py-3 text-xs text-yellow-700 dark:text-yellow-400"
        >
            {{ claimError }}
        </p>

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
            :convert-to-native="flow.context.convertToNative"
            :convert-rate="props.convertConfig.rate"
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
