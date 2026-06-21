<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Trash2 } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import type { CrmBridgeActivity, CrmContact } from '@/types';

type Props = {
    contact: CrmContact;
    bridgeActivity: CrmBridgeActivity[];
    options: {
        types: string[];
        statuses: string[];
    };
};

const props = defineProps<Props>();

const detailsForm = useForm({
    name: props.contact.name ?? '',
    email: props.contact.email ?? '',
    telegram: props.contact.telegram ?? '',
    type: props.contact.type,
    status: props.contact.status,
    tags: (props.contact.tags ?? []).join(', '),
});

function saveDetails() {
    detailsForm
        .transform((data) => ({
            ...data,
            tags: data.tags
                .split(',')
                .map((t) => t.trim())
                .filter(Boolean),
        }))
        .put(`/crm/${props.contact.id}`, { preserveScroll: true });
}

const noteForm = useForm({ body: '' });

function addNote() {
    noteForm.post(`/crm/${props.contact.id}/notes`, {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });
}

function deleteNote(id: number) {
    router.delete(`/crm/notes/${id}`, { preserveScroll: true });
}

function deleteContact() {
    if (confirm('Delete this contact?')) {
        router.delete(`/crm/${props.contact.id}`);
    }
}

function formatCyber(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return parseFloat(value).toLocaleString('en-US', {
        maximumFractionDigits: 4,
    });
}

defineOptions({
    layout: () => ({
        breadcrumbs: [{ title: 'CRM', href: '/crm' }],
    }),
});
</script>

<template>
    <Head :title="contact.name || 'Contact'" />

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
                    :title="
                        contact.name ||
                        contact.email ||
                        `Contact #${contact.id}`
                    "
                    :description="
                        contact.source +
                        ' · joined ' +
                        contact.created_at.slice(0, 10)
                    "
                />
            </div>
            <Button variant="ghost" size="sm" @click="deleteContact">
                <Trash2 class="h-4 w-4" /> Delete
            </Button>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Left: editable details -->
            <div class="space-y-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form @submit.prevent="saveDetails" class="space-y-4">
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="text-sm font-medium">
                                        Name
                                    </label>
                                    <Input
                                        v-model="detailsForm.name"
                                        class="mt-1"
                                    />
                                </div>
                                <div>
                                    <label class="text-sm font-medium">
                                        Email
                                    </label>
                                    <Input
                                        v-model="detailsForm.email"
                                        type="email"
                                        class="mt-1"
                                    />
                                </div>
                                <div>
                                    <label class="text-sm font-medium">
                                        Telegram
                                    </label>
                                    <Input
                                        v-model="detailsForm.telegram"
                                        class="mt-1"
                                    />
                                </div>
                                <div>
                                    <label class="text-sm font-medium">
                                        Tags (comma-separated)
                                    </label>
                                    <Input
                                        v-model="detailsForm.tags"
                                        class="mt-1"
                                    />
                                </div>
                                <div>
                                    <label class="text-sm font-medium">
                                        Type
                                    </label>
                                    <select
                                        v-model="detailsForm.type"
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
                                    <label class="text-sm font-medium">
                                        Status
                                    </label>
                                    <select
                                        v-model="detailsForm.status"
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
                            <Button
                                type="submit"
                                :disabled="detailsForm.processing"
                            >
                                Save
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <!-- Notes / activity -->
                <Card>
                    <CardHeader>
                        <CardTitle>Notes</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <form @submit.prevent="addNote" class="flex gap-2">
                            <Input
                                v-model="noteForm.body"
                                placeholder="Add a note…"
                            />
                            <Button
                                type="submit"
                                :disabled="
                                    noteForm.processing || !noteForm.body
                                "
                            >
                                Add
                            </Button>
                        </form>

                        <div
                            v-for="note in contact.notes"
                            :key="note.id"
                            class="flex items-start justify-between rounded-md border p-3"
                        >
                            <div>
                                <p class="text-sm">{{ note.body }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ note.author?.name || 'System' }} ·
                                    {{
                                        note.created_at
                                            .slice(0, 16)
                                            .replace('T', ' ')
                                    }}
                                </p>
                            </div>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="deleteNote(note.id)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>
                        <p
                            v-if="!contact.notes || contact.notes.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No notes yet.
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- Right: on-chain & meta -->
            <div class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>On-chain</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <div>
                            <div class="text-xs text-muted-foreground">
                                EVM address
                            </div>
                            <div class="font-mono break-all">
                                {{ contact.evm_address || '—' }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Solana address
                            </div>
                            <div class="font-mono break-all">
                                {{ contact.solana_address || '—' }}
                            </div>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">CYBER</span>
                            <span class="font-mono">
                                {{ formatCyber(contact.cyber_balance) }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">CYBER.sol</span>
                            <span class="font-mono">
                                {{ formatCyber(contact.cyber_sol_balance) }}
                            </span>
                        </div>
                        <div
                            v-if="contact.last_synced_at"
                            class="text-xs text-muted-foreground"
                        >
                            Synced
                            {{
                                contact.last_synced_at
                                    .slice(0, 16)
                                    .replace('T', ' ')
                            }}
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="bridgeActivity.length">
                    <CardHeader>
                        <CardTitle>Bridge activity</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-2 text-sm">
                        <div
                            v-for="tx in bridgeActivity"
                            :key="tx.id"
                            class="flex items-center justify-between"
                        >
                            <div>
                                <span class="font-mono">{{ tx.amount }}</span>
                                {{ tx.token }}
                                <Badge variant="outline" class="ml-1">
                                    {{ tx.direction }}
                                </Badge>
                            </div>
                            <Badge
                                :variant="
                                    tx.status === 'completed'
                                        ? 'default'
                                        : 'secondary'
                                "
                            >
                                {{ tx.status }}
                            </Badge>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
