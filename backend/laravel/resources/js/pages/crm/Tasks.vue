<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Rule from '@/components/console/Rule.vue';
import { useLocale } from '@/composables/useLocale';
import { num, shortDate } from '@/lib/console';
import { consoleMessages } from '@/lib/consoleMessages';
import tasks from '@/routes/crm/tasks';

/**
 * "Задачи" — three columns and one line to type into.
 *
 * A task list has exactly three states worth separating (late, now, later) and
 * one thing that is not a state at all: work nobody owns. Unowned work never
 * gets picked up by itself, so it stands above the columns in its own band
 * with a single button, instead of hiding inside them behind an empty column.
 *
 * The composer is one line because four fields are four decisions before the
 * thought is written down, and the thought is the part that gets lost.
 */
type Task = {
    id: number;
    title: string;
    description: string | null;
    priority: string;
    due_at: string | null;
    overdue: boolean;
    overdue_days: number | null;
    assignee: string | null;
    assignee_id: number | null;
    contact: { id: number; name: string } | null;
};

type ClosedTask = Pick<
    Task,
    'id' | 'title' | 'description' | 'assignee' | 'contact'
> & {
    completed_at: string | null;
};

const props = defineProps<{
    columns: { overdue: Task[]; soon: Task[]; later: Task[] };
    closed: ClosedTask[];
    unowned: Task[];
    stats: {
        open: number;
        overdue: number;
        unowned: number;
        closed_7d: number;
        median_days: number | null;
    };
}>();

const { t, tag } = useLocale(consoleMessages);

const compose = useForm({ title: '' });

const COLUMNS = [
    { key: 'overdue', tone: 'critical' },
    { key: 'soon', tone: 'warning' },
    { key: 'later', tone: 'plain' },
] as const;

function tasksOf(key: 'overdue' | 'soon' | 'later'): Task[] {
    return props.columns[key] ?? [];
}

function submit() {
    if (!compose.title.trim() || compose.processing) {
        return;
    }

    compose.post(tasks.store.url(), {
        preserveScroll: true,
        onSuccess: () => compose.reset(),
    });
}

function claim(task: Task) {
    router.post(tasks.claim.url(task.id), {}, { preserveScroll: true });
}

function done(task: Task) {
    router.put(
        tasks.update.url(task.id),
        { status: 'done' },
        { preserveScroll: true },
    );
}

function reopen(task: Pick<Task, 'id'>) {
    router.put(
        tasks.update.url(task.id),
        { status: 'open' },
        { preserveScroll: true },
    );
}

function due(task: Task): string {
    if (!task.due_at) {
        return t('tasks.noDue');
    }

    const date = new Date(task.due_at);
    const today = new Date();
    const sameDay = date.toDateString() === today.toDateString();

    if (sameDay) {
        return t('tasks.today');
    }

    const tomorrow = new Date(today.getTime() + 86_400_000);

    if (date.toDateString() === tomorrow.toDateString()) {
        return t('tasks.tomorrow');
    }

    return shortDate(task.due_at, tag.value);
}

function bar(task: Task): string {
    if (task.overdue) {
        return 'var(--mk-critical)';
    }

    return task.priority === 'high'
        ? 'var(--mk-critical)'
        : task.priority === 'normal'
          ? 'var(--mk-warning)'
          : 'var(--mk-fainter)';
}

function completedAt(task: ClosedTask): string {
    if (!task.completed_at) {
        return '—';
    }

    return new Intl.DateTimeFormat(tag.value, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(task.completed_at));
}

const footer = computed(() =>
    props.stats.closed_7d > 0
        ? t('tasks.footer', {
              closed: props.stats.closed_7d,
              median: props.stats.median_days ?? '—',
          })
        : t('tasks.footerEmpty'),
);
</script>

