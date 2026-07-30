<script setup lang="ts">
import { Head, Link as InertiaLink, useForm, usePage } from '@inertiajs/vue3';
import {
    MessageSquare,
    Plus,
    ThumbsDown,
    ThumbsUp,
    Trash2,
    Pencil,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SimplePagination from '@/components/web3/SimplePagination.vue';
import WalletAvatar from '@/components/web3/WalletAvatar.vue';
import { profileUrl } from '@/lib/profileUrl';
import { store as proposalStore } from '@/routes/dao/proposals';
import {
    destroy as proposalDestroy,
    show as proposalShow,
    update as proposalUpdate,
} from '@/routes/proposals';
import type { Dao, Proposal, User } from '@/types';
import type { Paginated } from '@/types/pagination';

type Props = {
    dao: Dao;
    proposals: Paginated<Proposal>;
};

const props = defineProps<Props>();
const page = usePage();
const authUser = computed(() => page.props.auth?.user as User | undefined);
const isAuthenticated = computed(() => !!authUser.value);

const showForm = ref(false);
const editingProposal = ref<Proposal | null>(null);

function defaultDeadline(): string {
    const date = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000);
    // datetime-local wants "YYYY-MM-DDTHH:MM" in local time.
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

const form = useForm({
    dao_id: props.dao.id,
    title: '',
    description: '',
    ends_at: defaultDeadline(),
});

function openCreate() {
    editingProposal.value = null;
    form.reset();
    form.clearErrors();
    form.dao_id = props.dao.id;
    form.ends_at = defaultDeadline();
    showForm.value = true;
}

function openEdit(proposal: Proposal) {
    editingProposal.value = proposal;
    form.title = proposal.title;
    form.description = proposal.description || '';
    form.ends_at = proposal.ends_at
        ? proposal.ends_at.slice(0, 16)
        : '';
    form.clearErrors();
    showForm.value = true;
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            form.reset();
            form.dao_id = props.dao.id;
        },
    };

    if (editingProposal.value) {
        form.put(proposalUpdate(editingProposal.value.id).url, options);
    } else {
        form.post(proposalStore(props.dao.id).url, options);
    }
}

function deleteProposal(proposal: Proposal) {
    if (confirm(`Delete "${proposal.title}"?`)) {
        form.delete(proposalDestroy(proposal.id).url, {
            preserveScroll: true,
        });
    }
}

function canManage(proposal: Proposal): boolean {
    return !!authUser.value && proposal.user_id === authUser.value.id;
}

function formatPower(power: string | undefined): string {
    const value = parseFloat(power || '0');

    if (value === 0) {
        return '0';
    }

    if (value < 0.0001) {
        return value.toExponential(2);
    }

    const truncated = Math.trunc(value * 10000) / 10000;

    if (truncated < 1) {
        return truncated.toString();
    }

    if (truncated < 1000) {
        return truncated.toFixed(4).replace(/\.?0+$/, '');
    }

    return truncated.toLocaleString('en-US', { maximumFractionDigits: 0 });
}
</script>

<template>
    <Head :title="`DAO: ${props.dao.name}`" />

    <div class="mx-auto max-w-5xl px-4 py-8">
        <!-- DAO header -->
        <header class="mb-6 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight">
                    {{ props.dao.name }}
                </h1>
                <Badge variant="secondary" class="mt-1 font-mono text-xs">
                    {{ props.dao.address }}
                </Badge>
            </div>
            <Button v-if="isAuthenticated" @click="openCreate">
                <Plus class="mr-1 h-4 w-4" /> New Proposal
            </Button>
        </header>

        <!-- Create / Edit form -->
        <form
            v-if="isAuthenticated && showForm"
            class="mb-6 space-y-4 rounded-lg border border-border/70 bg-card p-4"
            @submit.prevent="submit"
        >
            <h3 class="text-lg font-medium">
                {{ editingProposal ? 'Edit Proposal' : 'New Proposal' }}
            </h3>
            <div>
                <label class="text-sm font-medium">Title</label>
                <Input
                    v-model="form.title"
                    class="mt-1"
                    placeholder="Proposal title"
                    required
                />
                <InputError :message="form.errors.title" />
            </div>
            <div>
                <label class="text-sm font-medium">Description</label>
                <textarea
                    v-model="form.description"
                    class="mt-1 block min-h-[100px] w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm"
                    placeholder="Describe your proposal..."
                />
                <InputError :message="form.errors.description" />
            </div>
            <div>
                <label class="text-sm font-medium">Voting deadline</label>
                <Input
                    v-model="form.ends_at"
                    type="datetime-local"
                    class="mt-1 w-fit"
                />
                <p class="mt-1 text-xs text-muted-foreground">
                    Voting closes automatically at this time. Leave empty to
                    keep it open indefinitely.
                </p>
                <InputError :message="form.errors.ends_at" />
            </div>
            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">
                    {{ editingProposal ? 'Update' : 'Create' }}
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

        <!-- Proposals list -->
        <div class="space-y-3">
            <div
                v-for="proposal in props.proposals.data"
                :key="proposal.id"
                class="group flex items-center justify-between gap-3 rounded-lg border border-border/70 bg-card p-4 transition-colors hover:border-brand-cyan/40"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <InertiaLink
                        :href="profileUrl(proposal.user, proposal.user_id)"
                        class="shrink-0"
                    >
                        <WalletAvatar
                            :seed="proposal.user?.wallet_address"
                            :name="proposal.user?.name"
                            :src="proposal.user?.avatar"
                        />
                    </InertiaLink>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <InertiaLink
                                :href="proposalShow(proposal.id).url"
                                class="truncate font-medium hover:underline"
                            >
                                {{ proposal.title }}
                            </InertiaLink>
                            <Badge
                                :variant="
                                    proposal.status === 'open'
                                        ? 'default'
                                        : 'secondary'
                                "
                            >
                                {{ proposal.status }}
                            </Badge>
                        </div>
                        <div
                            class="mt-1 flex flex-wrap items-center gap-3 text-sm text-muted-foreground"
                        >
                            <InertiaLink
                                :href="profileUrl(proposal.user, proposal.user_id)"
                                class="hover:underline"
                            >
                                {{ proposal.user?.name || 'Unknown' }}
                            </InertiaLink>
                            <span class="flex items-center gap-1">
                                <ThumbsUp class="h-3 w-3 text-green-500" />
                                {{ formatPower(proposal.power_for) }}
                            </span>
                            <span class="flex items-center gap-1">
                                <ThumbsDown class="h-3 w-3 text-red-500" />
                                {{ formatPower(proposal.power_against) }}
                            </span>
                            <span class="flex items-center gap-1">
                                <MessageSquare class="h-3 w-3" />
                                {{ proposal.comments_count || 0 }}
                            </span>
                        </div>
                    </div>
                </div>

                <div
                    v-if="canManage(proposal)"
                    class="hidden shrink-0 items-center gap-1 group-hover:flex"
                >
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        @click="openEdit(proposal)"
                    >
                        <Pencil class="h-4 w-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        @click="deleteProposal(proposal)"
                    >
                        <Trash2 class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <p
                v-if="props.proposals.data.length === 0"
                class="rounded-lg border border-dashed py-12 text-center text-sm text-muted-foreground"
            >
                {{
                    isAuthenticated
                        ? 'No proposals yet. Create one to get started.'
                        : 'No proposals yet.'
                }}
            </p>

            <SimplePagination :paginator="props.proposals" />
        </div>
    </div>
</template>
