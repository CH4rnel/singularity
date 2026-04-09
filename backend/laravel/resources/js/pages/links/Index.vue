<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Edit, Link as LinkIcon, Plus, Trash2 } from 'lucide-vue-next';
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
import type { Category, Link, Team } from '@/types';

function isIPv6(str: string): boolean {
    return /^([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}$|^::/.test(str);
}

function formatUrl(url: string): string {
    if (isIPv6(url)) {
        return `[${url}]`;
    }
    return 'http://' + url;
}

type Props = {
    links: Link[];
    categories: Category[];
};

const props = defineProps<Props>();

const page = usePage();
const showForm = ref(false);
const editingLink = ref<Link | null>(null);
const selectedCategoryId = ref<number | null>(null);

onMounted(() => {
    const categoryId = page.props.category_id;
    if (categoryId) {
        selectedCategoryId.value = categoryId as number;
    }
});

const filteredLinks = computed(() => {
    if (selectedCategoryId.value === null) {
        return props.links;
    }
    return props.links.filter(link => link.category_id === selectedCategoryId.value);
});

function filterByCategory(categoryId: number | null) {
    selectedCategoryId.value = categoryId === selectedCategoryId.value ? null : categoryId;
}

const createForm = useForm({
    name: '',
    urls: [''],
    category_id: null as number | null,
});

const editForm = useForm({
    name: '',
    urls: [''] as string[],
    category_id: null as number | null,
});

function openCreate() {
    editingLink.value = null;
    createForm.reset();
    showForm.value = true;
}

function openEdit(link: Link) {
    editingLink.value = link;
    editForm.name = link.name;
    editForm.urls = link.urls?.length ? [...link.urls] : [''];
    editForm.category_id = link.category_id;
    showForm.value = true;
}

function submitCreate() {
    createForm.post('/links', {
        onSuccess: () => {
            showForm.value = false;
            createForm.reset();
            router.reload();
        },
    });
}

function submitEdit() {
    if (!editingLink.value) return;
    
    editForm.put(`/links/${editingLink.value.id}`, {
        onSuccess: () => {
            showForm.value = false;
            router.reload();
        },
    });
}

function deleteLink(link: Link) {
    if (confirm(`Delete "${link.name}"?`)) {
        router.delete(`/links/${link.id}`);
    }
}

function addUrl() {
    if (editingLink.value) {
        editForm.urls.push('');
    } else {
        createForm.urls.push('');
    }
}

function removeUrl(i: number) {
    if (editingLink.value) {
        if (editForm.urls.length > 1) editForm.urls.splice(i, 1);
    } else {
        if (createForm.urls.length > 1) createForm.urls.splice(i, 1);
    }
}

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Links',
                href: '/links',
            },
        ],
    }),
});
</script>

