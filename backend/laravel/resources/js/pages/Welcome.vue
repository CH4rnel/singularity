<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard, login, register } from '@/routes';

withDefaults(
    defineProps<{
        canRegister: boolean;
        yesOrNo: {
            answer: string;
        },
        cataas: {
            cat: string;
            catById: string;
            catByIdSays: string;
            catByTag: string;
            catByTagSays: string;
            catSays: string;
            cats: Array<any>;
            count: null;
            tags: Array<string>
        }
    }>(),
    {
        canRegister: true,
    },
);

const page = usePage();
const dashboardUrl = computed(() => {
    console.log(page.props)
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/'
});
</script>

<template>
    <Head title="Welcome">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>
    <div
        class="flex min-h-screen flex-col items-center bg-[#FDFDFC] p-6 text-[#1b1b18] lg:justify-center lg:p-8 dark:bg-[#0a0a0a]"
    >
        <header
            class="mb-6 w-full max-w-[335px] text-sm not-has-[nav]:hidden lg:max-w-4xl"
        >
            <nav class="flex items-center justify-end gap-4">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboardUrl"
                    class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="inline-block rounded-sm border border-transparent px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#19140035] dark:text-[#EDEDEC] dark:hover:border-[#3E3E3A]"
                    >
                        Log in
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="register()"
                        class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                    >
                        Register
                    </Link>
                </template>
            </nav>
        </header>
        <div
            class="w-full items-center justify-center opacity-100 transition-opacity duration-750 lg:grow starting:opacity-0 text-white"
        >
            <p>yesno.answer: {{ yesOrNo.answer }}</p>
            <p><img :src="cataas.cat" alt="cat" /></p>
            <p><img :src="cataas.catSays" alt="cat" /></p>
            <p><img :src="cataas.catById" alt="cat" /></p>
            <p><img :src="cataas.catByIdSays" alt="cat" /></p>
            <p><img :src="cataas.catByTag" alt="cat" /></p>
            <p><img :src="cataas.catByTagSays" alt="cat" /></p>
        </div>
        <div class="hidden h-14.5 lg:block"></div>
    </div>
</template>
