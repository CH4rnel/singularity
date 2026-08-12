<?php

namespace App\Services\Predictions;

use App\Services\BridgeRelayerService;
use App\Support\Environment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * The oracle, as a plan and a hand.
 *
 * `plan()` is pure: markets in, decisions out, no clock and no network of its
 * own. Everything that decides money is therefore testable without a chain,
 * and the part that can lose money — signing — has no opinions in it.
 *
 * Three things can happen to a market that has closed:
 *
 *   - it carries a price tag this build can read, and a live quote answers it:
 *     resolved YES or NO;
 *   - it does not, so a human owes it an answer: reported, every run, with the
 *     time left before the contract's refund window makes the question moot;
 *   - nobody answered and that window is about to lapse: cancelled, which
 *     refunds every bettor in full.
 *
 * The last one is the point. Left alone, an unresolved market simply rots for
 * thirty days and then becomes permanently unresolvable — which is how both
 * markets on the live contract died. Cancelling a few days early turns a
 * silent trap into a visible refund, and costs the losing side nothing they
 * were owed.
 */
class PredictionsResolver
{
    /** enum Outcome { None, Yes, No, Invalid } */
    public const OUTCOME_NONE = 0;

    public const OUTCOME_YES = 1;

    public const OUTCOME_NO = 2;

    public const OUTCOME_INVALID = 3;

    public function __construct(
        private PredictionMarketReader $reader,
        private BridgeRelayerService $relayer,
    ) {}

    /**
     * Decide what to do with every market, without touching a clock or a wire.
     *
     * @param  array<int, array{id: int, question: string, closeTime: int, outcome: int, yesPool: string, noPool: string}>  $markets
     * @param  array<string, float|null>  $prices  keyed by WalletPriceService quote key
     * @return array{
     *     resolve: array<int, array{id: int, outcome: int, reason: string, pot: string}>,
     *     pending: array<int, array{id: int, question: string, reason: string, deadline: int, pot: string}>,
     *     expired: array<int, array{id: int, question: string, pot: string}>,
     * }
     */
    public function plan(array $markets, array $prices, int $now, int $window, int $graceSeconds): array
    {
        $out = ['resolve' => [], 'pending' => [], 'expired' => []];

        foreach ($markets as $market) {
            if ($market['outcome'] !== self::OUTCOME_NONE || $now < $market['closeTime']) {
                continue;
            }

            $deadline = $market['closeTime'] + $window;
            $pot = bcadd($market['yesPool'], $market['noPool']);

            // Past the window the contract refuses every outcome, so there is
            // nothing to send — only something to say.
            if ($now > $deadline) {
                $out['expired'][] = [
                    'id' => $market['id'],
                    'question' => $market['question'],
                    'pot' => $pot,
                ];

                continue;
            }

            $decision = $this->decidePrice($market['question'], $prices);

            if ($decision !== null) {
                $out['resolve'][] = [
                    'id' => $market['id'],
                    'outcome' => $decision['outcome'],
                    'reason' => $decision['reason'],
                    'pot' => $pot,
                ];

                continue;
            }

            $reason = $this->pendingReason($market['question'], $prices);

            if ($now >= $deadline - $graceSeconds) {
                $out['resolve'][] = [
                    'id' => $market['id'],
                    'outcome' => self::OUTCOME_INVALID,
                    'reason' => 'cancelled: '.$reason.', refund window closes '
                        .gmdate('Y-m-d H:i', $deadline).'Z',
                    'pot' => $pot,
                ];

                continue;
            }

            $out['pending'][] = [
                'id' => $market['id'],
                'question' => $market['question'],
                'reason' => $reason,
                'deadline' => $deadline,
                'pot' => $pot,
            ];
        }

        return $out;
    }

    /**
     * YES/NO for a tagged question whose asset has a live quote, else null.
     *
     * The comparison is strict, matching what the question says in words: a
     * price that lands exactly on the threshold is not "above" it.
     *
     * @param  array<string, float|null>  $prices
     * @return array{outcome: int, reason: string}|null
     */
    private function decidePrice(string $question, array $prices): ?array
    {
        $spec = PredictionQuestion::parsePrice($question);

        if ($spec === null) {
            return null;
        }

        $price = $prices[$spec['quote']] ?? null;

        if (! is_numeric($price) || (float) $price <= 0.0) {
            return null;
        }

        $price = (float) $price;
        $threshold = (float) $spec['threshold'];
        $hit = $spec['above'] ? $price > $threshold : $price < $threshold;

        return [
            'outcome' => $hit ? self::OUTCOME_YES : self::OUTCOME_NO,
            'reason' => sprintf(
                '%s $%s %s $%s (%s)',
                $spec['symbol'],
                rtrim(rtrim(number_format($price, 6, '.', ''), '0'), '.'),
                $spec['above'] ? '>' : '<',
                $spec['threshold'],
                $spec['source'],
            ),
        ];
    }

