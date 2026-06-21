<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Download, Plus, RefreshCw, Trash2, Users } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import type { CrmContact, CrmContactType, Paginated } from '@/types';

type Filters = {
    q: string | null;
    type: string | null;
    status: string | null;
    source: string | null;
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
    };
    options: {
        types: string[];
        statuses: string[];
        sources: string[];
    };
};

const props = defineProps<Props>();

const typeVariant: Record<CrmContactType, string> = {
    lead: 'secondary',
    holder: 'default',
    whale: 'destructive',
};

const search = ref(props.filters.q ?? '');
const type = ref(props.filters.type ?? '');
const status = ref(props.filters.status ?? '');
const source = ref(props.filters.source ?? '');

let searchTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters() {
    router.get(
        '/crm',
        {
            q: search.value || undefined,
            type: type.value || undefined,
            status: status.value || undefined,
            source: source.value || undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 300);
});
watch([type, status, source], applyFilters);

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
    if (confirm(`Delete "${contact.name || contact.email || contact.id}"?`)) {
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
                title="CRM"
                description="Contacts, holders and whales across the ecosystem"
            />
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" :disabled="syncing" @click="syncNow">
                    <RefreshCw
                        class="h-4 w-4"
                        :class="syncing ? 'animate-spin' : ''"
                    />
                    Sync
                </Button>
                <a href="/crm/export">
                    <Button variant="outline">
                        <Download class="h-4 w-4" /> Export
                    </Button>
                </a>
                <Button @click="showForm = !showForm">
                    <Plus class="h-4 w-4" /> Add contact
                </Button>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
            <Card
                v-for="card in [
                    { label: 'Total', value: stats.total },
                    { label: 'Leads', value: stats.leads },
                    { label: 'Holders', value: stats.holders },
                    { label: 'Whales', value: stats.whales },
                    { label: 'Customers', value: stats.customers },
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
            <h3 class="mb-4 text-lg font-medium">New contact</h3>
            <form @submit.prevent="submitCreate" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="text-sm font-medium">Name</label>
                        <Input v-model="createForm.name" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-sm font-medium">Email</label>
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
                            <label class="text-sm font-medium">Type</label>
                            <select
                                v-model="createForm.type"
                                class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option
                                    v-for="t in options.types"
                                    :key="t"
                                    :value="t"
                                >
                                    {{ t }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium">Status</label>
                            <select
                                v-model="createForm.status"
                                class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option
                                    v-for="s in options.statuses"
                                    :key="s"
                                    :value="s"
                                >
                                    {{ s }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="text-sm font-medium">EVM address</label>
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
                        <label class="text-sm font-medium"
                            >Solana address</label
                        >
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
                        Create
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="showForm = false"
                    >
                        Cancel
                    </Button>
                </div>
            </form>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap items-center gap-2">
            <Input
                v-model="search"
                placeholder="Search name, email, address…"
                class="max-w-xs"
            />
            <select
                v-model="type"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
                <option value="">All types</option>
                <option v-for="t in options.types" :key="t" :value="t">
                    {{ t }}
                </option>
            </select>
            <select
                v-model="status"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
                <option value="">All statuses</option>
                <option v-for="s in options.statuses" :key="s" :value="s">
                    {{ s }}
                </option>
            </select>
            <select
                v-model="source"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
                <option value="">All sources</option>
                <option v-for="s in options.sources" :key="s" :value="s">
                    {{ s }}
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
                        <th class="px-4 py-2 font-medium">Contact</th>
                        <th class="px-4 py-2 font-medium">Addresses</th>
                        <th class="px-4 py-2 font-medium">Type</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 text-right font-medium">CYBER</th>
                        <th class="px-4 py-2 text-right font-medium">
                            CYBER.sol
                        </th>
                        <th class="px-4 py-2 font-medium">Source</th>
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
                                {{ contact.type }}
                            </Badge>
                        </td>
                        <td class="px-4 py-2">
                            <Badge variant="outline">{{
                                contact.status
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
                                {{ contact.source }}
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
                <p>No contacts found. Add one or run a sync.</p>
            </div>
        </div>

        <!-- Pagination -->
        <div
            v-if="contacts.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-2"
        >
            <span class="text-xs text-muted-foreground">
                {{ contacts.from }}–{{ contacts.to }} of {{ contacts.total }}
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
