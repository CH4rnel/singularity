<script setup lang="ts">
import { ExternalLink } from 'lucide-vue-next';
import { onMounted, ref, watch } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { relativeTime, shortAddress } from '@/lib/wallet/format';
import { fetchFeed } from '@/lib/wallet/social';
import type { FeedItem } from '@/lib/wallet/social';
import { walletMessages } from '@/lib/walletMessages';

/**
 * What is happening across Cyberia, as one column.
 *
 * Two sources, because that is what exists: posts people wrote and activity the
 * DAO recorded. They are merged server-side into one stream so this screen does
 * not have to page two lists against each other.
 *
 * It is read-only, and that is the wallet being honest rather than unfinished:
 * there is no session here — the seed is in this browser and the server never
 * learns whose it is — so there is nobody to post or reply as. Anything that
 * needs an account opens on the site.
 */

const emit = defineEmits<{
    profile: [address: string];
}>();

const { locale, t } = useLocale(walletMessages);

type Tab = 'all' | 'posts' | 'dao';

const TABS: Tab[] = ['all', 'posts', 'dao'];

const tab = ref<Tab>('all');
const items = ref<FeedItem[]>([]);
const loading = ref(true);
const failure = ref(false);

/**
 * The activity keys the DAO records, said in the reader's language. An
 * unknown key falls back to itself rather than to nothing — a new activity
 * type should read oddly, not vanish.
 */
const ACTIVITY: Record<string, string> = {
    'proposal.created': 'feedProposalCreated',
    'vote.cast': 'feedVoteCast',
    'comment.posted': 'feedCommentPosted',
};

const describe = (item: FeedItem): string =>
    item.type ? (ACTIVITY[item.type] ? t(ACTIVITY[item.type]) : item.type) : '';

const load = async (): Promise<void> => {
    loading.value = true;
    failure.value = false;

    try {
        items.value = await fetchFeed(tab.value);
    } catch {
        failure.value = true;
        items.value = [];
    } finally {
        loading.value = false;
    }
};

watch(tab, load);
onMounted(load);
</script>

<template>
    <div class="cw-stack">
        <div
            style="
                display: flex;
                align-items: baseline;
                justify-content: space-between;
                gap: 12px;
            "
        >
            <h2 class="cw-title" style="margin: 0">{{ t('feed') }}</h2>
            <a
                class="cw-back"
                href="/feed"
                target="_blank"
                rel="noopener noreferrer"
                style="text-decoration: none"
            >
                {{ t('feedOpenSite') }}
                <ExternalLink :size="12" aria-hidden="true" />
            </a>
        </div>
        <p class="cw-prose" style="margin-top: 8px">{{ t('feedBody') }}</p>

        <div class="cw-seg" style="margin-top: 18px">
            <button
                v-for="entry in TABS"
                :key="entry"
                type="button"
                class="cw-seg-item"
                :aria-pressed="tab === entry"
                @click="tab = entry"
            >
                {{
                    t(
                        entry === 'all'
                            ? 'feedTabAll'
                            : entry === 'posts'
                              ? 'feedTabPosts'
                              : 'feedTabDao',
                    )
                }}
            </button>
        </div>

        <p v-if="failure" class="cw-note cw-note-bad" style="margin-top: 18px">
            <span style="flex: 1">{{ t('feedUnreadable') }}</span>
            <button type="button" class="cw-back" @click="load">
                {{ t('retry') }}
            </button>
        </p>

        <p
            v-else-if="loading"
            class="cw-label"
            style="margin-top: 18px; color: var(--cw-faint)"
        >
            {{ t('feedLoading') }}
        </p>

        <p
            v-else-if="items.length === 0"
            class="cw-prose"
            style="margin-top: 18px"
        >
            {{ t('feedEmpty') }}
        </p>

        <div v-else class="cw-stack" style="gap: 12px; margin-top: 18px">
            <article
                v-for="item in items"
                :key="item.id"
                class="cw-card"
                style="padding: 16px"
            >
                <div
                    style="
                        display: flex;
                        align-items: center;
                        gap: 11px;
                        margin-bottom: 12px;
                    "
                >
                    <span
                        style="
                            display: flex;
                            width: 30px;
                            height: 30px;
                            flex: none;
                            align-items: center;
                            justify-content: center;
                            border: 1px solid var(--cw-border-soft);
                            font: 500 10px/1 var(--cw-mono);
                            color: var(--cw-muted);
                        "
                        >{{
                            (item.who?.name ?? '??').slice(0, 2).toUpperCase()
                        }}</span
                    >
                    <div style="flex: 1; min-width: 0">
                        <div
                            style="
                                font: 500 13px/1.2 var(--cw-sans);
                                color: var(--cw-text);
                            "
                        >
                            {{ item.who?.name ?? t('feedSomeone') }}
                        </div>
                        <div
                            class="cw-data"
                            style="
                                margin-top: 3px;
                                font-size: 9px;
                                color: var(--cw-muted);
                            "
                        >
                            <!--
                              An address is the only identity the wallet shares
                              with the feed, so it is also the only handle worth
                              offering as a link to a profile.
                            -->
                            <button
                                v-if="item.who?.address"
                                type="button"
                                class="cw-back"
                                style="
                                    display: inline;
                                    padding: 0;
                                    min-height: 0;
                                    font-size: 9px;
                                "
                                @click="emit('profile', item.who.address)"
                            >
                                {{ shortAddress(item.who.address) }}
                            </button>
                            <span v-else>—</span>
                            ·
                            {{
                                relativeTime(
                                    item.at
                                        ? Math.round(Date.parse(item.at) / 1000)
                                        : null,
                                    locale,
                                )
                            }}
                        </div>
                    </div>
                    <span
                        class="cw-label"
                        style="
                            border: 1px solid var(--cw-hairline);
                            padding: 4px 6px;
                            color: var(--cw-muted);
                        "
                        >{{
                            item.kind === 'dao'
                                ? t('feedTagDao')
                                : t('feedTagPost')
                        }}</span
                    >
                </div>

                <p
                    v-if="item.kind === 'dao'"
                    style="
                        margin: 0;
                        font: 400 13px/1.6 var(--cw-sans);
                        color: var(--cw-body);
                    "
                >
                    <span style="color: var(--cw-accent)">{{
                        describe(item)
                    }}</span>
                    <template v-if="item.text"> — {{ item.text }}</template>
                </p>
                <p
                    v-else
                    style="
                        margin: 0;
                        white-space: pre-wrap;
                        font: 400 13px/1.6 var(--cw-sans);
                        color: var(--cw-body);
                    "
                >
                    {{ item.text }}
                </p>

                <div
                    style="
                        display: flex;
                        align-items: center;
                        gap: 12px;
                        margin-top: 14px;
                        padding-top: 12px;
                        border-top: 1px solid var(--cw-line);
                    "
                >
                    <span v-if="item.meta" class="cw-label">{{
                        item.meta
                    }}</span>
                    <span class="cw-fill"></span>
                    <a
                        class="cw-back"
                        :href="item.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        style="text-decoration: none"
                    >
                        {{ t('feedOpen') }}
                        <ExternalLink :size="12" aria-hidden="true" />
                    </a>
                </div>
            </article>
        </div>

        <p class="cw-note" style="margin-top: 20px">
            <span>{{ t('feedReadOnly') }}</span>
        </p>
    </div>
</template>
