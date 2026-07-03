<script setup lang="ts">
import { Link as InertiaLink, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    destroy as daoDestroy,
    show as daoShow,
    store as daoStore,
    update as daoUpdate,
} from '@/routes/dao';
import type { Dao, User } from '@/types';

const props = defineProps<{
    daos: Dao[];
}>();

const page = usePage();
const authUser = computed(() => page.props.auth?.user as User | undefined);
const isAuthenticated = computed(() => !!authUser.value);

const showForm = ref(false);
const editingDao = ref<Dao | null>(null);

const form = useForm({
    address: '',
    name: '',
});

function openCreate() {
    editingDao.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}

function openEdit(dao: Dao) {
    editingDao.value = dao;
    form.address = dao.address;
    form.name = dao.name;
    form.clearErrors();
    showForm.value = true;
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    };

    if (editingDao.value) {
        form.put(daoUpdate(editingDao.value.id).url, options);
    } else {
        form.post(daoStore().url, options);
    }
}

function deleteDao(dao: Dao) {
    if (confirm(`Delete "${dao.name}"?`)) {
        form.delete(daoDestroy(dao.id).url, { preserveScroll: true });
    }
}

function canManage(dao: Dao): boolean {
    return !!authUser.value && dao.user_id === authUser.value.id;
}

function formatAddress(address: string): string {
    if (address.length <= 12) {
        return address;
    }

    return address.slice(0, 6) + '...' + address.slice(-4);
}
</script>

<template>
    <div class="space-y-3 rounded-lg border border-border/70 bg-card p-4">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold tracking-widest text-muted-foreground uppercase">
                DAOs
            </h3>
            <Button
                v-if="isAuthenticated"
                variant="ghost"
                size="icon-sm"
                @click="showForm ? (showForm = false) : openCreate()"
            >
                <X v-if="showForm" class="h-4 w-4" />
                <Plus v-else class="h-4 w-4" />
            </Button>
        </div>

        <form
            v-if="isAuthenticated && showForm"
            class="space-y-2 rounded-md border border-border/70 p-3"
            @submit.prevent="submit"
        >
            <p class="text-xs font-medium">
                {{ editingDao ? 'Edit DAO' : 'Register DAO' }}
            </p>
            <Input v-model="form.name" placeholder="Name" required />
            <InputError :message="form.errors.name" />
            <Input
                v-model="form.address"
                placeholder="Token address 0x… (voting power source)"
                class="font-mono"
                required
            />
            <InputError :message="form.errors.address" />
            <Button type="submit" size="sm" :disabled="form.processing">
                {{ editingDao ? 'Update' : 'Create' }}
            </Button>
        </form>

        <ul class="space-y-1">
            <li
                v-for="dao in props.daos"
                :key="dao.id"
                class="group flex items-center justify-between gap-2 rounded-md px-2 py-1.5 hover:bg-accent"
            >
                <InertiaLink
                    :href="daoShow(dao.id).url"
                    class="min-w-0 flex-1"
                >
                    <span class="block truncate text-sm font-medium">
                        {{ dao.name }}
                    </span>
                    <span class="block truncate font-mono text-xs text-muted-foreground">
                        {{ formatAddress(dao.address) }}
                    </span>
                </InertiaLink>

                <Badge variant="outline" class="shrink-0">
                    {{ dao.proposals_count || 0 }}
                </Badge>

                <span
                    v-if="canManage(dao)"
                    class="hidden shrink-0 items-center gap-1 group-hover:flex"
                >
                    <Button variant="ghost" size="icon-sm" @click="openEdit(dao)">
                        <Pencil class="h-3.5 w-3.5" />
                    </Button>
                    <Button variant="ghost" size="icon-sm" @click="deleteDao(dao)">
                        <Trash2 class="h-3.5 w-3.5" />
                    </Button>
                </span>
            </li>
        </ul>

        <p
            v-if="props.daos.length === 0"
            class="py-4 text-center text-sm text-muted-foreground"
        >
            No DAOs yet.
        </p>
    </div>
</template>
