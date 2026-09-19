<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OnrampOrder;
use App\Services\Onramp\OnrampRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buying crypto with a card, from inside the wallet.
 *
 * Four reads, one write and one ear. Nothing here takes a card number, nothing
 * here holds money, and — as everywhere else under `/wallet` — nothing here
 * needs an account: the destination is an address the browser derived, and
 * requiring a login in front of somebody's first purchase would be a login in
 * front of the only screen whose entire job is a first purchase.
 *
 * The reason the browser does not simply open a provider's widget itself is in
 * `OnrampRouter`: one of the four refuses an unsigned handoff, and the secret
 * that signs it cannot live in a bundle; and a purchase that leaves for
 * somebody else's page is one the wallet could otherwise never say another
 * word about.
 */
class WalletOnrampController extends Controller
{
    public function __construct(private readonly OnrampRouter $router) {}

    /** What can be bought, where it lands, who might sell it — and who cannot. */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->router->catalogue($request->query('country')));
    }

    /**
     * Every provider's price for one purchase, best first.
     *
     * The amount is a decimal string, validated as one: a JSON number here
     * would arrive at four different providers with four different roundings.
     */
    public function quote(Request $request): JsonResponse
    {
        if (! $this->router->enabled()) {
            return response()->json(['error' => 'onramp_disabled'], 422);
        }

        $data = $this->validated($request);
        $purchase = $this->router->request($data);

        if ($purchase === null) {
            return response()->json(['error' => 'delivery_unknown'], 422);
        }

        return response()->json(['offers' => $this->router->quotes($purchase)]);
    }

    /**
     * Start one, and hand back a URL on the provider's own origin.
     *
     * A session is used when there is one — a purchase by somebody signed in
     * belongs on their row — and its absence is not an obstacle.
     */
    public function checkout(Request $request): JsonResponse
    {
        if (! $this->router->enabled()) {
            return response()->json(['error' => 'onramp_disabled'], 422);
        }

        $data = $this->validated($request, ['provider' => ['required', 'string', 'max:32']]);
        $provider = $this->router->provider((string) $data['provider']);
        $purchase = $this->router->request($data);

        if ($provider === null || $purchase === null) {
            return response()->json(['error' => 'delivery_unknown'], 422);
        }

        $handoff = $this->router->checkout($provider, $purchase, $request->user()?->id);

        return $handoff['ok']
            ? response()->json($handoff, 201)
            : response()->json(['error' => $handoff['reason']], 422);
    }

    /**
     * Where one purchase has got to.
     *
     * Read by the reference the browser is holding, which is a v4 uuid nobody
     * enumerates — and the row it returns carries only what that browser typed
     * plus what the provider reported about it.
     */
    public function order(string $reference): JsonResponse
    {
        $order = OnrampOrder::query()->where('reference', $reference)->first();

        return $order === null
            ? response()->json(['error' => 'unknown_order'], 404)
            : response()->json(['order' => $order->present()]);
    }

    /**
     * A provider telling us what happened after the redirect.
     *
     * Always 202, and deliberately the same 202 for a good payload, a forged
     * one and an order we never started: a webhook endpoint that answers
     * differently is an oracle for guessing references, and every provider
     * here retries on anything that is not a 2xx — which would turn one
     * unverifiable POST into a permanent retry loop.
     */
    public function webhook(Request $request, string $name): JsonResponse
    {
        $provider = $this->router->provider($name);
        $event = $provider?->webhook($request);

        if ($provider !== null && is_array($event)) {
            $this->router->record($provider, $event);
        }

        return response()->json(['ok' => true], 202);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, array $extra = []): array
    {
        return $request->validate(array_merge([
            'fiat' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            // A decimal string, and never scientific notation: what is typed
            // is what four providers are asked, character for character.
            'amount' => ['required', 'string', 'regex:/^[0-9]{1,9}(\.[0-9]{1,2})?$/'],
            'country' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'method' => ['required', 'string', 'in:card,bank,apple_pay,google_pay,paypal'],
            'delivery' => ['required', 'string', 'max:64'],
            /*
             * The two address shapes this wallet can actually receive on: EVM
             * and Solana. Loose enough for both, strict enough that a typo is
             * refused here rather than at a provider that would have paid it.
             */
            'address' => ['required', 'string', 'regex:/^(0x[0-9a-fA-F]{40}|[1-9A-HJ-NP-Za-km-z]{32,44})$/'],
        ], $extra));
    }
}
