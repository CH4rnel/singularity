import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const tasks = readFileSync(
    new URL('../../resources/js/pages/crm/Tasks.vue', import.meta.url),
    'utf8',
);
const strategy = readFileSync(
    new URL(
        '../../resources/js/components/console/StrategyWorkspace.vue',
        import.meta.url,
    ),
    'utf8',
);
const strategyPage = readFileSync(
    new URL('../../resources/js/pages/crm/Strategy.vue', import.meta.url),
    'utf8',
);
const consoleLayout = readFileSync(
    new URL('../../resources/js/layouts/ConsoleLayout.vue', import.meta.url),
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
        "'fontName'",
        "'fontSize'",
        "'foreColor'",
        "'hiliteColor'",
        "run('insertImage'",
        'strategy.update.url()',
        'sandbox="allow-same-origin"',
        'strategy-shell--pinned',
        'startResize',
        'strategy-close',
        'startDrag',
        'crm-strategy-window',
        "pinned ? '.mostik' : '#strategy-dock'",
    ]) {
        assert.ok(
            strategy.includes(feature),
            `missing editor feature: ${feature}`,
        );
    }

    assert.match(
        strategyPage,
        /#strategy-dock\s*\{[^}]*width:\s*100%[^}]*height:/,
    );
    assert.ok(
        consoleLayout.includes('<StrategyWorkspace />'),
        'persistent console layout owns the strategy window',
    );
});
