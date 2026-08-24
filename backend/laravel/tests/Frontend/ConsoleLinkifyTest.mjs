import assert from 'node:assert/strict';
import test from 'node:test';
import { linkify } from '@/lib/console';

/**
 * Where an address ends inside a written sentence.
 *
 * Everything else about linkifying is uninteresting; this is the part that
 * goes wrong, in both directions — a link cut short is a 404, and a link that
 * swallowed the full stop is a different 404.
 */

const href = (text) => linkify(text).filter((part) => part.href);

test('a bare url becomes one link and nothing else', () => {
    assert.deepEqual(linkify('https://cyberia.church'), [
        { text: 'https://cyberia.church', href: 'https://cyberia.church' },
    ]);
});

test('the text around a link is kept exactly as it was typed', () => {
    assert.deepEqual(linkify('см. https://cyberia.church сегодня'), [
        { text: 'см. ' },
        { text: 'https://cyberia.church', href: 'https://cyberia.church' },
        { text: ' сегодня' },
    ]);
});

test('a sentence keeps its punctuation and the link keeps its path', () => {
    assert.deepEqual(href('открой https://explorer.cyberia.church/tx/0xab.'), [
        {
            text: 'https://explorer.cyberia.church/tx/0xab',
            href: 'https://explorer.cyberia.church/tx/0xab',
        },
    ]);

    assert.deepEqual(href('(см. https://cyberia.church/docs), потом'), [
        {
            text: 'https://cyberia.church/docs',
            href: 'https://cyberia.church/docs',
        },
    ]);
});

test('a bracket the address opened is part of the address', () => {
    assert.deepEqual(href('https://ru.wikipedia.org/wiki/Лейн_(имя)'), [
        {
            text: 'https://ru.wikipedia.org/wiki/Лейн_(имя)',
            href: 'https://ru.wikipedia.org/wiki/Лейн_(имя)',
        },
    ]);
});

test('a query string survives, and so does the query itself', () => {
    assert.deepEqual(href('t.me? нет: https://t.me/cyberia?start=1&x=2!'), [
        { text: 'https://t.me/cyberia?start=1&x=2', href: 'https://t.me/cyberia?start=1&x=2' },
    ]);
});

test('www gets the scheme it was written without', () => {
    assert.deepEqual(href('www.cyberia.church'), [
        { text: 'www.cyberia.church', href: 'https://www.cyberia.church' },
    ]);
});

test('an address to write to is a mail link', () => {
    assert.deepEqual(href('пиши на ops@cyberia.church, отвечу'), [
        { text: 'ops@cyberia.church', href: 'mailto:ops@cyberia.church' },
    ]);
});

test('a bare domain is left alone — a room is full of file names', () => {
    assert.deepEqual(href('смотри config.php и cyberia.church'), []);
    assert.deepEqual(href('версия 1.2.3'), []);
});

test('a handle is not an address and is not touched', () => {
    assert.deepEqual(href('@lainos, посмотри #Nakamoto'), []);
});

test('several links in one line are found separately', () => {
    assert.deepEqual(
        href('https://a.io/1 и https://b.io/2'),
        [
            { text: 'https://a.io/1', href: 'https://a.io/1' },
            { text: 'https://b.io/2', href: 'https://b.io/2' },
        ],
    );
});

test('nothing to say returns nothing to render', () => {
    assert.deepEqual(linkify(''), []);
    assert.deepEqual(linkify(null), []);
    assert.deepEqual(linkify(undefined), []);
});

test('markup in a line stays text, because segments are never HTML', () => {
    const parts = linkify('<img src=x onerror=alert(1)> https://ok.io');

    assert.equal(parts[0].text, '<img src=x onerror=alert(1)> ');
    assert.equal(parts[0].href, undefined);
    assert.equal(parts[1].href, 'https://ok.io');
});

test('every segment joined back is the line that was typed', () => {
    for (const line of [
        'см. https://cyberia.church/docs, потом www.x.io!',
        'ops@cyberia.church',
        'ничего интересного',
        'https://t.me/cyberia?start=1',
        '  пробелы   и https://a.io  ',
    ]) {
        assert.equal(
            linkify(line)
                .map((part) => part.text)
                .join(''),
            line,
        );
    }
});
