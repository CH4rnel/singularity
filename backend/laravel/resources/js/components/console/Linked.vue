<script setup lang="ts">
import { computed } from 'vue';
import { linkify } from '@/lib/console';

/**
 * A written line, with the addresses in it clickable.
 *
 * Everything an operator types into this console — a line of a conversation,
 * a task, a note, a comment — used to render as flat text, so a link somebody
 * pasted had to be selected and copied out by hand. That is the whole reason
 * this exists.
 *
 * It renders **segments**, never markup: this text was typed by a person or
 * imported from a chat, and building HTML out of it for `v-html` is the one
 * way the console could be made to run somebody else's script. Vue escapes
 * each piece, so the worst a hostile line can do is look like a link.
 */
const props = defineProps<{ text: string | null | undefined }>();

const parts = computed(() => linkify(props.text));
</script>

<template>
    <!-- Written without a break between the tags on purpose: the containers
         this sits in are `white-space: pre-wrap`, and a newline in the source
         would become a space inside somebody's sentence. -->
    <span
        ><template v-for="(part, index) in parts" :key="index"
            ><a
                v-if="part.href"
                :href="part.href"
                target="_blank"
                rel="noreferrer noopener"
                class="mk-link"
                @click.stop
                >{{ part.text }}</a
            ><template v-else>{{ part.text }}</template></template
        ></span
    >
</template>
