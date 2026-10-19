import assert from 'node:assert/strict';
import test from 'node:test';

import {
    EXPERIMENTS,
    isUnlocked,
    missLine,
    runCommand,
} from '@/lib/wallet/layers';
import { PIECES, mulberry32 } from '@/lib/wallet/netart';

/**
 * The layers under Settings decide two things in the browser: what is open to
 * somebody, and what the terminal does with a line. Both are pinned here; the
 * answers themselves are the server's and are tested there.
 */

const CONTEXT = { who: null, readme: ['readme'], help: ['help'] };

test('nobody signed in stands on the first rung of everything', () => {
    assert.equal(isUnlocked({ level: 1 }, null, []), true);
    assert.equal(isUnlocked({ level: 2 }, null, []), false);
    assert.equal(isUnlocked({ secret: 'navi' }, null, []), false);
});

test('a level opens what asks for it and everything below', () => {
    assert.equal(isUnlocked({ level: 3 }, 3, []), true);
    assert.equal(isUnlocked({ level: 3 }, 5, []), true);
    assert.equal(isUnlocked({ level: 4 }, 3, []), false);
});

test('a find opens what asks for that find and nothing else', () => {
    assert.equal(isUnlocked({ secret: 'navi' }, 1, ['navi']), true);
    assert.equal(isUnlocked({ secret: 'robots' }, 50, ['navi']), false);
});

test('every layer has something open at level 1', () => {
    assert.ok(EXPERIMENTS.some((entry) => isUnlocked(entry.requires, 1, [])));
    assert.ok(PIECES.some((piece) => isUnlocked(piece.requires, 1, [])));
});

test('ls hides the dotfile and ls -a shows it', () => {
    assert.doesNotMatch(runCommand('ls', CONTEXT).lines.join(' '), /\.navi/);
    assert.match(runCommand('ls -a', CONTEXT).lines.join(' '), /\.navi/);
    assert.match(runCommand('cat .navi', CONTEXT).lines.join(' '), /navi/);
});

test('commands are answered locally and anything else is a guess', () => {
    assert.deepEqual(runCommand('help', CONTEXT), {
        kind: 'print',
        lines: ['help'],
    });
    assert.deepEqual(runCommand('cd gallery/', CONTEXT), {
        kind: 'open',
        target: 'gallery',
    });
    assert.deepEqual(runCommand('  ', CONTEXT), { kind: 'none' });
    assert.deepEqual(runCommand('Present day, present time', CONTEXT), {
        kind: 'claim',
        code: 'Present day, present time',
    });
});

test('the same wrong word always gets the same answer', () => {
    assert.equal(missLine('hunter2'), missLine('hunter2'));
});

test('an edition is its seed: the same seed draws the same picture', () => {
    const first = mulberry32(42);
    const second = mulberry32(42);

    for (let index = 0; index < 5; index++) {
        assert.equal(first(), second());
    }
});
