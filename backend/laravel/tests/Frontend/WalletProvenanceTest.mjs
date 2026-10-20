import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { logoForToken } from '@/lib/tokenLogos';
import { provenanceOf, registeredAddresses } from '@/lib/wallet/provenance';

/**
 * A token is what its contract is, not what its ticker says.
 *
 * Logos used to be looked up by symbol, so any token calling itself "USDC" —
 * Cyberia's bridge-minted one, or a stranger's airdrop on any chain — wore
 * Circle's logo. These tests pin the address-keyed replacement and keep the
 * registry in step with the public token directory.
 */

const CYBERIA = 49406;
const BRIDGED_USDC = '0xdc25597B19799010047F17e9591EFE08EFd40077';
const SPOOF_USDC = '0x7777777777777777777777777777777777777777';

test('the registry token gets its logo, a spoof with the same ticker gets none', () => {
    assert.equal(
        logoForToken(CYBERIA, BRIDGED_USDC, 'USDC'),
        '/token-icons/usdc.svg',
    );
    assert.equal(logoForToken(CYBERIA, SPOOF_USDC, 'USDC'), undefined);
    assert.equal(
        logoForToken(8453, BRIDGED_USDC, 'USDC'),
        undefined,
        'the same address on another chain is another token',
    );
});

test("a chain's own coin, which has no contract, still finds its logo by symbol", () => {
    assert.equal(
        logoForToken(CYBERIA, null, 'CYBER'),
        '/token-icons/cyberia.png',
    );
});

test('CYBER.sol on Solana, which has no chain id, finds its logo by mint', () => {
    const mint = 'E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump';

    assert.equal(
        logoForToken(undefined, mint, 'CYBER.sol'),
        '/token-icons/CYBER.png',
    );
    assert.equal(
        logoForToken(undefined, mint.toLowerCase(), 'CYBER.sol'),
        undefined,
        'base58 is case-sensitive',
    );
    assert.equal(
        logoForToken(
            undefined,
            'So11111111111111111111111111111111111111112',
            'CYBER.sol',
        ),
        undefined,
        'a ticker alone earns no logo',
    );
});

test('bridged assets are marked bridged, and lookups ignore address case', () => {
    assert.equal(provenanceOf(CYBERIA, BRIDGED_USDC)?.kind, 'bridged');
    assert.equal(
        provenanceOf(CYBERIA, BRIDGED_USDC.toLowerCase())?.kind,
        'bridged',
    );
    assert.equal(
        provenanceOf(CYBERIA, '0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9')
            ?.kind,
        'wrapped',
    );
    assert.equal(provenanceOf(CYBERIA, SPOOF_USDC), undefined);
    assert.equal(provenanceOf(undefined, BRIDGED_USDC), undefined);
});

test('every token in the public directory is in the registry, and every bridged one is marked so', async () => {
    const config = await readFile(
        new URL('../../config/tokens.php', import.meta.url),
        'utf8',
    );
    const entries = [
        ...config.matchAll(
            /'(0x[0-9a-f]{40})' => \[\s*'symbol' => '([^']+)'[\s\S]*?'category' => '(\w+)'/g,
        ),
    ];

    assert.ok(entries.length >= 20, 'the directory parsed');

    const registered = new Set(registeredAddresses(CYBERIA));

    for (const [, address, symbol, category] of entries) {
        assert.ok(
            registered.has(address),
            `${symbol} (${address}) is missing from the provenance registry`,
        );

        if (['stablecoin', 'crypto', 'commodity'].includes(category)) {
            assert.equal(
                provenanceOf(CYBERIA, address)?.kind,
                'bridged',
                `${symbol} is a bridged asset`,
            );
        }
    }
});
