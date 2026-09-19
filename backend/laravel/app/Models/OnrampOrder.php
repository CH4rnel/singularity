<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One card purchase, as this server saw it happen elsewhere.
 *
 * The provider is the authority on the money — it took the card, it did the
 * KYC, it signed the delivery — and the chain is the authority on whether
 * anything arrived. This row is the authority on one thing only: that somebody
 * here started this purchase, on these terms, for this address. Without it a
 * purchase made on a provider's page is invisible to the wallet that sent the
 * person there, and the wallet can neither say "your order is on its way" nor
 * count how many of them a day get abandoned.
 *
 * Nothing on this row identifies a person beyond the address they asked to be
 * paid at, which is already public on the chain where it is paid.
 */
class OnrampOrder extends Model
{
    protected $fillable = [
        'reference',
        'provider',
        'provider_order_id',
        'user_id',
        'status',
        'fiat',
        'fiat_amount',
        'crypto_amount',
        'chain',
        'asset',
        'address',
        'method',
        'tx_hash',
        'delivered_at',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
    ];

    /**
     * What the wallet is allowed to read back.
     *
     * The reference is included because the browser already holds it — it is
     * in the redirect it came back on — and everything else here is what that
     * browser typed in the first place plus what the provider reported.
     */
    public function present(): array
    {
        return [
            'reference' => $this->reference,
            'provider' => $this->provider,
            'status' => $this->status,
            'fiat' => $this->fiat,
            'fiat_amount' => $this->fiat_amount,
            'crypto_amount' => $this->crypto_amount,
            'chain' => $this->chain,
            'asset' => $this->asset,
            'address' => $this->address,
            'method' => $this->method,
            'tx' => $this->tx_hash,
            'at' => $this->created_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ];
    }
}
