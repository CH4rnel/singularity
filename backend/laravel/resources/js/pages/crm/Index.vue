<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    BarChart3,
    CheckSquare,
    Download,
    Languages,
    Plus,
    RefreshCw,
    Trash2,
    Users,
    Wallet,
} from 'lucide-vue-next';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import { crmMessages } from '@/lib/crmMessages';
import type { CrmContact, CrmContactType, Paginated } from '@/types';

type Filters = {
    q: string | null;
    type: string | null;
    status: string | null;
    source: string | null;
    chain: string | null;
};

type Props = {
    contacts: Paginated<CrmContact>;
    filters: Filters;
    stats: {
        total: number;
        leads: number;
        holders: number;
        whales: number;
        customers: number;
        evm: number;
        solana: number;
        both: number;
    };
    options: {
        types: string[];
        statuses: string[];
        sources: string[];
        chains: string[];
    };
};

const props = defineProps<Props>();

const { nextTag, toggleLocale, t } = useLocale(crmMessages);

const typeVariant: Record<CrmContactType, string> = {
    lead: 'secondary',
    holder: 'default',
    whale: 'destructive',
};

const search = ref(props.filters.q ?? '');
const type = ref(props.filters.type ?? '');
const status = ref(props.filters.status ?? '');
const source = ref(props.filters.source ?? '');
const chain = ref(props.filters.chain ?? '');

let searchTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters() {
    router.get(
        '/crm',
        {
            q: search.value || undefined,
            type: type.value || undefined,
            status: status.value || undefined,
            source: source.value || undefined,
            chain: chain.value || undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 300);
});
watch([type, status, source, chain], applyFilters);

const showForm = ref(false);
const createForm = useForm({
    name: '',
    email: '',
    telegram: '',
    evm_address: '',
    solana_address: '',
    type: 'lead',
    status: 'new',
});

function submitCreate() {
    createForm.post('/crm', {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            createForm.reset();
        },
    });
}

const syncing = ref(false);
function syncNow() {
    router.post(
        '/crm/sync',
        {},
        {
            preserveScroll: true,
            onStart: () => (syncing.value = true),
            onFinish: () => (syncing.value = false),
        },
    );
}

function deleteContact(contact: CrmContact) {
    const label = contact.name || contact.email || `#${contact.id}`;

    if (confirm(`${t('confirmDelete')} (${label})`)) {
        router.delete(`/crm/${contact.id}`, { preserveScroll: true });
    }
}

function formatCyber(value: string | null): string {
    if (value === null) {
        return '—';
    }

    const num = parseFloat(value);

    if (num === 0) {
        return '0';
    }

    if (num < 0.0001) {
        return '<0.0001';
    }

    return num.toLocaleString('en-US', { maximumFractionDigits: 4 });
}

function shortAddr(addr: string | null): string {
    if (!addr) {
        return '';
    }

    return addr.length > 12 ? `${addr.slice(0, 6)}…${addr.slice(-4)}` : addr;
}

defineOptions({
    layout: () => ({
        breadcrumbs: [{ title: 'CRM', href: '/crm' }],
    }),
});
</script>

