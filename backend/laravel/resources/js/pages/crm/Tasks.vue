<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckSquare,
    Languages,
    Plus,
    Trash2,
} from 'lucide-vue-next';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import { crmMessages } from '@/lib/crmMessages';
import type {
    CrmAssignee,
    CrmTask,
    CrmTaskPriority,
    CrmTaskStatus,
    Paginated,
} from '@/types';

type Filters = {
    q: string | null;
    status: string | null;
    assignee: string | null;
    priority: string | null;
    overdue: boolean | null;
};

type Props = {
    tasks: Paginated<CrmTask>;
    filters: Filters;
    stats: {
        open: number;
        in_progress: number;
        overdue: number;
        unassigned: number;
        mine: number;
        done: number;
    };
    options: {
        statuses: CrmTaskStatus[];
        priorities: CrmTaskPriority[];
        assignees: CrmAssignee[];
    };
};

const props = defineProps<Props>();

const { locale, toggleLocale, t } = useLocale(crmMessages);

const page = usePage();
const currentUserId = (page.props.auth?.user?.id as number | undefined) ?? null;

const priorityVariant: Record<CrmTaskPriority, string> = {
    low: 'outline',
    normal: 'secondary',
    high: 'destructive',
};

const search = ref(props.filters.q ?? '');
const status = ref(props.filters.status ?? '');
const assignee = ref(props.filters.assignee ?? '');
const priority = ref(props.filters.priority ?? '');
const overdueOnly = ref(props.filters.overdue === true);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters() {
    router.get(
        '/crm/tasks',
        {
            q: search.value || undefined,
            status: status.value || undefined,
            assignee: assignee.value || undefined,
            priority: priority.value || undefined,
            overdue: overdueOnly.value ? 1 : undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 300);
});
watch([status, assignee, priority, overdueOnly], applyFilters);

const showForm = ref(false);
const createForm = useForm<{
    title: string;
    description: string;
    assigned_to_user_id: number | null;
    priority: CrmTaskPriority;
    due_at: string;
}>({
    title: '',
    description: '',
    assigned_to_user_id: null,
    priority: 'normal',
    due_at: '',
});

function submitCreate() {
    createForm
        .transform((data) => ({ ...data, due_at: data.due_at || null }))
        .post('/crm/tasks', {
            preserveScroll: true,
            onSuccess: () => {
                showForm.value = false;
                createForm.reset();
            },
        });
}

/** Inline edits: one field at a time, straight to PUT /crm/tasks/{id}. */
function patch(task: CrmTask, payload: Record<string, string | number | null>) {
    router.put(`/crm/tasks/${task.id}`, payload, { preserveScroll: true });
}

function reassign(task: CrmTask, value: string) {
    patch(task, { assigned_to_user_id: value === '' ? null : Number(value) });
}

function deleteTask(task: CrmTask) {
    if (confirm(`${t('confirmDeleteTask')} (${task.title})`)) {
        router.delete(`/crm/tasks/${task.id}`, { preserveScroll: true });
    }
}

function isOverdue(task: CrmTask): boolean {
    return (
        task.due_at !== null &&
        (task.status === 'open' || task.status === 'in_progress') &&
        new Date(task.due_at) < new Date()
    );
}

function formatDue(value: string | null): string {
    return value ? value.slice(0, 10) : '—';
}

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'CRM', href: '/crm' },
            { title: 'Tasks', href: '/crm/tasks' },
        ],
    }),
});
</script>

