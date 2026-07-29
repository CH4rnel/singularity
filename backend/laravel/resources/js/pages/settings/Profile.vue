<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit, show as profileShow } from '@/routes/profile';
import { send } from '@/routes/verification';

type Props = {
    mustVerifyEmail: boolean;
    status?: string;
    error?: string;
};

defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile information"
            description="Update your name and email address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    :readonly="!!user.onchain_nickname"
                    autocomplete="name"
                    placeholder="Full name"
                />
                <p
                    v-if="user.onchain_nickname"
                    class="text-xs text-muted-foreground"
                >
                    Your public name is the on-chain nickname
                    <span class="font-mono text-foreground">
                        @{{ user.onchain_nickname }}</span
                    >.
                    <Link
                        :href="profileShow()"
                        class="text-foreground underline underline-offset-4"
                    >
                        Change it on your profile.
                    </Link>
                </p>
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div v-if="mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    Your email address is unverified.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        Click here to resend the verification email.
                    </Link>
                </p>

                <div
                    v-if="status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-show="recentlySuccessful"
                        class="text-sm text-neutral-600"
                    >
                        Saved.
                    </p>
                </Transition>
            </div>
        </Form>
    </div>

    <div class="mt-10 flex flex-col space-y-6">
        <Heading
            variant="small"
            title="X (Twitter) account"
            description="Link your X account to sign in with one click"
        />

        <p
            v-if="status === 'X account linked.'"
            class="text-sm font-medium text-green-600"
        >
            X account linked.
        </p>
        <p v-if="error" class="text-sm font-medium text-red-600">
            {{ error }}
        </p>

        <p
            v-if="user.twitter_id"
            class="text-sm text-muted-foreground"
        >
            Linked to
            <span class="font-medium text-foreground">
                @{{ user.twitter_username ?? user.twitter_id }}
            </span>
        </p>
        <div v-else>
            <!-- Plain navigation: the OAuth redirect must be a full page
                 load, not an Inertia visit. -->
            <Button variant="outline" as-child>
                <a href="/auth/twitter">
                    <svg
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                        class="h-4 w-4 fill-current"
                    >
                        <path
                            d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"
                        />
                    </svg>
                    Link X account
                </a>
            </Button>
        </div>
    </div>

    <DeleteUser />
</template>