    /**
     * @param  array<string, float|null>  $prices
     */
    private function pendingReason(string $question, array $prices): string
    {
        $spec = PredictionQuestion::parsePrice($question);

        if ($spec === null) {
            return 'free-form question, needs a human';
        }

        return sprintf('no %s quote for %s', $spec['source'], $spec['symbol']);
    }

    /**
     * Send the decisions. Returns one result row per market, in order.
     *
     * @param  array<int, array{id: int, outcome: int}>  $decisions
     * @return array<int, array{id: int, outcome: int, ok: bool, txHash?: string, error?: string}>
     */
    public function submit(array $decisions): array
    {
        $contract = $this->reader->contractAddress();
        $key = $this->relayer->privateKey();

        if ($contract === null || $decisions === []) {
            return [];
        }

        if (! $key) {
            Log::error('predictions: relayer key unavailable, nothing resolved');

            return array_map(fn (array $d): array => [
                'id' => $d['id'],
                'outcome' => $d['outcome'],
                'ok' => false,
                'error' => 'relayer key unavailable',
            ], $decisions);
        }

        $args = array_map(
            fn (array $d): string => $d['id'].':'.$d['outcome'],
            $decisions,
        );

        $hardhatDir = Environment::isProduction()
            ? '/singularity/crypto/hardhat'
            : base_path('/../../crypto/hardhat');

        try {
            $result = Process::path($hardhatDir)
                ->env([
                    'EVM_RPC_URL' => (string) config('predictions.rpc_url', 'https://rpc.cyberia.church'),
                    'EVM_CHAIN_ID' => (string) config('predictions.chain_id', 49406),
                    'CYBERIA_RPC_URL' => (string) config('predictions.rpc_url', 'https://rpc.cyberia.church'),
                    'BRIDGE_RELAYER_PRIVATE_KEY' => $key,
                ])
                // Transactions go out one at a time on purpose; give the whole
                // batch room rather than racing the shared relayer nonce.
                ->timeout(30 + 20 * count($args))
                ->run(['npx', 'tsx', 'scripts/predictions-resolve.ts', $contract, ...$args]);
        } catch (\Throwable $e) {
            Log::error('predictions: resolve script failed to start', ['error' => $e->getMessage()]);

            return array_map(fn (array $d): array => [
                'id' => $d['id'],
                'outcome' => $d['outcome'],
                'ok' => false,
                'error' => $e->getMessage(),
            ], $decisions);
        }

        $rows = $this->parseScriptOutput($result->output());

        if ($rows === [] || $result->exitCode() !== 0) {
            Log::error('predictions: resolve script reported nothing usable', [
                'exit' => $result->exitCode(),
                'stderr' => trim($result->errorOutput()),
            ]);
        }

        // Anything the script never reported on did not happen.
        return array_map(function (array $d) use ($rows): array {
            $row = $rows[$d['id']] ?? null;

            return $row ?? [
                'id' => $d['id'],
                'outcome' => $d['outcome'],
                'ok' => false,
                'error' => 'no result reported',
            ];
        }, $decisions);
    }

    /**
     * @return array<int, array{id: int, outcome: int, ok: bool, txHash?: string, error?: string}>
     */
    private function parseScriptOutput(string $output): array
    {
        $rows = [];

        foreach (explode("\n", trim($output)) as $line) {
            $json = json_decode(trim($line), true);

            if (is_array($json) && isset($json['id'])) {
                $rows[(int) $json['id']] = [
                    'id' => (int) $json['id'],
                    'outcome' => (int) ($json['outcome'] ?? 0),
                    'ok' => (bool) ($json['ok'] ?? false),
                    'txHash' => isset($json['txHash']) ? (string) $json['txHash'] : null,
                    'error' => isset($json['error']) ? (string) $json['error'] : null,
                ];
            }
        }

        return $rows;
    }
}
