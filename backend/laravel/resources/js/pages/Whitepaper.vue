<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink, FileDown } from 'lucide-vue-next';
import PageHero from '@/components/web3/PageHero.vue';

type Status = 'live' | 'progress' | 'planned' | 'vision';

type Reference = {
    title: string;
    href: string;
    external?: boolean;
};

type Phase = {
    numeral: string;
    title: string;
    status: Status;
    focus: string;
    outcome: string;
    summary: string;
    done: string[];
    next: string[];
    line: string;
    links: Reference[];
    window: { from: string; to: string | null } | 'after' | null;
};

type Milestone = { date: string; phase: string; title: string };

defineProps<{
    thesis: string;
    pillars: { title: string; body: string }[];
    phases: Phase[];
    references: Reference[];
    document: { href: string; date: string };
    reviewedAt: string;
    tally: Record<Status, number>;
    horizon: { date: string; title: string; body: string; href: string };
    milestones: Milestone[];
}>();

/**
 * One vocabulary for a phase's state, used by the table, the cards and the
 * counters alike — the three of them disagreeing about what "in progress"
 * looks like is how a roadmap stops being read as one document.
 *
 * `vision` is deliberately not a fifth shade of grey: it is the one row that
 * describes where this is all going rather than a piece of work with an
 * owner, and drawing it like a planned task would promise a date.
 */
const STATUS: Record<Status, { label: string; dot: string; chip: string }> = {
    live: {
        label: 'Live',
        dot: 'bg-brand-cyan',
        chip: 'bg-brand-cyan/10 text-brand-cyan',
    },
    progress: {
        label: 'In progress',
        dot: 'bg-brand-magenta',
        chip: 'bg-brand-magenta/10 text-brand-magenta',
    },
    planned: {
        label: 'Planned',
        dot: 'bg-muted-foreground/50',
        chip: 'bg-muted text-muted-foreground',
    },
    vision: {
        label: 'Long-term',
        dot: 'bg-transparent ring-1 ring-muted-foreground/50',
        chip: 'bg-transparent text-muted-foreground ring-1 ring-border',
    },
};

/** The date is printed, not implied: an undated roadmap is read as current forever. */
const readable = (iso: string): string =>
    new Date(`${iso}T00:00:00Z`).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        timeZone: 'UTC',
    });

const anchor = (numeral: string): string => `phase-${numeral.toLowerCase()}`;

const month = (ym: string): string =>
    new Date(`${ym}-01T00:00:00Z`).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });

/** "Nov 2026 – Feb 2027", or one month when the window is a single one. */
const span = (window: Phase['window']): string =>
    window === null
        ? '—'
        : window === 'after'
          ? 'After the unlock'
          : window.to === null
            ? `${month(window.from)} – after the unlock`
            : window.from === window.to
              ? month(window.from)
              : `${month(window.from)} – ${month(window.to)}`;

/** Read once per render: a milestone is past or not by today's date, not by a flag in a file. */
const today = new Date().toISOString().slice(0, 10);
</script>

