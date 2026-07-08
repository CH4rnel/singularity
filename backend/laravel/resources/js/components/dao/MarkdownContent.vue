<script setup lang="ts">
defineProps<{
    html: string | null | undefined;
    compact?: boolean;
}>();
</script>

<template>
    <div
        v-if="html"
        class="markdown-content text-sm leading-relaxed"
        :class="compact ? 'markdown-content--compact' : ''"
        v-html="html"
    />
</template>

<style scoped>
.markdown-content {
    overflow-wrap: anywhere;
}

.markdown-content :deep(*) {
    margin-top: 0;
}

.markdown-content :deep(* + *) {
    margin-top: 0.75rem;
}

.markdown-content :deep(h1),
.markdown-content :deep(h2),
.markdown-content :deep(h3) {
    color: var(--foreground);
    font-weight: 700;
    line-height: 1.2;
}

.markdown-content :deep(h1) {
    font-size: 1.25rem;
}

.markdown-content :deep(h2) {
    font-size: 1.125rem;
}

.markdown-content :deep(h3) {
    font-size: 1rem;
}

.markdown-content :deep(p),
.markdown-content :deep(li),
.markdown-content :deep(blockquote) {
    color: var(--muted-foreground);
}

.markdown-content :deep(a) {
    color: var(--brand-cyan);
    text-decoration: underline;
    text-underline-offset: 3px;
}

.markdown-content :deep(ul),
.markdown-content :deep(ol) {
    padding-left: 1.25rem;
}

.markdown-content :deep(ul) {
    list-style-type: disc;
}

.markdown-content :deep(ol) {
    list-style-type: decimal;
}

.markdown-content :deep(blockquote) {
    border-left: 2px solid var(--border);
    padding-left: 0.75rem;
}

.markdown-content :deep(code) {
    border-radius: 0.25rem;
    background: var(--muted);
    padding: 0.1rem 0.3rem;
    color: var(--foreground);
    font-size: 0.875em;
}

.markdown-content :deep(pre) {
    overflow-x: auto;
    border-radius: 0.5rem;
    background: var(--muted);
    padding: 0.75rem;
}

.markdown-content :deep(pre code) {
    background: transparent;
    padding: 0;
}

.markdown-content--compact :deep(* + *) {
    margin-top: 0.35rem;
}

.markdown-content--compact :deep(h1),
.markdown-content--compact :deep(h2),
.markdown-content--compact :deep(h3) {
    font-size: 0.875rem;
}
</style>
