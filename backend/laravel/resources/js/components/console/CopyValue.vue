<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { consoleMessages } from '@/lib/consoleMessages';

/**
 * A value that is going somewhere else.
 *
 * Every address on this screen is shortened — forty hex characters tell a
 * reader nothing and cost a whole column — but shortening is exactly what
 * makes them unusable: the operator who needs `0x7f3a…9c21` needs all forty
 * of them, in an explorer or in a message, and until now the only way to get
 * them was to open the edit form and select the field by hand.
 *
 * So what is drawn and what is copied are two different strings, deliberately:
 * `display` is for the eye, `value` is what lands on the clipboard. The button
 * confirms in place rather than with a toast, because a confirmation somewhere
 * else on the page is a confirmation nobody sees while looking at the value
 * they just copied.
 */
const props = defineProps<{
    /** The full string to copy. Nothing renders a button when it is null. */
    value: string | null;
    /** What to show; the value itself when it is short enough to read. */
    display?: string | null;
    /** Rendered as a link when the value is somewhere you can go. */
    href?: string | null;
}>();

const { t } = useLocale(consoleMessages);

const copied = ref(false);
let clearing: ReturnType<typeof setTimeout> | null = null;

/**
 * `navigator.clipboard` needs a secure context, which this console has in
 * production and over the loopback address, and does not have if it is ever
 * opened over plain http on the LAN. The old `execCommand` path is kept for
 * exactly that case: a copy button that silently does nothing is worse than
 * no copy button.
 */
async function copy() {
    if (!props.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.value);
    } catch {
        const field = document.createElement('textarea');

        field.value = props.value;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();

        try {
            document.execCommand('copy');
        } finally {
            field.remove();
        }
    }

    copied.value = true;

    if (clearing !== null) {
        clearTimeout(clearing);
    }

    clearing = setTimeout(() => (copied.value = false), 1400);
}

onBeforeUnmount(() => {
    if (clearing !== null) {
        clearTimeout(clearing);
    }
});
</script>

<template>
    <span
        style="
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 7px;
            min-width: 0;
            max-width: 100%;
        "
    >
        <!-- A long value gives way to the ellipsis rather than pushing the
             label off its own row — the whole string is on the clipboard a
             click away, which is what the button is for. -->
        <a
            v-if="href"
            :href="href"
            target="_blank"
            rel="noreferrer"
            class="mk-m mk-clip"
            style="
                min-width: 0;
                font-size: 12px;
                color: var(--mk-accent);
                text-decoration: none;
            "
            >{{ display ?? value }}</a
        >
        <span
            v-else
            class="mk-m mk-clip"
            style="min-width: 0; font-size: 12px; color: var(--mk-body)"
            >{{ display ?? value }}</span
        >

        <button
            v-if="value"
            type="button"
            class="mk-copy"
            :class="{ 'mk-copied': copied }"
            :title="copied ? t('action.copied') : t('action.copy')"
            :aria-label="copied ? t('action.copied') : t('action.copy')"
            @click.stop.prevent="copy"
        >
            <!-- Two sheets, then a tick. The icon is the whole button: a word
                 beside every address would be seven words in a panel of
                 seven rows. -->
            <svg
                width="13"
                height="13"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path v-if="copied" d="M5 12.5l4.5 4.5L19 7" />
                <template v-else>
                    <rect x="9" y="9" width="11" height="11" rx="1.6" />
                    <path
                        d="M5.5 15H4.6A1.6 1.6 0 0 1 3 13.4V4.6A1.6 1.6 0 0 1 4.6 3h8.8A1.6 1.6 0 0 1 15 4.6v.9"
                    />
                </template>
            </svg>
        </button>
    </span>
</template>
