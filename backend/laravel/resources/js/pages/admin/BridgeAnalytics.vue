<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type FunnelStep = {
    event_type: string;
    sessions: number;
    total: number;
};

type DailyRow = {
    day: string;
    event_type: string;
    total: number;
};

type ErrorRow = {
    event_type: string;
    error_message: string;
    total: number;
};

type HistogramBucket = {
    label: string;
    count: number;
};

type Totals = {
    requests: number;
    completed: number;
    failed: number;
    sessions: number;
};

const props = defineProps<{
    days: number;
    totals: Totals;
    funnel: FunnelStep[];
    dailyVolume: DailyRow[];
    topErrors: ErrorRow[];
    completionHistogram: HistogramBucket[];
    eventTypes: string[];
}>();

const daysInput = ref(props.days);

const reload = () => {
    router.get(
        '/admin/bridge-analytics',
        { days: daysInput.value },
        { preserveScroll: true },
    );
};

const maxFunnel = computed(() =>
    Math.max(1, ...props.funnel.map((s) => s.sessions)),
);

const maxHistogram = computed(() =>
    Math.max(1, ...props.completionHistogram.map((b) => b.count)),
);

const maxError = computed(() =>
    Math.max(1, ...props.topErrors.map((e) => e.total)),
);

const dailyChart = computed(() => {
    const byDay = new Map<string, Map<string, number>>();
    const types = new Set<string>();

    for (const row of props.dailyVolume) {
        if (!byDay.has(row.day)) {
            byDay.set(row.day, new Map());
        }

        byDay.get(row.day)!.set(row.event_type, row.total);
        types.add(row.event_type);
    }

    const days = Array.from(byDay.keys()).sort();
    const trackedTypes = [
        'page_view',
        'bridge_submitted',
        'relayer_succeeded',
    ].filter((t) => types.has(t));

    return { days, trackedTypes, byDay };
});

const dropoff = (
    step: FunnelStep,
    index: number,
): { rate: number; lost: number } | null => {
    if (index === 0) {
        return null;
    }

    const prev = props.funnel[index - 1];

    if (prev.sessions === 0) {
        return null;
    }

    const lost = prev.sessions - step.sessions;
    const rate = (lost / prev.sessions) * 100;

    return { rate, lost };
};
</script>

