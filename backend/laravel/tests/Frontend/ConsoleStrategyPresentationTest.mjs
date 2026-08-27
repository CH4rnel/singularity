import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const tasks = readFileSync(
    new URL('../../resources/js/pages/crm/Tasks.vue', import.meta.url),
    'utf8',
);
const strategy = readFileSync(
    new URL('../../resources/js/pages/crm/Strategy.vue', import.meta.url),
    'utf8',
);

test('completed tasks use the console page scroll instead of a nested scroller', () => {
    const completedCss = tasks.match(
        /\.task-completed__grid\s*\{(?<rules>[^}]*)\}/,
    )?.groups?.rules;

    assert.ok(completedCss, 'completed task grid CSS is present');
    assert.doesNotMatch(completedCss, /max-height|overflow-y|overscroll/);
});

test('the strategy frame exposes rich editing, images and durable save controls', () => {
    for (const feature of [
        "run('bold')",
        "run('fontName'",
        "run('fontSize'",
        "run('foreColor'",
        "run('hiliteColor'",
        "run('insertImage'",
        'strategy.update.url()',
        'sandbox="allow-same-origin"',
        'strategy-shell--pinned',
        'resize:both',
        'strategy-close',
        'startDrag',
        'crm-strategy-window',
    ]) {
        assert.ok(strategy.includes(feature), `missing editor feature: ${feature}`);
    }
});
