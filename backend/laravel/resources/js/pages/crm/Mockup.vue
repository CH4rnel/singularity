<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Rule from '@/components/console/Rule.vue';
import { useLocale } from '@/composables/useLocale';
import { consoleMessages } from '@/lib/consoleMessages';

/**
 * "Макет" — the drawing this console was built from, kept inside it.
 *
 * A design that lives only in a canvas link is gone the first time the link
 * changes owner, and a design exported to pictures loses the one thing worth
 * keeping: the argument written next to the screens. So the artboards are
 * files in this repository, and this lens is how an operator reads them.
 *
 * Each artboard is a whole page of its own CSS, so it is framed rather than
 * inlined — dropped into this DOM it would restyle the console around it.
 * The frame is sandboxed with nothing allowed: the design has never had a
 * script in it, and this is the cheapest way to keep it that way.
 */
type Screen = {
    key: string;
    title: string;
    width: number;
    height: number;
};

const props = defineProps<{
    screens: Screen[];
    notes: { key: string; text: string }[];
    source: string;
}>();

const { t } = useLocale(consoleMessages);

const current = ref<string>(props.screens[0]?.key ?? '');
const actual = ref(false);
const stage = ref<HTMLElement | null>(null);
const stageWidth = ref(0);

const screen = computed(
    () => props.screens.find((item) => item.key === current.value) ?? null,
);

/**
 * A 1440-wide artboard inside a 76px rail and a page gutter is never shown at
 * its own size, so it is scaled down rather than scrolled sideways — the whole
 * point of a mockup is seeing the screen at once. "Actual size" is one click
 * away for reading the small type.
 */
const scale = computed(() => {
    if (actual.value || !screen.value || stageWidth.value === 0) {
        return 1;
    }

    return Math.min(1, stageWidth.value / screen.value.width);
});

const framed = computed(() => {
    if (!screen.value) {
        return { width: '0px', height: '0px' };
    }

    return {
        width: `${screen.value.width}px`,
        height: `${screen.value.height}px`,
    };
});

let observer: ResizeObserver | null = null;

onMounted(() => {
    if (!stage.value) {
        return;
    }

    const measure = () => {
        stageWidth.value = stage.value?.clientWidth ?? 0;
    };

    measure();
    observer = new ResizeObserver(measure);
    observer.observe(stage.value);
});

onBeforeUnmount(() => observer?.disconnect());

/** The first line of an annotation is its heading; the rest is the argument. */
function heading(text: string): string {
    return text.split('\n')[0] ?? '';
}

function argument(text: string): string {
    return text.split('\n').slice(1).join('\n').trim();
}
</script>

<template>
    <Head title="Пульт · Макет" />

    <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap">
        <h1 class="mk-h1">{{ t('mockup.title') }}</h1>
        <span class="mk-m mk-t3" style="font-size: 12px">{{
            t('mockup.lead')
        }}</span>
        <div
            style="
                margin-left: auto;
                display: flex;
                gap: 8px;
                align-items: center;
            "
        >
            <button
                type="button"
                class="mk-btn"
                :class="{ 'mk-act': !actual }"
                @click="actual = !actual"
            >
                {{ actual ? t('mockup.fit') : t('mockup.actual') }}
            </button>
            <a
                v-if="screen"
                class="mk-btn"
                :href="`/crm/mockup/${screen.key}`"
                target="_blank"
                rel="noopener"
                >{{ t('mockup.separately') }}</a
            >
            <a
                v-if="source"
                class="mk-btn mk-ghost mk-wide"
                :href="source"
                target="_blank"
                rel="noopener"
                >{{ t('mockup.canvas') }}</a
            >
        </div>
    </div>

    <p class="mk-t3" style="margin: 6px 0 0; font-size: 12px; max-width: 74ch">
        {{ t('mockup.frozen') }}
    </p>
    <p class="mk-t3" style="margin: 4px 0 0; font-size: 12px; max-width: 74ch">
        {{ t('mockup.oldName') }}
    </p>

    <template v-if="screens.length">
        <div style="margin-top: 18px">
            <Rule :label="t('mockup.screens')" />
        </div>

        <!-- The artboards as one row of names: nine is a list, not a menu. -->
        <div
            style="
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin: 10px 0 14px;
                align-items: center;
            "
        >
            <button
                v-for="item in screens"
                :key="item.key"
                type="button"
                class="mk-btn"
                :class="{ 'mk-act': item.key === current }"
                style="text-transform: none; letter-spacing: 0"
                @click="current = item.key"
            >
                {{ item.title }}
            </button>
        </div>

        <div
            v-if="screen"
            class="mk-panel"
            style="
                padding: 14px;
                display: flex;
                flex-direction: column;
                gap: 10px;
            "
        >
            <div style="display: flex; align-items: baseline; gap: 12px">
                <span class="mk-k">{{ screen.title }}</span>
                <span class="mk-m mk-t3" style="font-size: 11px">
                    {{
                        t('mockup.size', {
                            width: screen.width,
                            height: screen.height,
                        })
                    }}
                </span>
                <span
                    v-if="scale < 1"
                    class="mk-m mk-t3"
                    style="font-size: 11px; margin-left: auto"
                >
                    {{
                        t('mockup.scale', { percent: Math.round(scale * 100) })
                    }}
                </span>
            </div>

            <div ref="stage" :style="{ overflowX: actual ? 'auto' : 'hidden' }">
                <div
                    :style="{
                        width: actual ? framed.width : '100%',
                        height: `${Math.round(screen.height * scale)}px`,
                    }"
                >
                    <iframe
                        :key="screen.key"
                        :src="`/crm/mockup/${screen.key}`"
                        :title="screen.title"
                        sandbox=""
                        loading="lazy"
                        :style="{
                            width: framed.width,
                            height: framed.height,
                            border: '0',
                            display: 'block',
                            transform: `scale(${scale})`,
                            transformOrigin: 'top left',
                        }"
                    />
                </div>
            </div>
        </div>

        <div style="margin-top: 22px">
            <Rule :label="t('mockup.why')" :note="t('mockup.russian')" />
        </div>

        <div
            style="
                margin-top: 12px;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(310px, 1fr));
                gap: 10px;
            "
        >
            <div
                v-for="note in notes"
                :key="note.key"
                class="mk-panel"
                style="padding: 14px"
            >
                <div class="mk-k">{{ heading(note.text) }}</div>
                <p
                    class="mk-t2"
                    style="
                        margin: 8px 0 0;
                        font-size: 12.5px;
                        line-height: 1.55;
                        white-space: pre-wrap;
                    "
                >
                    {{ argument(note.text) }}
                </p>
            </div>
        </div>
    </template>

    <p v-else class="mk-t3" style="margin-top: 20px; font-size: 13px">
        {{ t('mockup.empty') }}
    </p>
</template>