<template>
    <Head title="CRM" />

    <div class="m-2 flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <Heading
                variant="small"
                :title="t('title')"
                :description="t('description')"
            />
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="ghost" size="sm" @click="toggleLocale">
                    <Languages class="h-4 w-4" />
                    {{ nextTag }}
                </Button>
                <Link href="/crm/tasks">
                    <Button variant="outline">
                        <CheckSquare class="h-4 w-4" /> {{ t('tasks') }}
                    </Button>
                </Link>
                <Link href="/crm/analytics">
                    <Button variant="outline">
                        <BarChart3 class="h-4 w-4" /> {{ t('analytics') }}
                    </Button>
                </Link>
                <!--
                  A different subject from the site funnel next to it: that one
                  counts browser sessions on cyberia.church, this one counts
                  installations of the wallet.
                -->
                <Link href="/crm/product">
                    <Button variant="outline">
                        <Wallet class="h-4 w-4" /> {{ t('walletAnalytics') }}
                    </Button>
                </Link>
                <!--
                  Neither of the two above: not who visited and not who
                  installed, but whether the things they visited and installed
                  are actually running, and which of them nobody touches.
                -->
                <Link href="/crm/services">
                    <Button variant="outline">
                        <Activity class="h-4 w-4" /> {{ t('services') }}
                    </Button>
                </Link>
                <Button variant="outline" :disabled="syncing" @click="syncNow">
                    <RefreshCw
                        class="h-4 w-4"
                        :class="syncing ? 'animate-spin' : ''"
                    />
                    {{ t('sync') }}
                </Button>
                <a href="/crm/export">
                    <Button variant="outline">
                        <Download class="h-4 w-4" /> {{ t('export') }}
                    </Button>
                </a>
                <Button @click="showForm = !showForm">
                    <Plus class="h-4 w-4" /> {{ t('addContact') }}
                </Button>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Card
                v-for="card in [
                    { label: t('statTotal'), value: stats.total },
                    { label: t('statLeads'), value: stats.leads },
                    { label: t('statHolders'), value: stats.holders },
                    { label: t('statWhales'), value: stats.whales },
                    { label: t('statCustomers'), value: stats.customers },
                    { label: t('statEvm'), value: stats.evm },
                    { label: t('statSolana'), value: stats.solana },
                    { label: t('statBoth'), value: stats.both },
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
            <h3 class="mb-4 text-lg font-medium">{{ t('newContact') }}</h3>
            <form @submit.prevent="submitCreate" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="text-sm font-medium">
                            {{ t('name') }}
                        </label>
                        <Input v-model="createForm.name" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-sm font-medium">
                            {{ t('email') }}
                        </label>
                        <Input
                            v-model="createForm.email"
                            type="email"
                            class="mt-1"
                        />
                    </div>
                    <div>
                        <label class="text-sm font-medium">Telegram</label>
                        <Input v-model="createForm.telegram" class="mt-1" />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-sm font-medium">
                                {{ t('type') }}
                            </label>
                            <select
                                v-model="createForm.type"
                                class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option
                                    v-for="option in options.types"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ t(`type.${option}`) }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium">
                                {{ t('status') }}
                            </label>
                            <select
                                v-model="createForm.status"
                                class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option
                                    v-for="option in options.statuses"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ t(`status.${option}`) }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="text-sm font-medium">
                            {{ t('evmAddress') }}
                        </label>
                        <Input
                            v-model="createForm.evm_address"
                            class="mt-1 font-mono"
                            placeholder="0x…"
                        />
                        <p
                            v-if="createForm.errors.evm_address"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ createForm.errors.evm_address }}
                        </p>
                    </div>
                    <div>
                        <label class="text-sm font-medium">
                            {{ t('solanaAddress') }}
                        </label>
                        <Input
                            v-model="createForm.solana_address"
                            class="mt-1 font-mono"
                        />
                        <p
                            v-if="createForm.errors.solana_address"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ createForm.errors.solana_address }}
                        </p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <Button type="submit" :disabled="createForm.processing">
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
                :placeholder="t('searchPlaceholder')"
                class="max-w-xs"
            />
            <select
                v-model="type"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
                <option value="">{{ t('allTypes') }}</option>
                <option
                    v-for="option in options.types"
                    :key="option"
                    :value="option"
                >
                    {{ t(`type.${option}`) }}
                </option>
            </select>
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
                    {{ t(`status.${option}`) }}
                </option>
            </select>
            <select
                v-model="source"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
                <option value="">{{ t('allSources') }}</option>
                <option
                    v-for="option in options.sources"
                    :key="option"
                    :value="option"
                >
                    {{ t(`source.${option}`) }}
                </option>
            </select>
            <select
                v-model="chain"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
                <option value="">{{ t('allChains') }}</option>
                <option
                    v-for="option in options.chains"
                    :key="option"
                    :value="option"
                >
                    {{ t(`chain.${option}`) }}
                </option>
            </select>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead
                    class="border-b bg-muted/50 text-left text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-2 font-medium">
                            {{ t('colContact') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('colAddresses') }}
                        </th>
                        <th class="px-4 py-2 font-medium">{{ t('type') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('status') }}</th>
                        <th class="px-4 py-2 text-right font-medium">CYBER</th>
                        <th class="px-4 py-2 text-right font-medium">
                            CYBER.sol
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('colSource') }}
                        </th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="contact in contacts.data"
                        :key="contact.id"
                        class="border-b last:border-0 hover:bg-muted/30"
                    >
                        <td class="px-4 py-2">
                            <Link
                                :href="`/crm/${contact.id}`"
                                class="font-medium text-blue-500 hover:underline"
                            >
                                {{
                                    contact.name ||
                                    contact.email ||
                                    `#${contact.id}`
                                }}
                            </Link>
                            <div
                                v-if="contact.telegram"
                                class="text-xs text-muted-foreground"
                            >
                                {{ contact.telegram }}
                            </div>
                        </td>
                        <td class="px-4 py-2 font-mono text-xs">
                            <div v-if="contact.evm_address">
                                {{ shortAddr(contact.evm_address) }}
                            </div>
                            <div v-if="contact.solana_address">
                                {{ shortAddr(contact.solana_address) }}
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            <Badge
                                :variant="typeVariant[contact.type] as never"
                            >
                                {{ t(`type.${contact.type}`) }}
                            </Badge>
                        </td>
                        <td class="px-4 py-2">
                            <Badge variant="outline">{{
                                t(`status.${contact.status}`)
                            }}</Badge>
                        </td>
                        <td class="px-4 py-2 text-right font-mono">
                            {{ formatCyber(contact.cyber_balance) }}
                        </td>
                        <td class="px-4 py-2 text-right font-mono">
                            {{ formatCyber(contact.cyber_sol_balance) }}
                        </td>
                        <td class="px-4 py-2">
                            <span class="text-xs text-muted-foreground">
                                {{ t(`source.${contact.source}`) }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="deleteContact(contact)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div
                v-if="contacts.data.length === 0"
                class="flex flex-col items-center gap-2 py-12 text-muted-foreground"
            >
                <Users class="h-8 w-8" />
                <p>{{ t('noContacts') }}</p>
            </div>
        </div>

        <!-- Pagination -->
        <div
            v-if="contacts.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-2"
        >
            <span class="text-xs text-muted-foreground">
                {{ contacts.from }}–{{ contacts.to }} {{ t('of') }}
                {{ contacts.total }}
            </span>
            <div class="flex flex-wrap gap-1">
                <Link
                    v-for="link in contacts.links"
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
