<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallet\RecoverEvmAddress;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Notifications in Telegram" from the wallet, in one tap.
 *
 * The wallet signs a challenge naming its address; this answers with a
 * one-time deep link into the bot. Opening it in Telegram is what proves the
 * Telegram side, and the bot then links that account to the address
 * (`tg_wallets`) — the mapping reminders and personal notifications go by.
 * Without the signature anyone could have the bot report on an address that
 * isn't theirs.
 */
class WalletTelegramLinkController extends Controller
{
    private const NONCE_TTL_SECONDS = 300;

    private const LINK_TTL_MINUTES = 30;

    public function __construct(private RecoverEvmAddress $recover) {}

    public function nonce(Request $request): JsonResponse
    {
        $address = $this->requestedAddress($request);
        $nonce = Str::random(40);

        Cache::put($this->nonceKey($address), $nonce, self::NONCE_TTL_SECONDS);

        return response()->json(['message' => $this->challenge($address, $nonce)]);
    }

    public function link(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'signature' => ['required', 'string'],
        ]);

        $address = Str::lower($data['address']);
        // Pulled, not read: a challenge answers once.
        $nonce = Cache::pull($this->nonceKey($address));

        if (! is_string($nonce)) {
            return response()->json(['message' => 'That challenge expired. Ask for a new one.'], 422);
        }

        $signer = $this->recover->handle($this->challenge($address, $nonce), $data['signature']);

        if ($signer === null || Str::lower($signer) !== $address) {
            return response()->json(['message' => 'That signature was not made by this address.'], 401);
        }

        $bot = trim((string) config('services.telegram_bot.username', ''), "@ \t\n");

        if ($bot === '') {
            return response()->json(['message' => 'No Telegram bot is configured.'], 503);
        }

        // Telegram allows A-Z, a-z, 0-9, _ and - in a start parameter, up to 64.
        $token = Str::random(32);
        $now = Carbon::now('UTC');

        DB::table('tg_wallet_links')->insert([
            'token' => $token,
            'address' => $address,
            'expires_at' => $now->copy()->addMinutes(self::LINK_TTL_MINUTES)->toDateTimeString(),
            'created_at' => $now->toDateTimeString(),
        ]);

        return response()->json(['url' => "https://t.me/{$bot}?start=link_{$token}"]);
    }

    private function requestedAddress(Request $request): string
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        return Str::lower($data['address']);
    }

    private function nonceKey(string $address): string
    {
        return "wallet-telegram-nonce:{$address}";
    }

    /** The text the wallet signs; it names the address and moves nothing. */
    private function challenge(string $address, string $nonce): string
    {
        return implode("\n", [
            'Cyberia wallet — send notifications about this address to Telegram.',
            'This signature proves the address below. It moves no funds and approves no transaction.',
            "Address: {$address}",
            "Nonce: {$nonce}",
        ]);
    }
}
