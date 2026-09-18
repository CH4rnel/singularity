import assert from 'node:assert/strict';
import test from 'node:test';
import {
    bestOffer,
    countryFromLanguage,
    fiatForCountry,
    mirIsTheQuestion,
    needsSecondStep,
    offerAdvantagePct,
    parseFiatAmount,
} from '@/lib/wallet/onramp';

/**
 * The pure half of buying with a card.
 *
 * What is pinned here is the arithmetic and the guessing — the two places
 * where being wrong is silent. An amount that loses its decimal separator is a
 * purchase of a hundred instead of one-fifty; a country guessed out of a
 * language tag decides which providers somebody is even offered.
 */

const offer = (provider, cryptoAmount, available = true) => ({
    provider,
    label: provider,
    kind: 'widget',
    available,
    reason: available ? null : 'no_quote',
    cryptoAmount,
    fiatAmount: '100',
    rate: null,
    fee: null,
    via: null,
    tracking: 'none',
    best: false,
});

test('an amount is read the way the person typed it', () => {
    assert.equal(parseFiatAmount('100'), '100');
    assert.equal(parseFiatAmount(' 49.99 '), '49.99');
    // A comma is the decimal separator in most of the languages this wallet
    // speaks; dropping it would multiply somebody's purchase by a hundred.
    assert.equal(parseFiatAmount('1,50'), '1.50');
    assert.equal(parseFiatAmount('1 000'), '1000');
});

test('an amount that is not one is refused rather than rounded', () => {
    assert.equal(parseFiatAmount(''), null);
    assert.equal(parseFiatAmount('0'), null);
    assert.equal(parseFiatAmount('-5'), null);
    // Three decimal places is not a currency amount, and truncating it would
    // quietly charge a different number from the one on the screen.
    assert.equal(parseFiatAmount('10.001'), null);
    assert.equal(parseFiatAmount('1e3'), null);
});

test('the country comes from the language tag, or from nothing at all', () => {
    assert.equal(countryFromLanguage('ru-RU'), 'RU');
    assert.equal(countryFromLanguage('en_GB'), 'GB');
    assert.equal(countryFromLanguage('zh-Hans-CN'), null);
    // A bare language says nothing about where somebody is, and guessing would
    // hide providers they can actually use.
    assert.equal(countryFromLanguage('ru'), null);
    assert.equal(countryFromLanguage(''), null);
});

test('the currency follows the country, with the euro area as one answer', () => {
    assert.equal(fiatForCountry('DE'), 'EUR');
    assert.equal(fiatForCountry('GB'), 'GBP');
    assert.equal(fiatForCountry('RU'), 'RUB');
    assert.equal(fiatForCountry(null), 'USD');
    assert.equal(fiatForCountry('ZZ'), 'USD');
});

test('Мир is the first question for the people whose card it is', () => {
    assert.equal(mirIsTheQuestion('RU', 'USD'), true);
    assert.equal(mirIsTheQuestion(null, 'RUB'), true);
    assert.equal(mirIsTheQuestion('DE', 'EUR'), false);
});

test('nothing lands on Cyberia, and the screen has to say so', () => {
    assert.equal(needsSecondStep('base'), true);
    assert.equal(needsSecondStep('solana'), true);
    assert.equal(needsSecondStep('cyberia'), false);
});

test('the best offer is the first one that could be quoted', () => {
    const offers = [offer('ramp', null, false), offer('transak', '96.5')];

    assert.equal(bestOffer(offers)?.provider, 'transak');
    assert.equal(bestOffer([offer('ramp', null, false)]), null);
});

test('an advantage needs two prices to be an advantage', () => {
    const two = [offer('transak', '100'), offer('moonpay', '98')];

    assert.ok(Math.abs(offerAdvantagePct(two) - 2.0408) < 0.001);
    // One answer is not a comparison, and "infinitely better than nothing" is
    // the kind of number that ends up on a screen.
    assert.equal(offerAdvantagePct([offer('transak', '100')]), null);
    assert.equal(offerAdvantagePct([]), null);
});