<template>
    <Head class="m-3" title="Links" />

    <div class="flex flex-col space-y-6 m-2">
        <div class="flex items-center justify-between">
            <Heading variant="small" title="Links" description="Manage your monitored links" />
            <Button @click="openCreate">
                <Plus /> Add Link
            </Button>
        </div>

        <div v-if="showForm" class="rounded-lg border p-4">
            <h3 class="mb-4 text-lg font-medium">
                {{ editingLink ? 'Edit Link' : 'Add New Link' }}
            </h3>

            <form v-if="!editingLink" @submit.prevent="submitCreate" class="space-y-4">
                <div>
                    <label class="text-sm font-medium">Name</label>
                    <Input v-model="createForm.name" class="mt-1" required />
                </div>

                <div>
                    <label class="text-sm font-medium">URLs (mirrors)</label>
                    <div class="space-y-2 mt-1">
                        <div v-for="(_, i) in createForm.urls" :key="i" class="flex gap-2">
                            <Input v-model="createForm.urls[i]" placeholder="https://..." />
                            <Button type="button" variant="ghost" size="icon" @click="removeUrl(i)" :disabled="createForm.urls.length <= 1">
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                    <Button type="button" variant="outline" size="sm" class="mt-2" @click="addUrl">
                        <Plus class="mr-1 h-4 w-4" /> Add URL
                    </Button>
                </div>

                <div>
                    <label class="text-sm font-medium">Category</label>
                    <select v-model="createForm.category_id" class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                        <option :value="null">No category</option>
                        <option v-for="category in props.categories" :key="category.id" :value="category.id">
                            {{ category.name }}
                        </option>
                    </select>
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

                <div>
                    <label class="text-sm font-medium">URLs (mirrors)</label>
                    <div class="space-y-2 mt-1">
                        <div v-for="(_, i) in editForm.urls" :key="i" class="flex gap-2">
                            <Input v-model="editForm.urls[i]" placeholder="https://..." />
                            <Button type="button" variant="ghost" size="icon" @click="removeUrl(i)" :disabled="editForm.urls.length <= 1">
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                    <Button type="button" variant="outline" size="sm" class="mt-2" @click="addUrl">
                        <Plus class="mr-1 h-4 w-4" /> Add URL
                    </Button>
                </div>

                <div>
                    <label class="text-sm font-medium">Category</label>
                    <select v-model="editForm.category_id" class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                        <option :value="null">No category</option>
                        <option v-for="category in props.categories" :key="category.id" :value="category.id">
                            {{ category.name }}
                        </option>
                    </select>
                </div>



                <div class="flex gap-2">
                    <Button type="submit" :disabled="editForm.processing">Update</Button>
                    <Button type="button" variant="outline" @click="showForm = false">Cancel</Button>
                </div>
            </form>
        </div>

        <div class="flex flex-wrap gap-2 mb-4">
            <Badge 
                v-if="selectedCategoryId !== null"
                variant="destructive"
                class="cursor-pointer hover:opacity-80"
                @click="selectedCategoryId = null"
            >
                Clear filter
            </Badge>
            <Badge 
                v-for="category in props.categories" 
                :key="category.id"
                :variant="selectedCategoryId === category.id ? 'default' : 'secondary'"
                class="cursor-pointer hover:opacity-80"
                @click="filterByCategory(category.id)"
            >
                {{ category.name }}
            </Badge>
        </div>

        <div class="space-y-3">
            <div v-for="link in filteredLinks" :key="link.id" class="flex items-center justify-between rounded-lg border p-4">
                <div class="flex items-center gap-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                        <LinkIcon class="h-5 w-5" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <a 
                                v-if="link.urls && link.urls.length > 0" 
                                :href="formatUrl(link.urls[0])" 
                                target="_blank" 
                                class="font-medium text-blue-500 hover:underline"
                            >
                                {{ link.name }}
                            </a>
                            <span v-else class="font-medium">{{ link.name }}</span>
                            <Badge variant="secondary">{{ link.urls?.length || 0 }} mirrors</Badge>
                            <Badge 
                                v-if="link.category"
                                :variant="selectedCategoryId === link.category.id ? 'default' : 'outline'"
                                class="cursor-pointer hover:opacity-80"
                                @click.stop="filterByCategory(link.category!.id)"
                            >
                                {{ link.category.name }}
                            </Badge>
                            <a 
                                v-for="(url, idx) in link.urls?.slice(1)" 
                                :key="idx"
                                :href="formatUrl(url)" 
                                target="_blank" 
                                class="text-blue-500 hover:underline text-sm"
                            >
                                [{{ idx + 1 }}]
                            </a>
                        </div>
                    </div>
                </div>

                <TooltipProvider>
                    <div class="flex items-center gap-2">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button variant="ghost" size="sm" @click="openEdit(link)">
                                    <Edit class="h-4 w-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent><p>Edit</p></TooltipContent>
                        </Tooltip>

                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button variant="ghost" size="sm" @click="deleteLink(link)">
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent><p>Delete</p></TooltipContent>
                        </Tooltip>
                    </div>
                </TooltipProvider>
            </div>

            <p v-if="props.links.length === 0" class="py-8 text-center text-muted-foreground">
                No links yet. Add one to get started.
            </p>
        </div>
    </div>
</template>