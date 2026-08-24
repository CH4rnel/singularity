<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Rule from '@/components/console/Rule.vue';
import { useConsoleLive } from '@/composables/useConsolePulse';
import { useLocale } from '@/composables/useLocale';
import { dateTime, num, plural, toneColor } from '@/lib/console';
import { consoleMessages } from '@/lib/consoleMessages';
import chat from '@/routes/crm/chat';

/**
 * "Файлы" — the room read as the pile it collected.
 *
 * Not a second store: every row here is an attachment of a message, which is
 * why the table can print what each file was for. A folder is a place where a
 * file is put silently, and a month later it holds five files named
 * final2.log that nobody can account for.
 *
 * The segments are the same idea as in "Люди": a saved question with its rule
 * visible, rather than a set of checkboxes somebody re-ticks every time.
 */
type FileRow = {
    id: number;
    messageId: number;
    name: string;
    ext: string;
    kind: string;
    size: number;
    by: string;
    at: string | null;
    reason: string | null;
};

const props = defineProps<{
    segment: string;
    segments: Array<{ key: string; count: number; tone: string }>;
    files: FileRow[];
    total: { files: number; bytes: number; segmentBytes: number };
    limits: {
        maxMb: number;
        maxFiles: number;
        maxChars: number;
        retentionDays: number;
        contextMessages: number;
    };
}>();

const { locale, t, tag } = useLocale(consoleMessages);

const dragging = ref(false);
const sending = ref(false);

/* A file somebody else dropped into the room belongs on this pile at once. */
useConsoleLive('files', () =>
    router.reload({
        only: ['segments', 'files', 'total', 'console'],
    }),
);

const title = computed(() => t(`chat.f.seg.${props.segment}`));
const current = computed(() =>
    props.segments.find((segment) => segment.key === props.segment),
);

function size(bytes: number): string {
    if (bytes >= 1_073_741_824) {
        return `${num(bytes / 1_073_741_824, 1)} ${t('unit.gb')}`;
    }

    if (bytes >= 1_048_576) {
        return `${num(bytes / 1_048_576, 1)} ${t('unit.mb')}`;
    }

    return `${num(Math.max(1, Math.round(bytes / 1024)))} ${t('unit.kb')}`;
}

/**
 * A file dropped here still arrives as a message, and the room is where it
 * can be captioned — so that is where this hands over.
 */
function upload(chosen: File[]): void {
    if (sending.value || chosen.length === 0) {
        return;
    }

    sending.value = true;

    router.post(
        chat.store.url(),
        { body: '', files: chosen.slice(0, props.limits.maxFiles) },
        {
            forceFormData: true,
            onSuccess: () => router.visit(chat.index.url()),
            onFinish: () => {
                sending.value = false;
            },
        },
    );
}

function pick(event: Event): void {
    const chosen = (event.target as HTMLInputElement).files;

    if (chosen) {
        upload([...chosen]);
    }

    (event.target as HTMLInputElement).value = '';
}

function drop(event: DragEvent): void {
    dragging.value = false;

    if (event.dataTransfer?.files?.length) {
        upload([...event.dataTransfer.files]);
    }
}
</script>

