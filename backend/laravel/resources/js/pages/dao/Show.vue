<script setup lang="ts">
import {
    Head,
    Link as InertiaLink,
    router,
    useForm,
    usePage,
} from '@inertiajs/vue3';
import {
    Edit,
    MessageSquare,
    Plus,
    ThumbsDown,
    ThumbsUp,
    Trash2,
    Vote,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Header from '@/components/Header.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { Dao, Proposal } from '@/types';

type Props = {
    dao: Dao;
    proposals: Proposal[];
};

const props = defineProps<Props>();
const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const showForm = ref(false);
const editingProposal = ref<Proposal | null>(null);

const createForm = useForm({
    dao_id: props.dao.id,
    title: '',
    description: '',
});

const editForm = useForm({
    title: '',
    description: '',
    status: '' as string,
});

function openCreate() {
    if (!isAuthenticated.value) {
        return;
    }

    editingProposal.value = null;
    createForm.reset();
    createForm.dao_id = props.dao.id;
    showForm.value = true;
}

function openEdit(proposal: Proposal) {
    if (!isAuthenticated.value) {
        return;
    }

    editingProposal.value = proposal;
    editForm.title = proposal.title;
    editForm.description = proposal.description || '';
    editForm.status = proposal.status;
    showForm.value = true;
}

function submitCreate() {
    if (!isAuthenticated.value) {
        return;
    }

    createForm.post(`/dao/${props.dao.id}/proposals`, {
        onSuccess: () => {
            showForm.value = false;
            createForm.reset();
            createForm.dao_id = props.dao.id;
        },
    });
}

function submitEdit() {
    if (!isAuthenticated.value || !editingProposal.value) {
        return;
    }

    editForm.put(`/proposals/${editingProposal.value.id}`, {
        onSuccess: () => {
            showForm.value = false;
        },
    });
}

function deleteProposal(proposal: Proposal) {
    if (!isAuthenticated.value) {
        return;
    }

    if (confirm(`Delete "${proposal.title}"?`)) {
        router.delete(`/proposals/${proposal.id}`);
    }
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

defineOptions({
    layout: null,
});
</script>

<template>
    <Head :title="`DAO: ${props.dao.name}`" />

    <div class="min-h-screen bg-background text-foreground">
        <Header />

        <main class="mx-auto flex max-w-5xl flex-col space-y-6 px-4 py-8">
            <!-- DAO Header -->
            <div class="flex items-center justify-between">
                <div class="space-y-1">
                    <Heading
                        variant="small"
                        :title="props.dao.name"
                        description="Proposals for this DAO"
                    />
                    <div class="flex items-center gap-2">
                        <Badge variant="secondary" class="font-mono text-xs">{{
                            props.dao.address
                        }}</Badge>
                    </div>
                </div>
                <Button v-if="isAuthenticated" @click="openCreate">
                    <Plus class="mr-1 h-4 w-4" /> New Proposal
                </Button>
            </div>

            <!-- Create / Edit form -->
            <div
                v-if="isAuthenticated && showForm"
                class="rounded-lg border p-4"
            >
                <h3 class="mb-4 text-lg font-medium">
                    {{ editingProposal ? 'Edit Proposal' : 'New Proposal' }}
                </h3>

                <!-- Create form -->
                <form
                    v-if="!editingProposal"
                    @submit.prevent="submitCreate"
                    class="space-y-4"
                >
                    <div>
                        <label class="text-sm font-medium">Title</label>
                        <Input
                            v-model="createForm.title"
                            class="mt-1"
                            placeholder="Proposal title"
                            required
                        />
                        <InputError :message="createForm.errors.title" />
                    </div>
                    <div>
                        <label class="text-sm font-medium">Description</label>
                        <textarea
                            v-model="createForm.description"
                            class="mt-1 block min-h-[100px] w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm"
                            placeholder="Describe your proposal..."
                        />
                        <InputError :message="createForm.errors.description" />
                    </div>
                    <div class="flex gap-2">
                        <Button type="submit" :disabled="createForm.processing"
                            >Create</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            @click="showForm = false"
                            >Cancel</Button
                        >
                    </div>
                </form>

                <!-- Edit form -->
                <form v-else @submit.prevent="submitEdit" class="space-y-4">
                    <div>
                        <label class="text-sm font-medium">Title</label>
                        <Input v-model="editForm.title" class="mt-1" required />
                        <InputError :message="editForm.errors.title" />
                    </div>
                    <div>
                        <label class="text-sm font-medium">Description</label>
                        <textarea
                            v-model="editForm.description"
                            class="mt-1 block min-h-[100px] w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                        <InputError :message="editForm.errors.description" />
                    </div>
                    <div>
                        <label class="text-sm font-medium">Status</label>
                        <select
                            v-model="editForm.status"
                            class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="open">Open</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <Button type="submit" :disabled="editForm.processing"
                            >Update</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            @click="showForm = false"
                            >Cancel</Button
                        >
                    </div>
                </form>
            </div>

            <!-- Proposals list -->
            <div class="space-y-3">
                <div
                    v-for="proposal in props.proposals"
                    :key="proposal.id"
                    class="flex items-center justify-between rounded-lg border p-4 transition-colors hover:bg-muted/50"
                >
                    <div class="flex items-center gap-4">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-muted"
                        >
                            <Vote class="h-5 w-5" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <InertiaLink
                                    :href="`/proposals/${proposal.id}`"
                                    class="font-medium hover:underline"
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
                                class="mt-1 flex items-center gap-3 text-sm text-muted-foreground"
                            >
                                <span
                                    >by
                                    {{ proposal.user?.name || 'Unknown' }}</span
                                >
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

                    <TooltipProvider v-if="isAuthenticated">
                        <div class="flex items-center gap-2">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="openEdit(proposal)"
                                    >
                                        <Edit class="h-4 w-4" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent><p>Edit</p></TooltipContent>
                            </Tooltip>

                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="deleteProposal(proposal)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent><p>Delete</p></TooltipContent>
                            </Tooltip>
                        </div>
                    </TooltipProvider>
                </div>

                <p
                    v-if="props.proposals.length === 0"
                    class="py-8 text-center text-muted-foreground"
                >
                    {{
                        isAuthenticated
                            ? 'No proposals yet. Create one to get started.'
                            : 'No proposals yet.'
                    }}
                </p>
            </div>
        </main>
    </div>
</template>
