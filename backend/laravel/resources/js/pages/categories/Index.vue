<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Link as InertiaLink } from '@inertiajs/vue3';
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
import type { Category, Team } from '@/types';

type Props = {
    categories: Category[];
};

const props = defineProps<Props>();
console.log('Categories:', props.categories);

const showForm = ref(false);
const editingCategory = ref<Category | null>(null);

const createForm = useForm({
    name: '',
});

const editForm = useForm({
    name: '',
});

function openCreate() {
    editingCategory.value = null;
    createForm.reset();
    showForm.value = true;
}

function openEdit(category: Category) {
    editingCategory.value = category;
    editForm.name = category.name;
    showForm.value = true;
}

function submitCreate() {
    createForm.post('/categories', {
        onSuccess: () => {
            showForm.value = false;
            createForm.reset();
            router.reload();
        },
    });
}

function submitEdit() {
    if (!editingCategory.value) return;
    
    editForm.put(`/categories/${editingCategory.value.id}`, {
        onSuccess: () => {
            showForm.value = false;
            router.reload();
        },
    });
}

function deleteCategory(category: Category) {
    if (confirm(`Delete "${category.name}"?`)) {
        router.delete(`/categories/${category.id}`);
    }
}

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Categories',
                href: '/categories',
            },
        ],
    }),
});
</script>

<template>
    <Head class="m-3" title="Categories" />

    <div class="flex flex-col space-y-6 m-2">
        <div class="flex items-center justify-between">
            <Heading variant="small" title="Categories" description="Organize your links" />
            <Button @click="openCreate">
                <Plus /> Add Category
            </Button>
        </div>

        <div v-if="showForm" class="rounded-lg border p-4">
            <h3 class="mb-4 text-lg font-medium">
                {{ editingCategory ? 'Edit Category' : 'Add New Category' }}
            </h3>

            <form v-if="!editingCategory" @submit.prevent="submitCreate" class="space-y-4">
                <div>
                    <label class="text-sm font-medium">Name</label>
                    <Input v-model="createForm.name" class="mt-1" required />
                </div>

                <div class="flex gap-2">
                    <Button type="submit" :disabled="createForm.processing">Create</Button>
                    <Button type="button" variant="outline" @click="showForm = false">Cancel</Button>
                </div>
            </form>

            <form v-else @submit.prevent="submitEdit" class="space-y-4">
                <div>
                    <label class="text-sm font-medium">Name</label>
                    <Input v-model="editForm.name" class="mt-1" required />
                </div>

                <div class="flex gap-2">
                    <Button type="submit" :disabled="editForm.processing">Update</Button>
                    <Button type="button" variant="outline" @click="showForm = false">Cancel</Button>
                </div>
            </form>
        </div>

        <div class="space-y-3">
            <div v-for="category in props.categories" :key="category.id" class="flex items-center justify-between rounded-lg border p-4">
                <div class="flex items-center gap-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                        <Folder class="h-5 w-5" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ category.name }}</span>
                            <Badge variant="secondary">{{ category.links?.length || 0 }} links</Badge>
                        </div>
                    </div>
                </div>

                <TooltipProvider>
                    <div class="flex items-center gap-2">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button variant="ghost" size="sm" @click="openEdit(category)">
                                    <Edit class="h-4 w-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent><p>Edit</p></TooltipContent>
                        </Tooltip>

                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button variant="ghost" size="sm" @click="deleteCategory(category)">
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent><p>Delete</p></TooltipContent>
                        </Tooltip>
                    </div>
                </TooltipProvider>
            </div>

            <p v-if="props.categories.length === 0" class="py-8 text-center text-muted-foreground">
                No categories yet. Add one to get started.
            </p>
        </div>
    </div>
</template>