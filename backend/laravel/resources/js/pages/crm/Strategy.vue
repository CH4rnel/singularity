<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Rule from '@/components/console/Rule.vue';
import { useLocale } from '@/composables/useLocale';
import { dateTime } from '@/lib/console';
import { consoleMessages } from '@/lib/consoleMessages';
import strategy from '@/routes/crm/strategy';

const props = defineProps<{
    edited: boolean;
    updatedAt: string | null;
}>();

const { t, tag } = useLocale(consoleMessages);
const frame = ref<HTMLIFrameElement | null>(null);
const ready = ref(false);
const dirty = ref(false);
const saving = ref(false);
const imageInput = ref<HTMLInputElement | null>(null);
const shell = ref<HTMLElement | null>(null);
const visible = ref(true);
const pinned = ref(false);
const floating = ref({ left: 110, top: 72, width: 980, height: 720 });
const saveForm = useForm({ html: '' });

const shellStyle = computed(() =>
    pinned.value
        ? {
              left: `${floating.value.left}px`,
              top: `${floating.value.top}px`,
              width: `${floating.value.width}px`,
              height: `${floating.value.height}px`,
          }
        : {},
);

const status = computed(() => {
    if (saving.value) return t('strategy.saving');
    if (dirty.value) return t('strategy.unsaved');
    if (props.updatedAt) {
        return t('strategy.savedAt', {
            at: dateTime(props.updatedAt, tag.value),
        });
    }

    return t('strategy.original');
});

function editorDocument(): Document | null {
    return frame.value?.contentDocument ?? null;
}

function onFrameLoad() {
    const doc = editorDocument();
    if (!doc) return;

    doc.designMode = 'on';
    doc.body.spellcheck = true;
    doc.addEventListener('input', markDirty);
    ready.value = true;
}

function markDirty() {
    dirty.value = true;
}

function run(command: string, value?: string) {
    const doc = editorDocument();
    if (!doc) return;

    frame.value?.contentWindow?.focus();
    doc.execCommand(command, false, value);
    markDirty();
}

function createLink() {
    const url = window.prompt(t('strategy.linkPrompt'), 'https://');
    if (url) run('createLink', url);
}

function insertImage(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file || !file.type.startsWith('image/')) return;

    const reader = new FileReader();
    reader.onload = () => {
        if (typeof reader.result === 'string') run('insertImage', reader.result);
        if (imageInput.value) imageInput.value.value = '';
    };
    reader.readAsDataURL(file);
}

function save() {
    const doc = editorDocument();
    if (!doc || saving.value) return;

    saveForm.html = `<!doctype html>\n${doc.documentElement.outerHTML}`;
    saving.value = true;
    saveForm.put(strategy.update.url(), {
        preserveScroll: true,
        onSuccess: () => {
            dirty.value = false;
            saveForm.html = '';
        },
        onFinish: () => {
            saving.value = false;
        },
    });
}

function reset() {
    if (!window.confirm(t('strategy.resetConfirm'))) return;

    router.delete(strategy.reset.url(), {
        preserveScroll: true,
        onSuccess: () => {
            dirty.value = false;
            ready.value = false;
            if (frame.value) frame.value.src = strategy.document.url();
        },
    });
}

function protectUnsaved(event: BeforeUnloadEvent) {
    if (dirty.value) {
        event.preventDefault();
    }
}

function togglePin() {
    pinned.value = !pinned.value;
    visible.value = true;

    if (pinned.value) {
        floating.value.width = Math.min(
            floating.value.width,
            window.innerWidth - 32,
        );
        floating.value.height = Math.min(
            floating.value.height,
            window.innerHeight - 32,
        );
    }

    window.localStorage.setItem('crm-strategy-pinned', String(pinned.value));
}

function closeWindow() {
    visible.value = false;
}

function startDrag(event: PointerEvent) {
    if (!pinned.value || (event.target as HTMLElement).closest('button')) return;

    const origin = {
        x: event.clientX,
        y: event.clientY,
        left: floating.value.left,
        top: floating.value.top,
    };

    const move = (next: PointerEvent) => {
        floating.value.left = Math.max(
            8,
            Math.min(
                window.innerWidth - 120,
                origin.left + next.clientX - origin.x,
            ),
        );
        floating.value.top = Math.max(
            8,
            Math.min(
                window.innerHeight - 48,
                origin.top + next.clientY - origin.y,
            ),
        );
    };
    const stop = () => {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', stop);
        persistWindow();
    };

    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', stop, { once: true });
}

