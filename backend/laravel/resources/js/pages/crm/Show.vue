<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Languages, Trash2 } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import { crmMessages } from '@/lib/crmMessages';
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

const { locale, toggleLocale, t } = useLocale(crmMessages);

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
    if (confirm(t('confirmDelete'))) {
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
    <Head :title="contact.name || t('contact')" />

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
                        `${t('contact')} #${contact.id}`
                    "
                    :description="
                        t(`source.${contact.source}`) +
                        ' · ' +
                        t('joined') +
                        ' ' +
                        contact.created_at.slice(0, 10)
                    "
                />
            </div>
            <div class="flex items-center gap-2">
                <Button variant="ghost" size="sm" @click="toggleLocale">
                    <Languages class="h-4 w-4" />
                    {{ locale === 'ru' ? 'EN' : 'RU' }}
                </Button>
                <Button variant="ghost" size="sm" @click="deleteContact">
                    <Trash2 class="h-4 w-4" /> {{ t('delete') }}
                </Button>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Left: editable details -->
            <div class="space-y-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ t('details') }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form @submit.prevent="saveDetails" class="space-y-4">
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="text-sm font-medium">
                                        {{ t('name') }}
                                    </label>
                                    <Input
                                        v-model="detailsForm.name"
                                        class="mt-1"
                                    />
                                </div>
                                <div>
                                    <label class="text-sm font-medium">
                                        {{ t('email') }}
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
                                        {{ t('tags') }}
                                    </label>
                                    <Input
                                        v-model="detailsForm.tags"
                                        class="mt-1"
                                    />
                                </div>
                                <div>
                                    <label class="text-sm font-medium">
                                        {{ t('type') }}
                                    </label>
                                    <select
                                        v-model="detailsForm.type"
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
                                        v-model="detailsForm.status"
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
                            <Button
                                type="submit"
                                :disabled="detailsForm.processing"
                            >
                                {{ t('save') }}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <!-- Notes / activity -->
                <Card>
                    <CardHeader>
                        <CardTitle>{{ t('notes') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <form @submit.prevent="addNote" class="flex gap-2">
                            <Input
                                v-model="noteForm.body"
                                :placeholder="t('addNotePlaceholder')"
                            />
                            <Button
                                type="submit"
                                :disabled="
                                    noteForm.processing || !noteForm.body
                                "
                            >
                                {{ t('add') }}
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
                                    {{ note.author?.name || t('system') }} ·
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
                            {{ t('noNotes') }}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- Right: on-chain & meta -->
            <div class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ t('onchain') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <div>
                            <div class="text-xs text-muted-foreground">
                                {{ t('evmAddress') }}
                            </div>
                            <div class="font-mono break-all">
                                {{ contact.evm_address || '—' }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-muted-foreground">
                                {{ t('solanaAddress') }}
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
                            {{ t('synced') }}
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
                        <CardTitle>{{ t('bridgeActivity') }}</CardTitle>
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
