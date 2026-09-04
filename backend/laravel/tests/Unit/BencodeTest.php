<?php

use App\Support\Bencode;

/**
 * Bencode is the one place in the tracker where being slightly wrong is
 * invisible until a stranger's client refuses to talk to us.
 *
 * Two things here are not style. Dictionary keys come out bytewise sorted,
 * because the info hash of a torrent is SHA-1 over its bencoded info
 * dictionary and a different order is a different torrent. And strings are
 * bytes: an info hash is twenty arbitrary ones, several of which are
 * whitespace or NUL in any given swarm.
 */
test('the four types encode the way every other client reads them', function () {
    expect(Bencode::encode(42))->toBe('i42e')
        ->and(Bencode::encode(0))->toBe('i0e')
        ->and(Bencode::encode(-7))->toBe('i-7e')
        ->and(Bencode::encode('spam'))->toBe('4:spam')
        ->and(Bencode::encode(''))->toBe('0:')
        ->and(Bencode::encode(['a', 1]))->toBe('l1:ai1ee')
        ->and(Bencode::encode(['cow' => 'moo', 'spam' => 'eggs']))->toBe('d3:cow3:moo4:spam4:eggse');
});

test('dictionary keys are sorted bytewise, whatever order they were written in', function () {
    expect(Bencode::encode(['spam' => 'eggs', 'cow' => 'moo']))
        ->toBe('d3:cow3:moo4:spam4:eggse');

    // Uppercase sorts before lowercase in bytes and after it in most locales.
    expect(Bencode::encode(['a' => 1, 'Z' => 2]))->toBe('d1:Zi2e1:ai1ee');
});

test('a string is bytes, not text', function () {
    $hash = hex2bin('0a20005468697320697320612074657374212121');

    expect(strlen($hash))->toBe(20)
        ->and(Bencode::decode(Bencode::encode($hash)))->toBe($hash);
});

test('decoding is strict about what it accepts', function () {
    expect(fn () => Bencode::decode('i03e'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Bencode::decode('4:ab'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Bencode::decode('l1:ae1:b'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Bencode::decode('d1:ae'))->toThrow(InvalidArgumentException::class);
});

test('a round trip through a torrent-shaped document survives', function () {
    $document = [
        'announce' => 'https://cyberia.church/announce',
        'info' => [
            'name' => 'release',
            'piece length' => 262144,
            'pieces' => random_bytes(60),
            'files' => [
                ['length' => 12, 'path' => ['a', 'b.flac']],
                ['length' => 34, 'path' => ['c.flac']],
            ],
        ],
    ];

    // `toEqual` and not `toBe`: the encoder sorts a dictionary's keys, so the
    // document comes back with the same pairs in the order the wire uses.
    expect(Bencode::decode(Bencode::encode($document)))->toEqual($document);
});

test('the info dictionary can be sliced out verbatim, which is what makes a hash', function () {
    // The bytes are what SHA-1 is taken over. Re-encoding a decoded document
    // only reproduces them when the writer agreed with us about key order —
    // slicing the original always does.
    $raw = 'd8:announce5:hello4:infod6:lengthi7e4:name4:teste5:extra3:abce';
    $info = Bencode::slice($raw, 'info');

    expect($info)->toBe('d6:lengthi7e4:name4:teste')
        ->and(sha1((string) $info))->toBe(sha1('d6:lengthi7e4:name4:teste'))
        ->and(Bencode::slice($raw, 'missing'))->toBeNull();
});
