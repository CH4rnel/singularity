<?php

namespace App\Services\Predictions;

/**
 * The canonical question format for auto-resolvable prediction markets.
 *
 * PHP mirror of resources/js/lib/predictions.ts. The browser builds the string
 * and puts it on-chain; this class reads it back and decides real money on it,
 * so the two must agree byte for byte. Both sides pin the same vectors —
 * tests/Frontend/PredictionQuestionTest.mjs and tests/Feature/Predictions/
 * PredictionQuestionTest.php — and neither may drift alone.
 *
 *     Will SOL be above $80 on 2026-09-01? [px:SOL>80@coingecko]
 *
 * Everything before the tag is prose and is never parsed. A question with no
 * tag is a market only a human can settle, and the resolver leaves it alone.
 */
class PredictionQuestion
{
    /** Mirrors PredictionMarket.MAX_QUESTION_LENGTH. */
    public const MAX_QUESTION_LENGTH = 200;

    private const TAG_PATTERN =
        '/\[px:([A-Z][A-Z0-9]{0,9})([<>])(\d+(?:\.\d+)?)@([a-z][a-z0-9-]{0,19})\]$/';

    /**
     * Assets the oracle can price, and the feed it prices them from.
     *
     * This is the honest limit of automatic resolution. The keys are the
     * symbols allowed in a tag; `source` is named in the tag so the record
     * says where the number came from; `quote` is the key WalletPriceService
     * returns that price under. Adding a row without a matching quote key
     * would create markets that look automatic and are not.
     *
     * @var array<string, array{source: string, quote: string}>
     */
    private const ASSETS = [
        'CYBER' => ['source' => 'dexscreener', 'quote' => 'cyberia'],
        'BTC' => ['source' => 'coingecko', 'quote' => 'bitcoin'],
        'ETH' => ['source' => 'coingecko', 'quote' => 'base'],
        'SOL' => ['source' => 'coingecko', 'quote' => 'solana'],
        'BNB' => ['source' => 'coingecko', 'quote' => 'bnb'],
        'LTC' => ['source' => 'coingecko', 'quote' => 'litecoin'],
        'XMR' => ['source' => 'coingecko', 'quote' => 'monero'],
    ];

    /**
     * @return array<string, array{source: string, quote: string}>
     */
    public static function assets(): array
    {
        return self::ASSETS;
    }

    /**
     * Normalise a typed amount to one canonical spelling, with string
     * operations only.
     *
     * Never through a float: the tag is rebuilt and compared on two runtimes,
     * and a float disagrees with itself across them. "080.500" and "80.5" are
     * the same threshold and have to produce the same bytes.
     */
    public static function canonicalAmount(string $input): ?string
    {
        $raw = trim($input);

        if ($raw === '' || $raw === '.' || preg_match('/^\d*(?:\.\d*)?$/', $raw) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $whole = ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');

        if (strlen($fraction) > 8) {
            return null;
        }

        $value = ($whole === '' ? '0' : $whole).($fraction === '' ? '' : '.'.$fraction);

        // A threshold of zero is never a question anyone means to ask.
        return $value === '0' ? null : $value;
    }

    /**
     * Build the on-chain question for a price market, or null when the inputs
     * cannot make one.
     */
    public static function buildPrice(
        string $symbol,
        bool $above,
        string $threshold,
        int $closeAt,
    ): ?string {
        $symbol = strtoupper($symbol);
        $asset = self::ASSETS[$symbol] ?? null;
        $amount = self::canonicalAmount($threshold);

        if ($asset === null || $amount === null) {
            return null;
        }

        $question = sprintf(
            'Will %s be %s $%s on %s? [px:%s%s%s@%s]',
            $symbol,
            $above ? 'above' : 'below',
            $amount,
            gmdate('Y-m-d', $closeAt),
            $symbol,
            $above ? '>' : '<',
            $amount,
            $asset['source'],
        );

        return strlen($question) > self::MAX_QUESTION_LENGTH ? null : $question;
    }

    /**
     * Read the machine tag off a question, or null when there is none.
     *
     * Deliberately strict. An unknown asset, an unknown feed, or a feed that
     * does not match the one this build would use for that asset all read as
     * "no spec", and the market goes to the human queue. Guessing here pays
     * real money to the wrong side.
     *
     * @return array{symbol: string, above: bool, threshold: string, source: string, quote: string}|null
     */
    public static function parsePrice(string $question): ?array
    {
        if (preg_match(self::TAG_PATTERN, trim($question), $m) !== 1) {
            return null;
        }

        [, $symbol, $operator, $rawThreshold, $source] = $m;
        $asset = self::ASSETS[$symbol] ?? null;
        $threshold = self::canonicalAmount($rawThreshold);

        if ($asset === null || $asset['source'] !== $source || $threshold === null) {
            return null;
        }

        return [
            'symbol' => $symbol,
            'above' => $operator === '>',
            'threshold' => $threshold,
            'source' => $source,
            'quote' => $asset['quote'],
        ];
    }

    /** The prose half, for a message that should not carry the tag. */
    public static function prose(string $question): string
    {
        return trim((string) preg_replace(self::TAG_PATTERN, '', trim($question)));
    }
}
