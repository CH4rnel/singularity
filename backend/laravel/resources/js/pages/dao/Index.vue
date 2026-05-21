<script setup lang="ts">
import {
    Head,
    Link as InertiaLink,
    router,
    useForm,
    usePage,
} from '@inertiajs/vue3';
import { Edit, Plus, Trash2, Vote } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Header from '@/components/Header.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { Dao } from '@/types';

type Props = {
    daos: Dao[];
};

const props = defineProps<Props>();
const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const showForm = ref(false);
const editingDao = ref<Dao | null>(null);

const createForm = useForm({
    address: '',
    name: '',
});

const editForm = useForm({
    address: '',
    name: '',
});

function openCreate() {
    if (!isAuthenticated.value) {
        return;
    }

    editingDao.value = null;
    createForm.reset();
    showForm.value = true;
}

function openEdit(dao: Dao) {
    if (!isAuthenticated.value) {
        return;
    }

    editingDao.value = dao;
    editForm.address = dao.address;
    editForm.name = dao.name;
    showForm.value = true;
}

function submitCreate() {
    if (!isAuthenticated.value) {
        return;
    }

    createForm.post('/dao', {
        onSuccess: () => {
            showForm.value = false;
            createForm.reset();
            router.reload();
        },
    });
}

function submitEdit() {
    if (!isAuthenticated.value || !editingDao.value) {
        return;
    }

    editForm.put(`/dao/${editingDao.value.id}`, {
        onSuccess: () => {
            showForm.value = false;
            router.reload();
        },
    });
}

function deleteDao(dao: Dao) {
    if (!isAuthenticated.value) {
        return;
    }

    if (confirm(`Delete "${dao.name}"?`)) {
        router.delete(`/dao/${dao.id}`);
    }
}

function formatAddress(address: string): string {
    if (address.length <= 12) {
        return address;
    }

    return address.slice(0, 6) + '...' + address.slice(-4);
}

defineOptions({
    layout: null,
});
</script>

<template>
    <Head class="m-3" title="DAO" />

    <div class="min-h-screen bg-background text-foreground">
        <Header />

        <main class="mx-auto flex max-w-5xl flex-col space-y-6 px-4 py-8">
            <div class="flex items-center justify-between">
                <Heading
                    variant="small"
                    title="DAO"
                    description="Public DAO proposals"
                />
                <Button v-if="isAuthenticated" @click="openCreate">
                    <Plus /> Add DAO
                </Button>
            </div>

            <div
                v-if="isAuthenticated && showForm"
                class="rounded-lg border p-4"
            >
                <h3 class="mb-4 text-lg font-medium">
                    {{ editingDao ? 'Edit DAO' : 'Add New DAO' }}
                </h3>

                <form
                    v-if="!editingDao"
                    @submit.prevent="submitCreate"
                    class="space-y-4"
                >
                    <div>
                        <label class="text-sm font-medium">Name</label>
                        <Input
                            v-model="createForm.name"
                            class="mt-1"
                            required
                        />
                    </div>

                    <div>
                        <label class="text-sm font-medium">Address</label>
                        <Input
                            v-model="createForm.address"
                            class="mt-1"
                            placeholder="0x..."
                            required
                        />
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

                <form v-else @submit.prevent="submitEdit" class="space-y-4">
                    <div>
                        <label class="text-sm font-medium">Name</label>
                        <Input v-model="editForm.name" class="mt-1" required />
                    </div>

                    <div>
                        <label class="text-sm font-medium">Address</label>
                        <Input
                            v-model="editForm.address"
                            class="mt-1"
                            placeholder="0x..."
                            required
                        />
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

            <div class="space-y-3">
                <div
                    v-for="dao in props.daos"
                    :key="dao.id"
                    class="flex items-center justify-between rounded-lg border p-4"
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
                                    :href="`/dao/${dao.id}`"
                                    class="font-medium hover:underline"
                                    >{{ dao.name }}</InertiaLink
                                >
                                <Badge variant="secondary" class="font-mono">{{
                                    formatAddress(dao.address)
                                }}</Badge>
                                <Badge variant="outline"
                                    >{{
                                        dao.proposals_count || 0
                                    }}
                                    proposals</Badge
                                >
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
                                        @click="openEdit(dao)"
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
                                        @click="deleteDao(dao)"
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
                    v-if="props.daos.length === 0"
                    class="py-8 text-center text-muted-foreground"
                >
                    {{
                        isAuthenticated
                            ? 'No DAOs yet. Add one to get started.'
                            : 'No DAOs yet.'
                    }}
                </p>
            </div>
        </main>
    </div>
</template>
