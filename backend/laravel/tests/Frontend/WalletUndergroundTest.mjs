import assert from 'node:assert/strict';
import test from 'node:test';

import {
    HOMEPAGE_TEMPLATES,
    buildHomepage,
    scriptValue,
    suggestSubdomain,
} from '@/lib/wallet/homepages';
import { runCommand } from '@/lib/wallet/layers';
import { tokensBy } from '@/lib/wallet/underground';

/**
 * A homepage template is a whole file that gets uploaded and served, so what
 * is pinned is what would break somebody's page or somebody's neighbourhood:
 * a name that escapes its tag, a ring link that points at the wrong stop, a
 * link that is not a web address.
 */

const SITE = {
    name: '</title><script>alert(1)</script>',
    symbol: 'mine',
    description: 'blocks & "quotes"',
    subdomain: 'minecraft',
    address: '0xAbC0000000000000000000000000000000000001',
    undergroundUrl: 'https://underground.cyberia.church',
    buyUrl: 'https://swap.cyberia.church/#/swap?outputCurrency=0xabc',
    links: [
        { label: 'X', url: 'https://x.com/cyberia' },
        { label: 'bad', url: 'javascript:alert(1)' },
    ],
};

test('every template is a whole document with the name escaped', () => {
    for (const template of HOMEPAGE_TEMPLATES) {
        const html = buildHomepage(template, SITE);

        assert.match(html, /^<!DOCTYPE html>/, template);
        assert.match(html, /<\/html>\s*$/, template);
        assert.doesNotMatch(html, /<script>alert\(1\)/, template);
        assert.match(html, /&lt;\/title&gt;&lt;script&gt;/, template);
    }
});

test('every template is a stop on the ring and counts its visitors', () => {
    for (const template of HOMEPAGE_TEMPLATES) {
        const html = buildHomepage(template, SITE);

        for (const direction of ['prev', 'random', 'next']) {
            assert.ok(
                html.includes(
                    `https://underground.cyberia.church/ring/${direction}/minecraft`,
                ),
                `${template} ${direction}`,
            );
        }

        assert.ok(
            html.includes(
                'https://underground.cyberia.church/counter/minecraft.svg',
            ),
            template,
        );
    }
});

test('only web addresses become links', () => {
    const html = buildHomepage('homestead', SITE);

    assert.ok(html.includes('https://x.com/cyberia'));
    assert.doesNotMatch(html, /javascript:/);
});

test('a value handed to a script cannot close the script', () => {
    assert.doesNotMatch(scriptValue('</script><b>'), /<\/script>/);
});

test('a ticker suggests the subdomain it can be', () => {
    assert.equal(suggestSubdomain('MINE'), 'mine');
    assert.equal(suggestSubdomain('$LAB!'), 'lab');
    assert.equal(suggestSubdomain('——'), '');
});

test('the workshop lists the tokens this wallet launched, in any case', () => {
    const directory = {
        underground_url: 'https://underground.cyberia.church',
        ring: [],
        homes: [{ address: '0x1', creator: '0xaaa' }],
        lots: [
            { address: '0x2', creator: '0xbbb' },
            { address: '0x3', creator: null },
        ],
    };

    assert.deepEqual(
        tokensBy(directory, ['0xAAA']).map((entry) => entry.address),
        ['0x1'],
    );
});

test('the terminal knows the way down to the underground', () => {
    const context = { who: null, readme: [], help: [] };

    assert.deepEqual(runCommand('cd underground/', context), {
        kind: 'open',
        target: 'underground',
    });
    assert.match(runCommand('ls', context).lines.join(' '), /underground\//);
});