<template>
    <Head title="Пульт · Задачи" />

    <div class="task-heading">
        <h1 class="mk-h1">{{ t('tasks.title') }}</h1>
        <span class="mk-m mk-t3" style="font-size: 12px">
            {{
                t('tasks.stats', {
                    open: stats.open,
                    overdue: stats.overdue,
                    unowned: stats.unowned,
                })
            }}
        </span>
        <details class="task-journal">
            <summary class="mk-btn mk-ghost task-journal__trigger">
                {{ t('tasks.done') }}
            </summary>
            <div class="task-journal__panel">
                <div class="task-journal__heading">
                    <span class="mk-k">{{ t('tasks.journal.title') }}</span>
                    <span class="mk-m mk-t3">{{ num(closed.length) }}</span>
                </div>
                <div v-if="closed.length" class="task-journal__list">
                    <article
                        v-for="task in closed"
                        :key="task.id"
                        class="task-journal__item"
                    >
                        <p>{{ task.title }}</p>
                        <p v-if="task.description" class="mk-t3">
                            {{ task.description }}
                        </p>
                        <div class="task-journal__meta mk-m mk-t3">
                            <time :datetime="task.completed_at ?? undefined">
                                {{ completedAt(task) }}
                            </time>
                            <span v-if="task.assignee">{{
                                task.assignee
                            }}</span>
                            <span v-if="task.contact">{{
                                task.contact.name
                            }}</span>
                            <button
                                type="button"
                                class="mk-btn mk-ghost task-journal__reopen"
                                @click="reopen(task)"
                            >
                                {{ t('tasks.reopen') }}
                            </button>
                        </div>
                    </article>
                </div>
                <p v-else class="task-journal__empty mk-t3">
                    {{ t('tasks.journal.empty') }}
                </p>
            </div>
        </details>
    </div>

    <!-- One line, parsed as it is typed: @who !when #whom. -->
    <form
        style="
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 0 16px;
            height: 52px;
            border: 1px solid rgba(0, 229, 209, 0.25);
            background: rgba(0, 229, 209, 0.04);
        "
        @submit.prevent="submit"
    >
        <span class="task-compose__mark" aria-hidden="true">
            <svg
                width="17"
                height="17"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
            >
                <path d="M12 5v14M5 12h14" />
            </svg>
        </span>
        <input
            v-model="compose.title"
            class="mk-m"
            style="
                flex: 1;
                background: none;
                border: 0;
                outline: none;
                color: var(--mk-body);
                font-size: 13.5px;
            "
            :placeholder="t('tasks.quickAdd')"
        />
        <span class="mk-t3 mk-wide" style="font-size: 11.5px">{{
            t('tasks.quickAddHint')
        }}</span>
        <span v-if="compose.errors.title" class="task-compose__error">{{
            compose.errors.title
        }}</span>
        <button
            type="submit"
            class="mk-btn mk-act task-compose__submit"
            :disabled="compose.processing || !compose.title.trim()"
        >
            <svg
                width="14"
                height="14"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
                aria-hidden="true"
            >
                <path d="M12 5v14M5 12h14" />
            </svg>
            {{ t('tasks.create') }}
        </button>
    </form>

    <!-- Unowned work: a state, not a line in a list. -->
    <div v-if="unowned.length">
        <Rule :label="t('tasks.unowned')" :note="t('tasks.unownedNote')" />
        <div
            class="task-unowned-grid"
            style="margin-top: 12px; display: grid; gap: 12px"
        >
            <div
                v-for="task in unowned"
                :key="task.id"
                style="
                    border: 1px dashed rgba(232, 236, 236, 0.16);
                    padding: 12px 14px;
                "
            >
                <p
                    style="
                        margin: 0;
                        font-size: 13px;
                        font-weight: 500;
                        line-height: 1.4;
                    "
                >
                    {{ task.title }}
                </p>
                <div
                    style="
                        margin-top: 10px;
                        display: flex;
                        align-items: center;
                        gap: 10px;
                    "
                >
                    <span class="mk-m mk-t3" style="font-size: 11px">{{
                        due(task)
                    }}</span>
                    <span class="mk-k">{{
                        t(`priority.${task.priority}`)
                    }}</span>
                    <button
                        type="button"
                        class="mk-btn mk-act"
                        style="margin-left: auto; height: 26px; padding: 0 10px"
                        @click="claim(task)"
                    >
                        {{ t('tasks.claim') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div
        class="task-columns"
        style="flex: 1; min-height: 0; display: grid; gap: 20px"
    >
        <div
            v-for="column in COLUMNS"
            :key="column.key"
            style="display: flex; flex-direction: column; min-width: 0"
        >
            <div
                style="
                    display: flex;
                    align-items: center;
                    gap: 9px;
                    padding-bottom: 10px;
                    border-bottom: 1px solid rgba(232, 236, 236, 0.09);
                "
            >
                <span
                    class="mk-dot"
                    :style="{
                        background: `var(--mk-${column.tone === 'plain' ? 'faint' : column.tone})`,
                    }"
                />
                <span class="mk-k" style="color: var(--mk-body)">{{
                    t(`tasks.${column.key}`)
                }}</span>
                <span
                    class="mk-m mk-t3"
                    style="margin-left: auto; font-size: 11px"
                    >{{ num(tasksOf(column.key).length) }}</span
                >
            </div>

            <div
                style="
                    margin-top: 12px;
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                "
            >
                <div
                    v-for="task in tasksOf(column.key)"
                    :id="`task-${task.id}`"
                    :key="task.id"
                    class="mk-panel"
                    style="display: flex; gap: 12px; padding: 12px 14px 12px 0"
                >
                    <span
                        style="width: 2px; flex: 0 0 2px"
                        :style="{ background: bar(task) }"
                    />
                    <div style="min-width: 0; flex: 1">
                        <p
                            style="
                                margin: 0;
                                font-size: 13px;
                                font-weight: 500;
                                line-height: 1.4;
                            "
                        >
                            {{ task.title }}
                        </p>
                        <p
                            v-if="task.description"
                            class="mk-t3"
                            style="
                                margin: 5px 0 0;
                                font-size: 11.5px;
                                line-height: 1.45;
                            "
                        >
                            {{ task.description }}
                        </p>
                        <div
                            style="
                                margin-top: 9px;
                                display: flex;
                                align-items: center;
                                gap: 10px;
                            "
                        >
                            <span
                                class="mk-m"
                                style="font-size: 11px"
                                :style="{
                                    color: task.assignee
                                        ? 'var(--mk-dim)'
                                        : 'var(--mk-warning)',
                                }"
                                >{{ task.assignee ?? t('tasks.nobody') }}</span
                            >
                            <span
                                class="mk-m"
                                style="font-size: 11px"
                                :style="{
                                    color: task.overdue
                                        ? 'var(--mk-critical)'
                                        : 'var(--mk-faint)',
                                }"
                            >
                                {{ due(task)
                                }}<template v-if="task.overdue_days">
                                    · {{ task.overdue_days }}</template
                                >
                            </span>
                            <button
                                type="button"
                                class="mk-btn mk-ghost"
                                style="margin-left: auto; height: 22px"
                                @click="done(task)"
                            >
                                {{ t('tasks.done.action') }}
                            </button>
                        </div>
                    </div>
                </div>
                <p
                    v-if="!tasksOf(column.key).length"
                    class="mk-t3"
                    style="font-size: 12px"
                >
                    {{ t('tasks.empty') }}
                </p>
            </div>
        </div>
    </div>

    <div style="display: flex; align-items: center; gap: 12px">
        <span class="mk-m mk-t3" style="font-size: 11px">{{ footer }}</span>
    </div>
