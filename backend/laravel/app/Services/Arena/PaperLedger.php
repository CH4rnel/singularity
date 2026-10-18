<?php

namespace App\Services\Arena;

use DomainException;

class PaperLedger
{
    private const WEI = '1000000000000000000';

    /** @param array{cash: string, asset: string} $balance
     * @param  array{action: string, bps: int}  $decision
     * @param  array<string, mixed>  $rules
     * @return array{cash: string, asset: string}
     */
    public function apply(array $balance, array $decision, string $price, array $rules): array
    {
        $this->unsigned($balance['cash']);
        $this->unsigned($balance['asset']);
        $this->unsigned($price, positive: true);
        $this->validateRules($rules);
        $action = $decision['action'];
        $bps = $decision['bps'];
        if (! in_array($action, ['hold', 'buy', 'sell'], true)
            || ($action === 'hold' ? $bps !== 0 : ! in_array($bps, [2500, 5000, 10000], true))) {
            throw new DomainException('Invalid paper action.');
        }
        if ($action === 'hold') {
            return $balance;
        }
        $gas = $rules['gas_micros'];
        if ($action === 'buy') {
            $spend = bcdiv(bcmul($balance['cash'], (string) $bps), '10000', 0);
            $usable = bcsub($spend, $gas, 0);
            if (bccomp($usable, '0', 0) <= 0) {
                throw new DomainException('Insufficient paper cash.');
            }
            $net = $this->afterCosts($usable, $rules);
            $asset = bcdiv(bcmul($net, self::WEI), $price, 0);
            if (bccomp($asset, '0', 0) <= 0) {
                throw new DomainException('Paper order rounds to zero.');
            }

            return ['cash' => bcsub($balance['cash'], $spend, 0), 'asset' => bcadd($balance['asset'], $asset, 0)];
        }
        $amount = bcdiv(bcmul($balance['asset'], (string) $bps), '10000', 0);
        if (bccomp($amount, '0', 0) <= 0) {
            throw new DomainException('Insufficient paper asset.');
        }
        $gross = bcdiv(bcmul($amount, $price), self::WEI, 0);
        $net = bcsub($this->afterCosts($gross, $rules), $gas, 0);
        if (bccomp($net, '0', 0) < 0) {
            throw new DomainException('Paper sale cannot pay its modeled cost.');
        }

        return ['cash' => bcadd($balance['cash'], $net, 0), 'asset' => bcsub($balance['asset'], $amount, 0)];
    }

    /** @param array{cash: string, asset: string} $balance
     * @param  array<string, mixed>  $rules
     */
    public function equity(array $balance, string $price, array $rules): string
    {
        $this->unsigned($balance['cash']);
        $this->unsigned($balance['asset']);
        $this->unsigned($price, positive: true);
        $this->validateRules($rules);
        if (bccomp($balance['asset'], '0', 0) === 0) {
            return $balance['cash'];
        }
        $gross = bcdiv(bcmul($balance['asset'], $price), self::WEI, 0);
        $net = bcsub($this->afterCosts($gross, $rules), $rules['gas_micros'], 0);

        return bcadd($balance['cash'], bccomp($net, '0', 0) > 0 ? $net : '0', 0);
    }

    /** @param array<string, mixed> $rules */
    private function afterCosts(string $value, array $rules): string
    {
        $afterFee = bcdiv(bcmul($value, (string) (10000 - $rules['fee_bps'])), '10000', 0);

        return bcdiv(bcmul($afterFee, (string) (10000 - $rules['slippage_bps'])), '10000', 0);
    }

    /** @param array<string, mixed> $rules */
    private function validateRules(array $rules): void
    {
        foreach (['fee_bps', 'slippage_bps'] as $key) {
            if (! is_int($rules[$key] ?? null) || $rules[$key] < 0 || $rules[$key] > 1000) {
                throw new DomainException('Invalid paper cost configuration.');
            }
        }
        $this->unsigned($rules['gas_micros']);
    }

    private function unsigned(string $value, bool $positive = false): void
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,39})$/D', $value) || ($positive && $value === '0')) {
            throw new DomainException('Expected an unsigned raw integer amount.');
        }
    }
}
