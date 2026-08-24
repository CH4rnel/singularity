<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
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
    options: {
        priorities: string[];
        assignees: { id: number; name: string }[];
    };
}>();

const { t, tag } = useLocale(consoleMessages);

const compose = useForm({ title: '' });
const editingTaskId = ref<number | null>(null);
const edit = useForm({
    title: '',
    description: '',
    priority: 'normal',
    due_at: '',
    assigned_to_user_id: null as number | null,
});

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

function startEditing(task: Task) {
    editingTaskId.value = task.id;
    edit.clearErrors();
    edit.defaults({
        title: task.title,
        description: task.description ?? '',
        priority: task.priority,
        due_at: task.due_at ? task.due_at.slice(0, 10) : '',
        assigned_to_user_id: task.assignee_id,
    });
    edit.reset();
    void nextTick(() => {
        document.getElementById(`task-${task.id}`)?.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });
    });
}

function cancelEditing() {
    editingTaskId.value = null;
    edit.clearErrors();
}

function save(task: Task) {
    if (!edit.title.trim() || edit.processing) {
        return;
    }

    edit.put(tasks.update.url(task.id), {
        preserveScroll: true,
        onSuccess: cancelEditing,
    });
}

function remove(task: Pick<Task, 'id' | 'title'>) {
    if (!window.confirm(t('tasks.deleteConfirm', { title: task.title }))) {
        return;
    }

    router.delete(tasks.destroy.url(task.id), { preserveScroll: true });
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

    <div class="tasks-page">
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
                    class="task-unowned-card"
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
                    <div class="task-card__meta">
                        <span class="mk-m mk-t3" style="font-size: 11px">{{
                            due(task)
                        }}</span>
                        <span class="mk-k">{{
                            t(`priority.${task.priority}`)
                        }}</span>
                        <button
                            type="button"
                            class="mk-btn mk-act"
                            style="
                                margin-left: auto;
                                height: 26px;
                                padding: 0 10px;
                            "
                            @click="claim(task)"
                        >
                            {{ t('tasks.claim') }}
                        </button>
                        <button
                            type="button"
                            class="mk-btn mk-ghost"
                            @click="startEditing(task)"
                        >
                            {{ t('tasks.edit') }}
                        </button>
                        <button
                            type="button"
                            class="mk-btn mk-ghost task-delete"
                            @click="remove(task)"
                        >
                            {{ t('tasks.delete') }}
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

                <div class="task-list">
                    <div
                        v-for="task in tasksOf(column.key)"
                        :id="`task-${task.id}`"
                        :key="task.id"
                        class="mk-panel task-card"
                    >
                        <span
                            style="width: 2px; flex: 0 0 2px"
                            :style="{ background: bar(task) }"
                        />
                        <div style="min-width: 0; flex: 1">
                            <form
                                v-if="editingTaskId === task.id"
                                class="task-edit"
                                @submit.prevent="save(task)"
                            >
                                <input
                                    v-model="edit.title"
                                    class="task-edit__control task-edit__title"
                                    :aria-label="t('tasks.field.title')"
                                />
                                <textarea
                                    v-model="edit.description"
                                    class="task-edit__control task-edit__description"
                                    :placeholder="t('tasks.field.description')"
                                    rows="3"
                                />
                                <div class="task-edit__fields">
                                    <select
                                        v-model="edit.priority"
                                        class="task-edit__control"
                                        :aria-label="t('tasks.field.priority')"
                                    >
                                        <option
                                            v-for="priority in options.priorities"
                                            :key="priority"
                                            :value="priority"
                                        >
                                            {{ t(`priority.${priority}`) }}
                                        </option>
                                    </select>
                                    <input
                                        v-model="edit.due_at"
                                        class="task-edit__control"
                                        type="date"
                                        :aria-label="t('tasks.field.due')"
                                    />
                                    <select
                                        v-model="edit.assigned_to_user_id"
                                        class="task-edit__control"
                                        :aria-label="t('tasks.field.assignee')"
                                    >
                                        <option :value="null">
                                            {{ t('tasks.nobody') }}
                                        </option>
                                        <option
                                            v-for="assignee in options.assignees"
                                            :key="assignee.id"
                                            :value="assignee.id"
                                        >
                                            {{ assignee.name }}
                                        </option>
                                    </select>
                                </div>
                                <p
                                    v-if="edit.errors.title"
                                    class="task-compose__error"
                                >
                                    {{ edit.errors.title }}
                                </p>
                                <div class="task-card__actions">
                                    <button
                                        type="submit"
                                        class="mk-btn mk-act"
                                        :disabled="
                                            edit.processing ||
                                            !edit.title.trim()
                                        "
                                    >
                                        {{ t('tasks.save') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="mk-btn mk-ghost"
                                        @click="cancelEditing"
                                    >
                                        {{ t('tasks.cancel') }}
                                    </button>
                                </div>
                            </form>
                            <template v-else>
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
                                <div class="task-card__meta">
                                    <span
                                        class="mk-m"
                                        style="font-size: 11px"
                                        :style="{
                                            color: task.assignee
                                                ? 'var(--mk-dim)'
                                                : 'var(--mk-warning)',
                                        }"
                                        >{{
                                            task.assignee ?? t('tasks.nobody')
                                        }}</span
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
                                    <button
                                        type="button"
                                        class="mk-btn mk-ghost"
                                        @click="startEditing(task)"
                                    >
                                        {{ t('tasks.edit') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="mk-btn mk-ghost task-delete"
                                        @click="remove(task)"
                                    >
                                        {{ t('tasks.delete') }}
                                    </button>
                                </div>
                            </template>
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

        <section class="task-completed">
            <div class="task-completed__heading">
                <span class="mk-k">{{ t('tasks.journal.title') }}</span>
                <span class="mk-m mk-t3">{{ num(closed.length) }}</span>
            </div>
            <div v-if="closed.length" class="task-completed__grid">
                <article
                    v-for="task in closed"
                    :key="task.id"
                    class="mk-panel task-completed__item"
                >
                    <p>{{ task.title }}</p>
                    <p v-if="task.description" class="mk-t3">
                        {{ task.description }}
                    </p>
                    <div class="task-card__meta mk-m mk-t3">
                        <time :datetime="task.completed_at ?? undefined">{{
                            completedAt(task)
                        }}</time>
                        <span v-if="task.assignee">{{ task.assignee }}</span>
                        <span v-if="task.contact">{{ task.contact.name }}</span>
                        <button
                            type="button"
                            class="mk-btn mk-ghost"
                            @click="reopen(task)"
                        >
                            {{ t('tasks.reopen') }}
                        </button>
                        <button
                            type="button"
                            class="mk-btn mk-ghost task-delete"
                            @click="remove(task)"
                        >
                            {{ t('tasks.delete') }}
                        </button>
                    </div>
                </article>
            </div>
            <p v-else class="mk-t3 task-journal__empty">
                {{ t('tasks.journal.empty') }}
            </p>
        </section>

        <div style="display: flex; align-items: center; gap: 12px">
            <span class="mk-m mk-t3" style="font-size: 11px">{{ footer }}</span>
        </div>
    </div>
</template>

<style scoped>
.tasks-page {
    display: flex;
    min-width: 0;
    flex: 1;
    flex-direction: column;
    gap: 20px;
}

.task-heading {
    position: relative;
    display: flex;
    align-items: baseline;
    gap: 12px;
}

.task-unowned-grid {
    grid-template-columns: repeat(auto-fill, minmax(min(280px, 100%), 1fr));
}

.task-columns {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.task-completed__heading {
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid rgba(232, 236, 236, 0.09);
}

.task-completed__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(300px, 100%), 1fr));
    gap: 10px;
    margin-top: 12px;
}

.task-completed__item {
    padding: 12px 14px;
}

.task-completed__item p {
    margin: 0;
    font-size: 12.5px;
    line-height: 1.45;
    overflow-wrap: anywhere;
}

.task-completed__item p + p {
    margin-top: 4px;
    font-size: 11.5px;
}

.task-card__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 5px 10px;
    margin-top: 8px;
    font-size: 10.5px;
}

.task-journal__empty {
    margin: 0;
    padding: 20px 14px;
    font-size: 12px;
}

.task-unowned-card {
    min-width: 0;
    padding: 12px 14px;
    border: 1px dashed rgba(232, 236, 236, 0.16);
    overflow-wrap: anywhere;
}

.task-list {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 10px;
    margin-top: 12px;
}

.task-card {
    display: flex;
    min-width: 0;
    gap: 12px;
    padding: 12px 14px 12px 0;
    overflow-wrap: anywhere;
}

.task-card__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.task-delete {
    color: var(--mk-critical);
}

.task-edit {
    display: grid;
    gap: 9px;
}

.task-edit__control {
    min-width: 0;
    border: 1px solid rgba(232, 236, 236, 0.14);
    border-radius: 0;
    padding: 7px 9px;
    background: rgba(232, 236, 236, 0.03);
    color: var(--mk-body);
    font: inherit;
}

.task-edit__title {
    font-size: 13px;
    font-weight: 500;
}

.task-edit__description {
    resize: vertical;
}

.task-edit__fields {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
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

@media (max-width: 620px) {
    .task-edit__fields {
        grid-template-columns: 1fr;
    }

    .task-card__meta > button:first-of-type {
        margin-left: 0 !important;
    }
}
</style>