</template>

<style scoped>
.task-heading {
    position: relative;
    display: flex;
    align-items: baseline;
    gap: 12px;
}

.task-unowned-grid {
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
}

.task-columns {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.task-journal {
    position: relative;
    margin-left: auto;
}

.task-journal > summary {
    list-style: none;
}

.task-journal > summary::-webkit-details-marker {
    display: none;
}

.task-journal__trigger {
    height: 30px;
    padding: 0 13px;
    color: var(--mk-faint);
    cursor: pointer;
}

.task-journal[open] .task-journal__trigger,
.task-journal__trigger:hover,
.task-journal__trigger:focus-visible {
    color: var(--mk-dim);
}

.task-journal__panel {
    position: absolute;
    z-index: 30;
    top: calc(100% + 8px);
    right: 0;
    width: min(390px, calc(100vw - 40px));
    max-height: min(520px, 70vh);
    overflow: auto;
    border: 1px solid rgba(232, 236, 236, 0.16);
    background: var(--mk-bg);
    box-shadow: 0 18px 50px rgba(0, 0, 0, 0.42);
}

.task-journal__heading {
    position: sticky;
    z-index: 1;
    top: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    border-bottom: 1px solid rgba(232, 236, 236, 0.09);
    background: var(--mk-bg);
}

.task-journal__list {
    display: flex;
    flex-direction: column;
}

.task-journal__item {
    padding: 12px 14px;
    border-bottom: 1px solid rgba(232, 236, 236, 0.07);
}

.task-journal__item:last-child {
    border-bottom: 0;
}

.task-journal__item p {
    margin: 0;
    font-size: 12.5px;
    line-height: 1.45;
}

.task-journal__item p + p {
    margin-top: 4px;
    font-size: 11.5px;
}

.task-journal__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 5px 10px;
    margin-top: 8px;
    font-size: 10.5px;
}

.task-journal__reopen {
    height: 22px;
    margin-left: auto;
}

.task-journal__empty {
    margin: 0;
    padding: 20px 14px;
    font-size: 12px;
}

.task-compose__mark {
    display: flex;
    align-items: center;
    color: var(--mk-accent);
}

.task-compose__submit {
    flex: 0 0 auto;
    height: 30px;
    white-space: nowrap;
}

.task-compose__submit:disabled {
    opacity: 0.35;
    cursor: default;
}

.task-compose__error {
    color: var(--mk-critical);
    font-size: 11px;
}

@media (max-width: 980px) {
    .task-heading {
        flex-wrap: wrap;
    }

    .task-heading .task-journal {
        margin-left: auto;
    }

    .task-columns {
        grid-template-columns: 1fr;
    }

    .task-compose__submit {
        width: 34px;
        padding: 0;
        overflow: hidden;
        color: transparent;
        gap: 0;
    }

    .task-compose__submit svg {
        color: var(--mk-accent);
    }
}
</style>