<template>
    <Head :title="`${t('tasks')} — CRM`" />

    <div class="m-2 flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <Link href="/crm">
                    <Button variant="ghost" size="sm">
                        <ArrowLeft class="h-4 w-4" />
                    </Button>
                </Link>
                <Heading
                    variant="small"
                    :title="t('tasks')"
                    :description="t('tasksDescription')"
                />
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="ghost" size="sm" @click="toggleLocale">
                    <Languages class="h-4 w-4" />
                    {{ locale === 'ru' ? 'EN' : 'RU' }}
                </Button>
                <Button @click="showForm = !showForm">
                    <Plus class="h-4 w-4" /> {{ t('addTask') }}
                </Button>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="grid grid-cols-2 gap-3 md:grid-cols-6">
            <Card
                v-for="card in [
                    { label: t('statOpen'), value: stats.open },
                    { label: t('statInProgress'), value: stats.in_progress },
                    { label: t('statOverdue'), value: stats.overdue },
                    { label: t('statUnassigned'), value: stats.unassigned },
                    { label: t('statMine'), value: stats.mine },
                    { label: t('statDone'), value: stats.done },
                ]"
                :key="card.label"
            >
                <CardHeader class="pb-2">
                    <CardTitle
                        class="text-xs font-medium text-muted-foreground"
                    >
                        {{ card.label }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-semibold">{{ card.value }}</div>
                </CardContent>
            </Card>
        </div>

        <!-- Create form -->
        <div v-if="showForm" class="rounded-lg border p-4">
            <h3 class="mb-4 text-lg font-medium">{{ t('newTask') }}</h3>
            <form @submit.prevent="submitCreate" class="space-y-4">
                <div>
                    <label class="text-sm font-medium">
                        {{ t('taskTitle') }}
                    </label>
                    <Input
                        v-model="createForm.title"
                        class="mt-1"
                        :placeholder="t('taskTitlePlaceholder')"
                    />
                    <p
                        v-if="createForm.errors.title"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ createForm.errors.title }}
                    </p>
                </div>
                <div>
                    <label class="text-sm font-medium">
                        {{ t('taskDescription') }}
                    </label>
                    <textarea
                        v-model="createForm.description"
                        rows="3"
                        class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    ></textarea>
                </div>
                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="text-sm font-medium">
                            {{ t('assignee') }}
                        </label>
                        <select
                            v-model="createForm.assigned_to_user_id"
                            class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option :value="null">{{ t('unassigned') }}</option>
                            <option
                                v-for="operator in options.assignees"
                                :key="operator.id"
                                :value="operator.id"
                            >
                                {{ operator.name }}
                            </option>
                        </select>
                        <p
                            v-if="options.assignees.length === 0"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            {{ t('noOperators') }}
                        </p>
                    </div>
                    <div>
                        <label class="text-sm font-medium">
                            {{ t('priority') }}
                        </label>
                        <select
                            v-model="createForm.priority"
                            class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option
                                v-for="option in options.priorities"
                                :key="option"
                                :value="option"
                            >
                                {{ t(`priority.${option}`) }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-medium">
                            {{ t('dueDate') }}
                        </label>
                        <Input
                            v-model="createForm.due_at"
                            type="date"
                            class="mt-1"
                        />
                    </div>
                </div>
                <div class="flex gap-2">
                    <Button
                        type="submit"
                        :disabled="createForm.processing || !createForm.title"
                    >
                        {{ t('create') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="showForm = false"
                    >
                        {{ t('cancel') }}
                    </Button>
                </div>
            </form>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap items-center gap-2">
            <Input
                v-model="search"
                :placeholder="t('taskTitlePlaceholder')"
                class="max-w-xs"
            />
            <select
                v-model="status"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
                <option value="">{{ t('allStatuses') }}</option>
                <option
                    v-for="option in options.statuses"
                    :key="option"
                    :value="option"
                >
                    {{ t(`taskStatus.${option}`) }}
                </option>
            </select>
            <select
                v-model="assignee"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
                <option value="">{{ t('allAssignees') }}</option>
                <option v-if="currentUserId" value="me">{{ t('mine') }}</option>
                <option value="unassigned">{{ t('unassigned') }}</option>
                <option
                    v-for="operator in options.assignees"
                    :key="operator.id"
                    :value="String(operator.id)"
                >
                    {{ operator.name }}
                </option>
            </select>
            <select
                v-model="priority"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
                <option value="">{{ t('allPriorities') }}</option>
                <option
                    v-for="option in options.priorities"
                    :key="option"
                    :value="option"
                >
                    {{ t(`priority.${option}`) }}
                </option>
            </select>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="overdueOnly" type="checkbox" class="h-4 w-4" />
                {{ t('onlyOverdue') }}
            </label>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead
                    class="border-b bg-muted/50 text-left text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-2 font-medium">
                            {{ t('colTask') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('colContact') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('assignee') }}
                        </th>
                        <th class="px-4 py-2 font-medium">{{ t('status') }}</th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('priority') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('dueDate') }}
                        </th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="task in tasks.data"
                        :key="task.id"
                        class="border-b last:border-0 hover:bg-muted/30"
                    >
                        <td class="px-4 py-2">
                            <div
                                class="font-medium"
                                :class="
                                    task.status === 'done' ||
                                    task.status === 'cancelled'
                                        ? 'text-muted-foreground line-through'
                                        : ''
                                "
                            >
                                {{ task.title }}
                            </div>
                            <div
                                v-if="task.description"
                                class="text-xs text-muted-foreground"
                            >
                                {{ task.description }}
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            <Link
                                v-if="task.contact"
                                :href="`/crm/${task.contact.id}`"
                                class="text-blue-500 hover:underline"
                            >
                                {{
                                    task.contact.name ||
                                    task.contact.email ||
                                    `#${task.contact.id}`
                                }}
                            </Link>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-2">
                            <select
                                :value="task.assigned_to_user_id ?? ''"
                                class="rounded-md border border-input bg-background px-2 py-1 text-xs"
                                @change="
                                    reassign(
                                        task,
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option value="">{{ t('unassigned') }}</option>
                                <option
                                    v-for="operator in options.assignees"
                                    :key="operator.id"
                                    :value="operator.id"
                                >
                                    {{ operator.name }}
                                </option>
                            </select>
                        </td>
                        <td class="px-4 py-2">
                            <select
                                :value="task.status"
                                class="rounded-md border border-input bg-background px-2 py-1 text-xs"
                                @change="
                                    patch(task, {
                                        status: (
                                            $event.target as HTMLSelectElement
                                        ).value,
                                    })
                                "
                            >
                                <option
                                    v-for="option in options.statuses"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ t(`taskStatus.${option}`) }}
                                </option>
                            </select>
                        </td>
                        <td class="px-4 py-2">
                            <Badge
                                :variant="
                                    priorityVariant[task.priority] as never
                                "
                            >
                                {{ t(`priority.${task.priority}`) }}
                            </Badge>
                        </td>
                        <td class="px-4 py-2">
                            <span
                                :class="
                                    isOverdue(task)
                                        ? 'font-medium text-destructive'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ formatDue(task.due_at) }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="deleteTask(task)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div
                v-if="tasks.data.length === 0"
                class="flex flex-col items-center gap-2 py-12 text-muted-foreground"
            >
                <CheckSquare class="h-8 w-8" />
                <p>{{ t('noTasks') }}</p>
            </div>
        </div>

        <!-- Pagination -->
        <div
            v-if="tasks.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-2"
        >
            <span class="text-xs text-muted-foreground">
                {{ tasks.from }}–{{ tasks.to }} {{ t('of') }}
                {{ tasks.total }}
            </span>
            <div class="flex flex-wrap gap-1">
                <Link
                    v-for="link in tasks.links"
                    :key="link.label"
                    :href="link.url ?? ''"
                    preserve-scroll
                    :class="[
                        'rounded border px-2 py-1 text-xs',
                        link.active ? 'bg-primary text-primary-foreground' : '',
                        !link.url ? 'pointer-events-none opacity-50' : '',
                    ]"
                >
                    <span v-html="link.label" />
                </Link>
            </div>
        </div>
    </div>
</template>