function persistWindow() {
    if (pinned.value && shell.value) {
        floating.value.width = shell.value.offsetWidth;
        floating.value.height = shell.value.offsetHeight;
    }

    window.localStorage.setItem(
        'crm-strategy-window',
        JSON.stringify(floating.value),
    );
}

onMounted(() => {
    window.addEventListener('beforeunload', protectUnsaved);

    pinned.value = window.localStorage.getItem('crm-strategy-pinned') === 'true';
    try {
        const saved = JSON.parse(
            window.localStorage.getItem('crm-strategy-window') ?? 'null',
        ) as Partial<typeof floating.value> | null;
        if (saved) floating.value = { ...floating.value, ...saved };
    } catch {
        window.localStorage.removeItem('crm-strategy-window');
    }

    floating.value.left = Math.max(
        8,
        Math.min(floating.value.left, window.innerWidth - 120),
    );
    floating.value.top = Math.max(
        8,
        Math.min(floating.value.top, window.innerHeight - 48),
    );

    shell.value?.addEventListener('pointerup', persistWindow);
});
onBeforeUnmount(() => {
    editorDocument()?.removeEventListener('input', markDirty);
    window.removeEventListener('beforeunload', protectUnsaved);
    shell.value?.removeEventListener('pointerup', persistWindow);
});
</script>

<template>
    <Head :title="`Пульт · ${t('strategy.title')}`" />

    <div class="strategy-heading">
        <div>
            <h1 class="mk-h1">{{ t('strategy.title') }}</h1>
            <p class="mk-t3 strategy-lead">{{ t('strategy.lead') }}</p>
        </div>
        <div class="strategy-status" :class="{ 'strategy-status--dirty': dirty }">
            <span class="strategy-status__light" />
            <span class="mk-m">{{ status }}</span>
        </div>
    </div>

    <button
        v-if="!visible"
        type="button"
        class="strategy-reopen"
        @click="visible = true"
    >
        {{ t('strategy.open') }}
    </button>

    <section
        v-show="visible"
        ref="shell"
        class="strategy-shell"
        :class="{ 'strategy-shell--pinned': pinned }"
        :style="shellStyle"
    >
        <div class="strategy-shell__corners" aria-hidden="true" />
        <div class="strategy-windowbar" @pointerdown="startDrag">
            <span class="strategy-windowbar__mark" />
            <span class="mk-m">{{ t('strategy.window') }}</span>
            <button
                type="button"
                class="strategy-pin"
                :class="{ 'strategy-pin--on': pinned }"
                :title="pinned ? t('strategy.unpin') : t('strategy.pin')"
                :aria-pressed="pinned"
                @click="togglePin"
            >
                ◇
            </button>
            <button
                type="button"
                class="strategy-close"
                :title="t('strategy.close')"
                :aria-label="t('strategy.close')"
                @click="closeWindow"
            >
                ×
            </button>
        </div>
        <div class="strategy-toolbar" role="toolbar" :aria-label="t('strategy.toolbar')">
            <div class="strategy-toolbar__group">
                <button type="button" title="Bold" @mousedown.prevent="run('bold')"><b>B</b></button>
                <button type="button" title="Italic" @mousedown.prevent="run('italic')"><i>I</i></button>
                <button type="button" title="Underline" @mousedown.prevent="run('underline')"><u>U</u></button>
                <button type="button" title="Strike" @mousedown.prevent="run('strikeThrough')"><s>S</s></button>
            </div>

            <div class="strategy-toolbar__group">
                <select :aria-label="t('strategy.block')" @change="run('formatBlock', ($event.target as HTMLSelectElement).value)">
                    <option value="p">Text</option>
                    <option value="h1">H1</option>
                    <option value="h2">H2</option>
                    <option value="h3">H3</option>
                    <option value="blockquote">Quote</option>
                </select>
                <select :aria-label="t('strategy.font')" @change="run('fontName', ($event.target as HTMLSelectElement).value)">
                    <option value="Arial">Arial</option>
                    <option value="Georgia">Georgia</option>
                    <option value="Courier New">Mono</option>
                    <option value="Impact">Impact</option>
                </select>
                <select :aria-label="t('strategy.size')" @change="run('fontSize', ($event.target as HTMLSelectElement).value)">
                    <option v-for="size in 7" :key="size" :value="size">{{ size }}</option>
                </select>
            </div>

            <div class="strategy-toolbar__group strategy-toolbar__colors">
                <label :title="t('strategy.color')"><span>A</span><input type="color" value="#00e5d1" @input="run('foreColor', ($event.target as HTMLInputElement).value)" /></label>
                <label :title="t('strategy.highlight')"><span>▰</span><input type="color" value="#fff06a" @input="run('hiliteColor', ($event.target as HTMLInputElement).value)" /></label>
            </div>

            <div class="strategy-toolbar__group">
                <button type="button" title="Align left" @mousedown.prevent="run('justifyLeft')">≡</button>
                <button type="button" title="Center" @mousedown.prevent="run('justifyCenter')">≣</button>
                <button type="button" title="Bullets" @mousedown.prevent="run('insertUnorderedList')">•≡</button>
                <button type="button" title="Numbers" @mousedown.prevent="run('insertOrderedList')">1≡</button>
                <button type="button" :title="t('strategy.link')" @mousedown.prevent="createLink">↗</button>
                <button type="button" :title="t('strategy.image')" @mousedown.prevent="imageInput?.click()">▧</button>
                <input ref="imageInput" hidden type="file" accept="image/*" @change="insertImage" />
            </div>

            <div class="strategy-toolbar__group">
                <button type="button" title="Undo" @mousedown.prevent="run('undo')">↶</button>
                <button type="button" title="Redo" @mousedown.prevent="run('redo')">↷</button>
                <button type="button" :title="t('strategy.clear')" @mousedown.prevent="run('removeFormat')">Tx</button>
            </div>

            <div class="strategy-toolbar__actions">
                <button v-if="edited" type="button" class="mk-btn mk-ghost" @click="reset">{{ t('strategy.reset') }}</button>
                <button type="button" class="mk-btn mk-act" :disabled="!ready || saving" @click="save">{{ t('strategy.save') }}</button>
            </div>
        </div>

        <div class="strategy-frame-wrap">
            <div v-if="!ready" class="strategy-loading mk-m">{{ t('strategy.loading') }}</div>
            <iframe
                ref="frame"
                :src="strategy.document.url()"
                :title="t('strategy.document')"
                sandbox="allow-same-origin"
                class="strategy-frame"
                @load="onFrameLoad"
            />
        </div>
        <p v-if="Object.keys(saveForm.errors).length" class="strategy-error">
            {{ Object.values(saveForm.errors).join(' · ') }}
        </p>
    </section>

    <Rule :label="t('strategy.hintTitle')" :note="t('strategy.hint')" />