<template>
    <Head title="Whitepaper" />

    <div class="mx-auto max-w-5xl space-y-14 p-6">
        <PageHero
            eyebrow="The document"
            title="Whitepaper"
            :description="thesis"
        >
            <template #actions>
                <a
                    :href="document.href"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1 rounded border border-border px-3 py-1.5 text-sm transition hover:border-input"
                >
                    Original document <FileDown class="h-3.5 w-3.5" />
                </a>
                <a
                    href="https://docs.cyberia.church"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1 rounded border border-border px-3 py-1.5 text-sm transition hover:border-input"
                >
                    Documentation <ExternalLink class="h-3.5 w-3.5" />
                </a>
            </template>
        </PageHero>

        <!-- The counters are derived from the phase list below, so the headline
             and the table cannot disagree about how much is shipped. -->
        <section
            class="grid grid-cols-2 gap-px overflow-hidden rounded border border-border bg-border sm:grid-cols-4"
        >
            <div
                v-for="(count, status) in tally"
                :key="status"
                class="bg-background p-4"
            >
                <p
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <span
                        class="h-2 w-2 rounded-full"
                        :class="STATUS[status as Status].dot"
                    />
                    {{ STATUS[status as Status].label }}
                </p>
                <p class="mt-1 font-mono text-lg font-semibold">{{ count }}</p>
                <p class="text-xs text-muted-foreground">
                    {{ count === 1 ? 'phase' : 'phases' }}
                </p>
            </div>
        </section>

        <section class="space-y-4">
            <h2 class="text-xl font-bold">What this is</h2>
            <p class="max-w-2xl text-sm text-muted-foreground">
                Cyberia is a live EVM-compatible L1, an open-source software
                project, and a community organised around one belief: that
                open-source work should be economically visible, verifiable and
                rewardable on chain. The roadmap below starts with what is
                already running and ends with what that is for.
            </p>

            <div class="grid gap-3 sm:grid-cols-3">
                <div
                    v-for="pillar in pillars"
                    :key="pillar.title"
                    class="rounded border border-border p-4"
                >
                    <h3 class="font-semibold">{{ pillar.title }}</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ pillar.body }}
                    </p>
                </div>
            </div>
        </section>

        <!-- The whole roadmap on one screen, before any of it is read in full.
             Each row jumps to its phase rather than repeating it. -->
        <section class="space-y-4">
            <h2 class="text-xl font-bold">The roadmap at a glance</h2>

            <div class="overflow-x-auto rounded border border-border">
                <table class="w-full min-w-[40rem] text-sm">
                    <thead
                        class="border-b border-border text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">
                                Phase
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                Focus
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                Outcome
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                When
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                State
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="phase in phases"
                            :key="phase.numeral"
                            class="border-b border-border/60 last:border-0"
                        >
                            <td class="px-4 py-2 align-top">
                                <a
                                    :href="`#${anchor(phase.numeral)}`"
                                    class="font-mono text-xs text-brand-cyan hover:underline"
                                >
                                    {{ phase.numeral }}
                                </a>
                            </td>
                            <td
                                class="px-4 py-2 align-top text-muted-foreground"
                            >
                                {{ phase.focus }}
                            </td>
                            <td
                                class="px-4 py-2 align-top text-muted-foreground"
                            >
                                {{ phase.outcome }}
                            </td>
                            <td
                                class="px-4 py-2 align-top font-mono text-xs whitespace-nowrap text-muted-foreground"
                            >
                                {{ span(phase.window) }}
                            </td>
                            <td class="px-4 py-2 align-top">
                                <span
                                    class="rounded px-1.5 py-0.5 text-xs whitespace-nowrap"
                                    :class="STATUS[phase.status].chip"
                                >
                                    {{ STATUS[phase.status].label }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- The dated half of the roadmap. Every row is due on or before the
             unlock, and the unlock itself is a link to the lock, so the one
             date the rest hangs on can be checked rather than believed. -->
        <section class="space-y-4">
            <h2 class="text-xl font-bold">Milestones to the unlock</h2>
            <p class="max-w-2xl text-sm text-muted-foreground">
                {{ horizon.body }}
            </p>

            <ol class="rounded border border-border">
                <li
                    v-for="milestone in milestones"
                    :key="`${milestone.date}-${milestone.title}`"
                    class="flex gap-4 border-b border-border/60 px-4 py-2 text-sm last:border-0"
                    :class="
                        milestone.date < today ? 'text-muted-foreground' : ''
                    "
                >
                    <span
                        class="w-24 shrink-0 font-mono text-xs leading-5 text-muted-foreground"
                    >
                        {{ milestone.date }}
                    </span>
                    <a
                        :href="`#${anchor(milestone.phase)}`"
                        class="w-10 shrink-0 font-mono text-xs leading-5 text-brand-cyan hover:underline"
                    >
                        {{ milestone.phase }}
                    </a>
                    <span>{{ milestone.title }}</span>
                </li>
                <li
                    class="flex gap-4 bg-brand-magenta/5 px-4 py-2 text-sm font-semibold"
                >
                    <span class="w-24 shrink-0 font-mono text-xs leading-5">
                        {{ horizon.date }}
                    </span>
                    <span class="w-10 shrink-0" />
                    <a
                        :href="horizon.href"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 hover:underline"
                    >
                        {{ horizon.title }}
                        <ExternalLink class="h-3.5 w-3.5" />
                    </a>
                </li>
            </ol>
        </section>

        <!-- Every phase that claims to be finished carries the address of the
             thing that finished it. A "completed" with nothing to click is a
             promise, not a receipt. -->
        <section class="space-y-4">
            <h2 class="text-xl font-bold">The phases in full</h2>

            <article
                v-for="phase in phases"
                :id="anchor(phase.numeral)"
                :key="phase.numeral"
                class="scroll-mt-24 rounded border border-border p-5"
            >
                <div class="flex flex-wrap items-baseline gap-2">
                    <span class="font-mono text-xs text-muted-foreground">
                        Phase {{ phase.numeral }}
                    </span>
                    <span
                        class="rounded px-1.5 py-0.5 text-xs"
                        :class="STATUS[phase.status].chip"
                    >
                        {{ STATUS[phase.status].label }}
                    </span>
                    <span
                        v-if="phase.window"
                        class="font-mono text-xs text-muted-foreground"
                    >
                        {{ span(phase.window) }}
                    </span>
                </div>

                <h3 class="mt-1 font-semibold">{{ phase.title }}</h3>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ phase.summary }}
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div v-if="phase.done.length">
                        <p class="text-xs text-muted-foreground uppercase">
                            Standing
                        </p>
                        <ul class="mt-1 space-y-1 text-sm">
                            <li
                                v-for="item in phase.done"
                                :key="item"
                                class="flex gap-2"
                            >
                                <span
                                    class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-cyan"
                                />
                                <span>{{ item }}</span>
                            </li>
                        </ul>
                    </div>
                    <div v-if="phase.next.length">
                        <p class="text-xs text-muted-foreground uppercase">
                            Next
                        </p>
                        <ul
                            class="mt-1 space-y-1 text-sm text-muted-foreground"
                        >
                            <li
                                v-for="item in phase.next"
                                :key="item"
                                class="flex gap-2"
                            >
                                <span
                                    class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-muted-foreground/40"
                                />
                                <span>{{ item }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <p
                    class="mt-4 border-l-2 border-border pl-3 text-sm text-muted-foreground italic"
                >
                    {{ phase.line }}
                </p>

                <div
                    v-if="phase.links.length"
                    class="mt-3 flex flex-wrap gap-3 text-xs"
                >
                    <template v-for="link in phase.links" :key="link.href">
                        <a
                            v-if="link.external"
                            :href="link.href"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 text-brand-cyan hover:underline"
                        >
                            {{ link.title }}
                            <ExternalLink class="h-3 w-3" />
                        </a>
                        <Link
                            v-else
                            :href="link.href"
                            class="text-brand-cyan hover:underline"
                        >
                            {{ link.title }}
                        </Link>
                    </template>
                </div>
            </article>
        </section>

        <section class="space-y-4">
            <h2 class="text-xl font-bold">Check it</h2>
            <p class="max-w-2xl text-sm text-muted-foreground">
                Everything marked live has an address. The chain is in a block
                explorer, the contracts are in the repository, and the figures
                quoted anywhere on this site are read from the pool graph rather
                than typed into a page.
            </p>
            <div class="flex flex-wrap gap-3 text-sm">
                <template v-for="reference in references" :key="reference.href">
                    <a
                        v-if="reference.external"
                        :href="reference.href"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 rounded border border-border px-3 py-1.5 transition hover:border-input"
                    >
                        {{ reference.title }}
                        <ExternalLink class="h-3.5 w-3.5" />
                    </a>
                    <Link
                        v-else
                        :href="reference.href"
                        class="rounded border border-border px-3 py-1.5 transition hover:border-input"
                    >
                        {{ reference.title }}
                    </Link>
                </template>
            </div>
        </section>

        <!-- The date is the point of this block. A roadmap with no date on it
             is read as current forever, and this one will not be. -->
        <section class="rounded border border-border p-5">
            <h2 class="font-semibold">About this document</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                This page is the Cyberia roadmap of
                {{ readable(document.date) }}, with the state of each phase
                reviewed on {{ readable(reviewedAt) }}. The original file is
                still downloadable — a roadmap that only exists as HTML on our
                own server is one you cannot keep a copy of.
            </p>
            <p class="mt-2 text-sm text-muted-foreground">
                The months on each phase and the milestone dates are targets set
                on {{ readable(reviewedAt) }}, not receipts. A date that moves
                is changed on this page in the open, and a phase marked planned
                is work that has not started.
            </p>
            <a
                :href="document.href"
                target="_blank"
                rel="noopener"
                class="mt-3 inline-flex items-center gap-1 text-xs text-brand-cyan hover:underline"
            >
                Download the original (.docx) <FileDown class="h-3 w-3" />
            </a>
        </section>
    </div>
</template>
