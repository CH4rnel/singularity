<?php

namespace App\Support;

use App\Models\BridgeRequest;
use InvalidArgumentException;

class BridgeAmount
{
    /** @return array{numerator: string, denominator: string} */
    public static function rate(string $direction, string $token, ?BridgeRequest $request = null): array
    {
        $routes = config('bridge.routes', []);
        $rate = $routes[$direction]['conversion_rates'][$token] ?? ['numerator' => '1', 'denominator' => '1'];
        $numerator = (string) ($request?->conversion_numerator ?? $rate['numerator'] ?? '');
        $denominator = (string) ($request?->conversion_denominator ?? $rate['denominator'] ?? '');

        foreach ([$numerator, $denominator] as $value) {
            if (! preg_match('/^[1-9][0-9]{0,35}$/D', $value)) {
                throw new InvalidArgumentException('Invalid bridge conversion rate.');
            }
        }

        return ['numerator' => $numerator, 'denominator' => $denominator];
    }

    public static function destination(string $direction, string $token, string $sourceNet, ?BridgeRequest $request = null): string
    {
        $rate = self::rate($direction, $token, $request);
        $amount = bcdiv(bcmul($sourceNet, $rate['numerator'], 36), $rate['denominator'], 36);
        $routes = config('bridge.routes', []);
        $tokens = config('bridge.tokens', []);
        $chain = $routes[$direction]['destination_chain'] ?? '';
        $decimals = (int) ($tokens[$token]['chains'][$chain]['decimals'] ?? 18);

        return TokenAmount::fromRaw(TokenAmount::toRaw($amount, $decimals), $decimals);
    }

    /** Destination gas is charged in source units, rounded up to one source atomic unit. */
    public static function sourceFee(string $direction, string $token, string $destinationFee, ?BridgeRequest $request = null): string
    {
        $rate = self::rate($direction, $token, $request);
        if ($rate['numerator'] === $rate['denominator']) {
            return $destinationFee;
        }

        $fee = bcdiv(bcmul($destinationFee, $rate['denominator'], 36), $rate['numerator'], 36);
        $routes = config('bridge.routes', []);
        $tokens = config('bridge.tokens', []);
        $chain = $routes[$direction]['source_chain'] ?? '';
        $decimals = (int) ($tokens[$token]['chains'][$chain]['decimals'] ?? 18);
        $raw = TokenAmount::toRaw($fee, $decimals);

        if (bccomp(TokenAmount::fromRaw($raw, $decimals), $fee, 36) < 0) {
            $raw = bcadd($raw, '1', 0);
        }

        return TokenAmount::fromRaw($raw, $decimals);
    }
}