</template>

<style scoped>
.strategy-reopen{position:fixed;z-index:99;right:18px;bottom:18px;height:30px;padding:0 12px;border:1px solid rgba(0,229,209,.45);background:rgba(6,12,15,.94);box-shadow:0 0 18px rgba(0,229,209,.12);color:var(--mk-accent);font:10px var(--mk-mono);letter-spacing:.09em;text-transform:uppercase;cursor:pointer}.strategy-reopen:hover{box-shadow:0 0 24px rgba(0,229,209,.3)}
.strategy-windowbar{display:flex;align-items:center;gap:8px;min-height:28px;padding:0 7px 0 10px;border-bottom:1px solid rgba(0,229,209,.16);background:rgba(4,9,12,.98);color:var(--mk-faint);font-size:9px;letter-spacing:.1em;text-transform:uppercase;user-select:none}.strategy-shell--pinned .strategy-windowbar{cursor:move;touch-action:none}.strategy-windowbar__mark{width:24px;height:1px;background:var(--mk-accent);box-shadow:0 0 8px var(--mk-accent)}.strategy-pin,.strategy-close{display:grid;width:22px;height:22px;padding:0;place-items:center;border:1px solid transparent;background:transparent;color:var(--mk-faint);cursor:pointer}.strategy-pin{margin-left:auto;font-size:16px;transform:rotate(45deg)}.strategy-pin:hover,.strategy-pin:focus-visible,.strategy-pin--on{border-color:rgba(0,229,209,.35);color:var(--mk-accent);text-shadow:0 0 8px var(--mk-accent);outline:none}.strategy-pin--on{background:rgba(0,229,209,.08)}.strategy-close{font-size:15px;line-height:1}.strategy-close:hover,.strategy-close:focus-visible{border-color:rgba(255,77,77,.4);background:rgba(255,77,77,.08);box-shadow:0 0 12px rgba(255,77,77,.22);color:var(--mk-critical);text-shadow:0 0 7px var(--mk-critical);outline:none}
.strategy-shell--pinned{position:fixed;z-index:90;display:flex;min-width:min(520px,calc(100vw - 16px));min-height:360px;max-width:calc(100vw - 16px);max-height:calc(100vh - 16px);resize:both;overflow:hidden;background:#070c0f;box-shadow:0 22px 80px rgba(0,0,0,.72),0 0 38px rgba(0,229,209,.15)}.strategy-shell--pinned .strategy-toolbar{flex:0 0 auto}.strategy-shell--pinned .strategy-frame-wrap{flex:1;height:auto;min-height:0}.strategy-shell--pinned .strategy-error{flex:0 0 auto}
.strategy-error{margin:0;padding:8px 10px;border-top:1px solid rgba(255,77,77,.25);color:var(--mk-critical);font-size:11px}
.strategy-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:18px}.strategy-lead{max-width:72ch;margin:7px 0 0;font-size:12px;line-height:1.5}.strategy-status{display:flex;align-items:center;gap:8px;padding:6px 9px;border:1px solid rgba(0,229,209,.22);background:rgba(0,229,209,.04);color:var(--mk-dim);font-size:10px}.strategy-status__light{width:6px;height:6px;background:var(--mk-accent);box-shadow:0 0 10px var(--mk-accent)}.strategy-status--dirty{border-color:rgba(224,165,22,.45);color:var(--mk-warning)}.strategy-status--dirty .strategy-status__light{background:var(--mk-warning);box-shadow:0 0 10px var(--mk-warning)}.strategy-shell{position:relative;min-height:0;padding:1px;border:1px solid rgba(0,229,209,.3);background:linear-gradient(135deg,rgba(0,229,209,.09),transparent 18%,transparent 82%,rgba(255,43,214,.08));box-shadow:0 0 34px rgba(0,229,209,.06),inset 0 0 28px rgba(0,0,0,.35)}.strategy-shell::before{position:absolute;z-index:2;top:-1px;left:22px;width:110px;height:2px;background:var(--mk-accent);box-shadow:0 0 12px var(--mk-accent);content:''}.strategy-shell__corners::before,.strategy-shell__corners::after{position:absolute;z-index:2;width:14px;height:14px;content:''}.strategy-shell__corners::before{right:-2px;top:-2px;border-top:2px solid var(--mk-money);border-right:2px solid var(--mk-money)}.strategy-shell__corners::after{bottom:-2px;left:-2px;border-bottom:2px solid var(--mk-accent);border-left:2px solid var(--mk-accent)}.strategy-toolbar{position:relative;z-index:3;display:flex;align-items:center;gap:7px;min-height:48px;padding:7px 9px;border-bottom:1px solid rgba(0,229,209,.2);background:rgba(6,12,15,.96);flex-wrap:wrap}.strategy-toolbar__group{display:flex;align-items:center;border:1px solid rgba(232,236,236,.11)}.strategy-toolbar button,.strategy-toolbar select,.strategy-toolbar label{height:28px;border:0;border-right:1px solid rgba(232,236,236,.1);background:rgba(232,236,236,.025);color:var(--mk-dim);font:11px var(--mk-mono);cursor:pointer}.strategy-toolbar button{min-width:29px;padding:0 7px}.strategy-toolbar button:hover,.strategy-toolbar button:focus-visible,.strategy-toolbar select:hover{color:var(--mk-accent);background:rgba(0,229,209,.09);outline:none}.strategy-toolbar select{max-width:86px;padding:0 7px}.strategy-toolbar__colors label{position:relative;display:grid;width:34px;place-items:center}.strategy-toolbar__colors input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}.strategy-toolbar__actions{display:flex;gap:7px;margin-left:auto}.strategy-toolbar__actions .mk-btn{height:28px}.strategy-frame-wrap{position:relative;height:calc(100vh - 250px);min-height:520px;background:#101315}.strategy-frame{display:block;width:100%;height:100%;border:0;background:#fff}.strategy-loading{position:absolute;inset:0;display:grid;place-items:center;background:#101315;color:var(--mk-faint);font-size:11px;letter-spacing:.1em;text-transform:uppercase}@media(max-width:760px){.strategy-heading{align-items:stretch;flex-direction:column}.strategy-status{width:max-content}.strategy-frame-wrap{height:70vh;min-height:420px}.strategy-toolbar__actions{width:100%;margin-left:0}.strategy-toolbar__actions .mk-btn:last-child{margin-left:auto}}
</style>