<template>
    <Head title="Пульт · Файлы" />

    <div
        style="flex: 1; min-height: 0; display: flex; margin: -22px -24px"
        @dragover.prevent="dragging = true"
        @dragleave.self="dragging = false"
        @drop.prevent="drop"
    >
        <div
            style="
                width: 258px;
                flex: 0 0 258px;
                border-right: 1px solid var(--mk-hair);
                padding: 22px 18px;
                overflow-y: auto;
            "
        >
            <Rule :label="t('chat.f.segments')" />
            <div
                style="
                    margin-top: 12px;
                    display: flex;
                    flex-direction: column;
                    gap: 1px;
                "
            >
                <Link
                    v-for="item in segments"
                    :key="item.key"
                    :href="chat.files.url({ query: { segment: item.key } })"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 10px;
                        height: 34px;
                        padding: 0 11px;
                        font-size: 13px;
                    "
                    :style="
                        item.key === segment
                            ? {
                                  background: 'var(--mk-accent-soft)',
                                  color: 'var(--mk-text)',
                                  boxShadow: 'inset 2px 0 0 var(--mk-accent)',
                              }
                            : { color: 'var(--mk-dim)' }
                    "
                >
                    <span
                        class="mk-dot"
                        :style="{ background: toneColor(item.tone) }"
                    />
                    {{ t(`chat.f.seg.${item.key}`) }}
                    <span
                        class="mk-m mk-t3"
                        style="margin-left: auto; font-size: 11px"
                        >{{ item.count }}</span
                    >
                </Link>
            </div>

            <p
                class="mk-t3"
                style="
                    margin: 16px 11px 0;
                    font-size: 11px;
                    line-height: 1.55;
                    color: var(--mk-fainter);
                "
            >
                {{ t('chat.f.segNote') }}
            </p>
            <p
                class="mk-t3"
                style="
                    margin: 14px 11px 0;
                    font-size: 11px;
                    line-height: 1.55;
                    color: var(--mk-fainter);
                "
            >
                {{ t('chat.f.folders') }}
            </p>
        </div>

        <div
            style="
                position: relative;
                flex: 1;
                min-width: 0;
                padding: 22px 24px;
                display: flex;
                flex-direction: column;
                gap: 16px;
                overflow-y: auto;
            "
        >
            <div v-if="dragging" class="mk-drop">
                <span style="font-size: 15px; font-weight: 600">{{
                    t('chat.f.drop')
                }}</span>
                <span class="mk-t3" style="font-size: 12px">{{
                    t('chat.f.dropNote', {
                        mb: limits.maxMb,
                    })
                }}</span>
            </div>

            <div style="display: flex; align-items: baseline; gap: 12px">
                <h1 class="mk-h1">{{ title }}</h1>
                <span class="mk-m mk-t3" style="font-size: 12px">
                    {{
                        t('chat.f.subtitle', {
                            count: current?.count ?? 0,
                            files: plural(
                                locale,
                                current?.count ?? 0,
                                t('unit.file'),
                            ),
                            size: size(total.segmentBytes),
                        })
                    }}
                </span>
                <div style="margin-left: auto; display: flex; gap: 8px">
                    <Link :href="chat.index.url()" class="mk-btn mk-ghost">{{
                        t('chat.f.back')
                    }}</Link>
                </div>
            </div>

            <!-- The dump has one door, and it is the same one the room has. -->
            <label
                style="
                    display: flex;
                    align-items: center;
                    gap: 16px;
                    height: 74px;
                    padding: 0 20px;
                    border: 1px dashed rgba(0, 229, 209, 0.35);
                    background: rgba(0, 229, 209, 0.03);
                    cursor: pointer;
                "
            >
                <input type="file" multiple hidden @change="pick" />
                <svg
                    width="22"
                    height="22"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="var(--mk-accent)"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M12 16V4M7.5 8.5L12 4l4.5 4.5" />
                    <path
                        d="M3.5 15v3.5a2 2 0 0 0 2 2h13a2 2 0 0 0 2-2V15"
                    />
                </svg>
                <div>
                    <p style="margin: 0; font-size: 13.5px; font-weight: 600">
                        {{ t('chat.f.drop') }}
                    </p>
                    <p
                        class="mk-t3"
                        style="margin: 4px 0 0; font-size: 11.5px"
                    >
                        {{ t('chat.f.dropNote', { mb: limits.maxMb }) }}
                    </p>
                </div>
                <span
                    class="mk-btn mk-act"
                    style="margin-left: auto"
                    :style="sending ? { opacity: 0.5 } : {}"
                    >{{ t('chat.f.choose') }}</span
                >
            </label>

            <Rule :label="t('chat.f.what')" :note="t('chat.f.fresh')" />

            <div v-if="files.length === 0" style="padding: 26px 0">
                <p style="margin: 0; font-size: 15px; font-weight: 600">
                    {{ t('chat.f.empty') }}
                </p>
                <p class="mk-t2" style="margin: 6px 0 0; font-size: 12.5px">
                    {{ t('chat.f.emptyNote') }}
                </p>
            </div>

            <div v-else class="mk-scroll-x" style="flex: 1; min-height: 0">
                <table class="mk-table">
                    <thead>
                        <tr>
                            <th>{{ t('chat.f.kind') }}</th>
                            <th>{{ t('chat.f.name') }}</th>
                            <th>{{ t('chat.f.size') }}</th>
                            <th>{{ t('chat.f.by') }}</th>
                            <th>{{ t('chat.f.when') }}</th>
                            <th>{{ t('chat.f.why') }}</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="file in files" :key="file.id">
                            <td style="width: 62px">
                                <span class="mk-ext">{{ file.ext }}</span>
                            </td>
                            <td style="white-space: nowrap">{{ file.name }}</td>
                            <td
                                class="mk-m mk-t2"
                                style="width: 92px; font-size: 12px"
                            >
                                {{ size(file.size) }}
                            </td>
                            <td
                                class="mk-t2"
                                style="width: 116px; font-size: 12.5px"
                            >
                                {{ file.by }}
                            </td>
                            <td
                                class="mk-m mk-t3"
                                style="width: 132px; font-size: 12px"
                            >
                                {{ dateTime(file.at, tag) }}
                            </td>
                            <td class="mk-t3 mk-clip" style="font-size: 12px">
                                {{ file.reason ? `«${file.reason}»` : '—' }}
                            </td>
                            <td style="width: 118px; text-align: right">
                                <a
                                    :href="chat.download.url(file.id)"
                                    class="mk-btn mk-ghost"
                                    style="height: 26px"
                                    >{{ t('chat.download') }}</a
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- What the room is holding, and for how long. A dump with no
                 stated end is a disk somebody discovers full. -->
            <div
                style="
                    display: flex;
                    align-items: center;
                    gap: 18px;
                    padding-top: 14px;
                    border-top: 1px solid var(--mk-hair);
                "
            >
                <span class="mk-k">{{ t('chat.f.storage') }}</span>
                <span class="mk-t3" style="font-size: 11.5px">{{
                    t('chat.f.storageNote', {
                        size: size(total.bytes),
                        days: limits.retentionDays,
                    })
                }}</span>
                <span
                    class="mk-t3 mk-wide"
                    style="margin-left: auto; font-size: 11.5px"
                    >{{ t('chat.f.private') }}</span
                >
            </div>
        </div>
    </div>
</template>
