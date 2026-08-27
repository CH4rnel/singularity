<script lang="ts">
export type ContactWay = { kind: string; label: string; url: string };
</script>

<script setup lang="ts">
defineProps<{
    ways: ContactWay[];
    label: string;
}>();

const glyph: Record<string, string> = {
    telegram: '➤',
    x: '𝕏',
    discord: '◉',
    github: '⌘',
    linkedin: 'in',
    instagram: '◎',
    facebook: 'f',
    whatsapp: '◔',
    signal: '◌',
    email: '@',
    phone: '☎',
    link: '↗',
};
</script>

<template>
    <details class="contact-ways" @click.stop>
        <summary class="mk-btn mk-act contact-ways__summary">
            {{ label }}
            <span class="contact-ways__count">{{ ways.length }}</span>
        </summary>
        <div class="contact-ways__menu" role="group" :aria-label="label">
            <a
                v-for="way in ways"
                :key="way.url"
                :href="way.url"
                target="_blank"
                rel="noreferrer"
                class="contact-ways__link"
                :title="way.label"
                :aria-label="way.label"
                @click.stop
            >
                <span :class="`contact-ways__glyph contact-ways__glyph--${way.kind}`">
                    {{ glyph[way.kind] ?? glyph.link }}
                </span>
            </a>
        </div>
    </details>
</template>

<style scoped>
.contact-ways {
    position: relative;
    width: 108px;
}

.contact-ways__summary {
    width: 100%;
    list-style: none;
    cursor: pointer;
}

.contact-ways__summary::-webkit-details-marker {
    display: none;
}

.contact-ways__count {
    margin-left: 6px;
    color: var(--mk-bg);
    font-family: var(--mk-mono);
    font-size: 9px;
    opacity: 0.62;
}

.contact-ways__menu {
    position: absolute;
    z-index: 30;
    right: 0;
    top: calc(100% - 1px);
    display: grid;
    grid-template-columns: repeat(4, 28px);
    gap: 4px;
    width: max-content;
    padding: 8px 6px 6px;
    border: 1px solid rgba(232, 236, 236, 0.18);
    background: color-mix(in srgb, var(--mk-panel) 96%, transparent);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.42);
    opacity: 0;
    pointer-events: none;
    transform: translateY(-3px);
    transition: opacity 120ms ease, transform 120ms ease;
}

.contact-ways[open] .contact-ways__menu,
.contact-ways:hover .contact-ways__menu,
.contact-ways:focus-within .contact-ways__menu {
    opacity: 1;
    pointer-events: auto;
    transform: translateY(0);
}

.contact-ways__link {
    display: grid;
    width: 28px;
    height: 28px;
    place-items: center;
    border: 1px solid rgba(232, 236, 236, 0.12);
    color: var(--mk-dim);
    background: rgba(232, 236, 236, 0.035);
    text-decoration: none;
    transition: border-color 100ms ease, color 100ms ease, background 100ms ease;
}

.contact-ways__link:hover,
.contact-ways__link:focus-visible {
    border-color: color-mix(in srgb, var(--mk-accent) 55%, transparent);
    color: var(--mk-accent);
    background: color-mix(in srgb, var(--mk-accent) 10%, transparent);
    outline: none;
}

.contact-ways__glyph {
    font-family: var(--mk-mono);
    font-size: 13px;
    font-weight: 700;
    line-height: 1;
}

.contact-ways__glyph--linkedin,
.contact-ways__glyph--facebook {
    font-family: Arial, sans-serif;
    font-size: 12px;
}

@media (max-width: 760px) {
    .contact-ways__menu {
        right: auto;
        left: 0;
    }
}
</style>
