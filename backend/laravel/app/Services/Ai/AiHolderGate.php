<?php

namespace App\Services\Ai;

use App\Exceptions\AiApiException;
use App\Services\Erc20SupplyReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Who may use the inference API: an address holding its share of the gate
 * token on Cyberia.
 *
 * The key is not the membership — the holding is. A key only says which
 * address is claiming it, and the balance behind that address is re-read on
 * every request (cached for a minute, so a conversation does not hammer the
 * node). Selling the position closes the API the same way it closes the
 * holders' room, without anyone having to revoke anything.
 *
 * The gate fails closed. An unreadable chain is not a passing balance: if the
 * RPC cannot be reached the request is refused with a 503, because the
 * alternative is an open API every time the node hiccups.
 */
class AiHolderGate
{
    public function __construct(private Erc20SupplyReader $erc20) {}

    /**
     * @return array{address: string, token: string, symbol: string, balance: string, total_supply: string, minimum_balance: string, share_bps: int, minimum_share_bps: int, qualifies: bool}
     */
    public function status(string $address): array
    {
        $address = Str::lower($address);

        return Cache::remember(
            "ai-gate:{$this->token()}:{$address}",
            max(1, (int) config('ai.gate.cache_seconds', 60)),
            fn () => $this->read($address),
        );
    }

    /**
     * The same check, as a precondition: it either returns the status or ends
     * the request.
     *
     * @return array{address: string, token: string, symbol: string, balance: string, total_supply: string, minimum_balance: string, share_bps: int, minimum_share_bps: int, qualifies: bool}
     */
    public function assert(string $address): array
    {
        try {
            $status = $this->status($address);
        } catch (Throwable $e) {
            Log::warning('AI API gate could not read the chain', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);

            throw AiApiException::upstream(
                'The holder gate could not read Cyberia right now. Try again shortly.',
                'gate_unreadable',
                503,
            );
        }

        if (! $status['qualifies']) {
            throw AiApiException::forbidden(sprintf(
                'This key’s address holds %s of the %s supply; %s is required.',
                $this->percent($status['share_bps']),
                $status['symbol'],
                $this->percent($status['minimum_share_bps']),
            ));
        }

        return $status;
    }

    /** Basis points as a human percentage: 50 → "0.5%". */
    public function percent(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.').'%';
    }

    /** What the gate asks of a holder, for the public /v1/gate description. */
    public function terms(): array
    {
        return [
            'token' => $this->token(),
            'symbol' => (string) config('ai.gate.token_symbol', 'LAIN'),
            'chain_id' => 49406,
            'minimum_share_bps' => $this->minimumShareBps(),
            'minimum_share' => $this->percent($this->minimumShareBps()),
        ];
    }

    /**
     * @return array{address: string, token: string, symbol: string, balance: string, total_supply: string, minimum_balance: string, share_bps: int, minimum_share_bps: int, qualifies: bool}
     */
    private function read(string $address): array
    {
        $token = $this->token();
        $minimum = $this->minimumShareBps();

        ['balance' => $balance, 'total_supply' => $supply] = $this->erc20->holding(
            (string) config('ai.gate.rpc_url', 'https://rpc.cyberia.church'),
            $token,
            $address,
        );

        if (bccomp($supply, '0') <= 0) {
            throw new \RuntimeException('The gate token reports no supply.');
        }

        return [
            'address' => $address,
            'token' => $token,
            'symbol' => (string) config('ai.gate.token_symbol', 'LAIN'),
            'balance' => $balance,
            'total_supply' => $supply,
            'minimum_balance' => $this->erc20->minimumBalance($supply, $minimum),
            'share_bps' => $this->erc20->shareBps($balance, $supply),
            'minimum_share_bps' => $minimum,
            // Compared as a cross-product, not as a rounded share: an address
            // one wei short of the threshold must not round its way in.
            'qualifies' => bccomp(bcmul($balance, '10000'), bcmul($supply, (string) $minimum)) >= 0,
        ];
    }

    private function token(): string
    {
        return Str::lower((string) config('ai.gate.token_address'));
    }

    private function minimumShareBps(): int
    {
        return max(0, (int) config('ai.gate.minimum_share_bps', 50));
    }
}
