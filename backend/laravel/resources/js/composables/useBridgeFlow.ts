import { computed, reactive, ref } from 'vue';

import type { BridgeDirection } from '@/lib/addressValidation';
import type { BridgeTokenSymbol } from '@/lib/bridgeTokens';

export type BridgeStep =
    | 'idle'
    | 'configuring'
    | 'reviewing'
    | 'signing'
    | 'submitting'
    | 'tracking'
    | 'succeeded'
    | 'failed';

export type RecentDestination = {
    address: string;
    direction: BridgeDirection;
    token: BridgeTokenSymbol;
    amount: string;
    timestamp: number;
};

type FlowContext = {
    direction: BridgeDirection | null;
    token: BridgeTokenSymbol;
    sourceAddress: string;
    destinationAddress: string;
    amount: string;
    sourceTxHash: string;
    sourceNonce: number;
    bridgeRequestId: number | null;
    destinationTxHash: string | null;
    error: string | null;
    confirmed: boolean;
};

const RECENT_KEY = 'bridgeRecentDestinations';
const RECENT_LIMIT = 5;

const loadRecent = (): RecentDestination[] => {
    if (typeof window === 'undefined') {
        return [];
    }

    try {
        const raw = window.localStorage.getItem(RECENT_KEY);

        if (!raw) {
            return [];
        }

        const parsed = JSON.parse(raw) as RecentDestination[];

        return Array.isArray(parsed) ? parsed.slice(0, RECENT_LIMIT) : [];
    } catch {
        return [];
    }
};

const saveRecent = (entry: RecentDestination): RecentDestination[] => {
    const current = loadRecent().filter(
        (e) =>
            !(e.address === entry.address && e.direction === entry.direction),
    );
    const next = [entry, ...current].slice(0, RECENT_LIMIT);

    try {
        window.localStorage.setItem(RECENT_KEY, JSON.stringify(next));
    } catch {
        // ignore quota errors
    }

    return next;
};

export const useBridgeFlow = () => {
    const step = ref<BridgeStep>('idle');

    const context = reactive<FlowContext>({
        direction: null,
        token: 'CYBER.sol',
        sourceAddress: '',
        destinationAddress: '',
        amount: '',
        sourceTxHash: '',
        sourceNonce: 0,
        bridgeRequestId: null,
        destinationTxHash: null,
        error: null,
        confirmed: false,
    });

    const recent = ref<RecentDestination[]>(loadRecent());

    const reset = () => {
        step.value = 'idle';
        context.direction = null;
        context.token = 'CYBER.sol';
        context.sourceAddress = '';
        context.destinationAddress = '';
        context.amount = '';
        context.sourceTxHash = '';
        context.sourceNonce = 0;
        context.bridgeRequestId = null;
        context.destinationTxHash = null;
        context.error = null;
        context.confirmed = false;
    };

    const chooseDirection = (direction: BridgeDirection) => {
        context.direction = direction;
        step.value = 'configuring';
    };

    const proceedToReview = () => {
        context.error = null;
        context.confirmed = false;
        step.value = 'reviewing';
    };

    const backToConfigure = () => {
        step.value = 'configuring';
    };

    const beginSigning = () => {
        context.error = null;
        step.value = 'signing';
    };

    const beginSubmitting = () => {
        step.value = 'submitting';
    };

    const beginTracking = (bridgeRequestId: number) => {
        context.bridgeRequestId = bridgeRequestId;
        step.value = 'tracking';
    };

    const markSucceeded = (destinationTxHash: string | null) => {
        context.destinationTxHash = destinationTxHash;
        step.value = 'succeeded';
        rememberDestination();
    };

    const markFailed = (error: string) => {
        context.error = error;
        step.value = 'failed';
    };

    const rememberDestination = () => {
        if (!context.direction || !context.destinationAddress) {
            return;
        }

        recent.value = saveRecent({
            address: context.destinationAddress,
            direction: context.direction,
            token: context.token,
            amount: context.amount,
            timestamp: Date.now(),
        });
    };

    const stepIndex = computed(() => {
        switch (step.value) {
            case 'idle':
                return 0;
            case 'configuring':
                return 1;
            case 'reviewing':
                return 2;
            case 'signing':
            case 'submitting':
                return 3;
            case 'tracking':
            case 'succeeded':
            case 'failed':
                return 4;
            default:
                return 0;
        }
    });

    const recentForDirection = computed(() =>
        context.direction
            ? recent.value.filter((r) => r.direction === context.direction)
            : [],
    );

    return {
        step,
        context,
        stepIndex,
        recent,
        recentForDirection,
        reset,
        chooseDirection,
        proceedToReview,
        backToConfigure,
        beginSigning,
        beginSubmitting,
        beginTracking,
        markSucceeded,
        markFailed,
    };
};
