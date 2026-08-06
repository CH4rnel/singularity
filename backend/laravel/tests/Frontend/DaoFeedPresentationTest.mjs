import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const feedItemSource = await readFile(
    new URL('../../resources/js/components/dao/FeedItem.vue', import.meta.url),
    'utf8',
);

test('activity headline keeps visible spacing between its links and action', () => {
    const headlineClasses = feedItemSource.match(
        /<p class="([^"]*text-sm[^"]*)">/,
    )?.[1];

    assert.ok(headlineClasses, 'activity headline classes were not found');
    assert.match(headlineClasses, /(?:^|\s)flex(?:\s|$)/);
    assert.match(headlineClasses, /(?:^|\s)flex-wrap(?:\s|$)/);
    assert.match(headlineClasses, /(?:^|\s)gap-x-1(?:\s|$)/);
});