<template>
    <Head title="Bridge Analytics" />

    <div class="mx-auto max-w-5xl space-y-8 p-6">
        <header class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Bridge analytics</h1>
            <div class="flex items-center gap-2">
                <label for="days" class="text-sm text-gray-500">Days</label>
                <input
                    id="days"
                    v-model.number="daysInput"
                    type="number"
                    min="1"
                    max="365"
                    class="w-20 rounded border border-gray-300 px-2 py-1 text-sm dark:border-gray-700 dark:bg-gray-900"
                    @keyup.enter="reload"
                />
                <button
                    class="rounded bg-gray-900 px-3 py-1 text-sm text-white dark:bg-gray-100 dark:text-gray-900"
                    @click="reload"
                >
                    Apply
                </button>
            </div>
        </header>

        <section class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div
                class="rounded border border-gray-200 p-4 dark:border-gray-800"
            >
                <p class="text-xs text-gray-500">Sessions</p>
                <p class="font-mono text-2xl">{{ totals.sessions }}</p>
            </div>
            <div
                class="rounded border border-gray-200 p-4 dark:border-gray-800"
            >
                <p class="text-xs text-gray-500">Requests</p>
                <p class="font-mono text-2xl">{{ totals.requests }}</p>
            </div>
            <div
                class="rounded border border-gray-200 p-4 dark:border-gray-800"
            >
                <p class="text-xs text-gray-500">Completed</p>
                <p class="font-mono text-2xl text-green-600">
                    {{ totals.completed }}
                </p>
            </div>
            <div
                class="rounded border border-gray-200 p-4 dark:border-gray-800"
            >
                <p class="text-xs text-gray-500">Failed</p>
                <p class="font-mono text-2xl text-red-600">
                    {{ totals.failed }}
                </p>
            </div>
        </section>

        <section>
            <h2 class="mb-3 text-lg font-semibold">Funnel</h2>
            <div class="space-y-1.5">
                <div
                    v-for="(step, idx) in funnel"
                    :key="step.event_type"
                    class="grid grid-cols-[12rem_1fr_5rem_6rem] items-center gap-3 text-sm"
                >
                    <span
                        class="font-mono text-xs text-gray-600 dark:text-gray-400"
                    >
                        {{ step.event_type }}
                    </span>
                    <div
                        class="relative h-6 rounded bg-gray-100 dark:bg-gray-800"
                    >
                        <div
                            class="absolute inset-y-0 left-0 rounded bg-blue-500/70"
                            :style="{
                                width: (step.sessions / maxFunnel) * 100 + '%',
                            }"
                        />
                    </div>
                    <span class="text-right font-mono">{{
                        step.sessions
                    }}</span>
                    <span
                        v-if="dropoff(step, idx)"
                        class="font-mono text-xs text-red-500"
                    >
                        -{{ dropoff(step, idx)!.lost }} ({{
                            dropoff(step, idx)!.rate.toFixed(0)
                        }}%)
                    </span>
                    <span v-else class="text-xs text-gray-400">—</span>
                </div>
            </div>
        </section>

        <section v-if="dailyChart.days.length > 0">
            <h2 class="mb-3 text-lg font-semibold">Daily volume</h2>
            <div
                class="overflow-x-auto rounded border border-gray-200 dark:border-gray-800"
            >
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-3 py-2 text-left">Day</th>
                            <th
                                v-for="t in dailyChart.trackedTypes"
                                :key="t"
                                class="px-3 py-2 text-right font-mono text-xs"
                            >
                                {{ t }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="day in dailyChart.days"
                            :key="day"
                            class="border-t border-gray-100 dark:border-gray-800"
                        >
                            <td class="px-3 py-2 font-mono">{{ day }}</td>
                            <td
                                v-for="t in dailyChart.trackedTypes"
                                :key="t"
                                class="px-3 py-2 text-right font-mono"
                            >
                                {{ dailyChart.byDay.get(day)?.get(t) ?? 0 }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="completionHistogram.length > 0">
            <h2 class="mb-3 text-lg font-semibold">Time to complete</h2>
            <div class="space-y-1.5">
                <div
                    v-for="bucket in completionHistogram"
                    :key="bucket.label"
                    class="grid grid-cols-[6rem_1fr_4rem] items-center gap-3 text-sm"
                >
                    <span class="font-mono text-xs">{{ bucket.label }}</span>
                    <div
                        class="relative h-5 rounded bg-gray-100 dark:bg-gray-800"
                    >
                        <div
                            class="absolute inset-y-0 left-0 rounded bg-green-500/70"
                            :style="{
                                width:
                                    (bucket.count / maxHistogram) * 100 + '%',
                            }"
                        />
                    </div>
                    <span class="text-right font-mono">{{ bucket.count }}</span>
                </div>
            </div>
        </section>

        <section v-if="topErrors.length > 0">
            <h2 class="mb-3 text-lg font-semibold">Top errors</h2>
            <div class="space-y-1.5">
                <div
                    v-for="err in topErrors"
                    :key="err.event_type + err.error_message"
                    class="grid grid-cols-[10rem_1fr_4rem] items-center gap-3 text-sm"
                >
                    <span class="font-mono text-xs text-gray-500">
                        {{ err.event_type }}
                    </span>
                    <div class="flex items-center gap-2">
                        <div
                            class="relative h-5 flex-1 rounded bg-gray-100 dark:bg-gray-800"
                        >
                            <div
                                class="absolute inset-y-0 left-0 rounded bg-red-500/70"
                                :style="{
                                    width: (err.total / maxError) * 100 + '%',
                                }"
                            />
                        </div>
                        <span
                            class="truncate font-mono text-xs"
                            :title="err.error_message"
                        >
                            {{ err.error_message }}
                        </span>
                    </div>
                    <span class="text-right font-mono">{{ err.total }}</span>
                </div>
            </div>
        </section>

        <section
            v-else
            class="rounded border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-700"
        >
            No errors recorded in the last {{ days }} days.
        </section>
    </div>
</template>
