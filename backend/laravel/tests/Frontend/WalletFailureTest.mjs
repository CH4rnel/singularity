import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { failureReason } from '@/lib/wallet/failure';
import { walletMessages } from '@/lib/walletMessages';

/**
 * What a failed send or swap says about the money.
 *
 * Two lies were on these screens: a transaction refused in a block — fee spent,
 * coins still there — was told "Nothing was transferred", and the reason under
 * any failure was the node's raw error, English and sometimes the whole signed
 * transaction in hex. These tests pin the replacements.
 */

const CODES = [
    'user_rejected',
    'insufficient_gas',
    'insufficient_funds',
    'allowance',
    'slippage',
    'no_route',
    'quote_expired',
    'nonce',
    'watch_only',
    'unsupported',
    'timeout',
    'rpc_unreachable',
    'reverted',
    'server_refused',
    'unknown',
];

test('every reason a failure can have is written in every language', () => {
    for (const code of CODES) {
        for (const lang of ['en', 'ru', 'zh']) {
            assert.ok(
                walletMessages[lang][`failReason_${code}`],
                `failReason_${code} missing in ${lang}`,
            );
        }
    }
});

test('a node error becomes a sentence, and the raw text is kept only as detail', () => {
    const raw = new Error(
        'insufficient funds for intrinsic transaction cost (transaction="0x02f874…", code=INSUFFICIENT_FUNDS)',
    );
    const reason = failureReason(raw);

    assert.equal(reason.key, 'failReason_insufficient_funds');
    assert.equal(reason.detail, raw.message);
    assert.doesNotMatch(walletMessages.en[reason.key], /0x|transaction=/);
});

test('a rejection in the wallet and an unknown error each get their own words', () => {
    assert.equal(failureReason({ code: 4001 }).key, 'failReason_user_rejected');
    assert.equal(
        failureReason(new Error('the quick brown fox')).key,
        'failReason_unknown',
    );
    assert.equal(failureReason(null).detail, '');
});

test('a transaction refused in a block is never told nothing happened', () => {
    for (const lang of ['en', 'ru', 'zh']) {
        const dict = walletMessages[lang];

        assert.ok(
            dict.txRevertedBody.includes('{amount}'),
            `${lang}: the reverted body names the amount`,
        );
        assert.notEqual(
            dict.txRevertedBody,
            dict.txFailedBody,
            `${lang}: reverted is not "not sent"`,
        );
        assert.notEqual(
            dict.txSettledTitle,
            dict.txConfirmedTitle,
            `${lang}: confirmed is not "broadcast"`,
        );
        assert.ok(
            dict.swapOutcomeBody_reverted.includes('{from}'),
            `${lang}: swap reverted names the asset`,
        );
    }
});

test('send and swap map a failed receipt to the reverted state', async () => {
    for (const file of ['WalletSend.vue', 'WalletSwap.vue']) {
        const source = await readFile(
            new URL(
                `../../resources/js/components/wallet/${file}`,
                import.meta.url,
            ),
            'utf8',
        );

        assert.match(
            source,
            /=== 'confirmed'\s*\?\s*'confirmed'\s*:\s*'reverted'/,
            `${file} maps awaitOutcome`,
        );
        assert.doesNotMatch(
            source,
            /failure\.value = error instanceof Error \? error\.message/,
            `${file} no longer shows raw errors`,
        );
    }
});
