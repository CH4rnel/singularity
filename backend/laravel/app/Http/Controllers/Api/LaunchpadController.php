<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallet\RecoverEvmAddress;
use App\Http\Controllers\Controller;
use App\Models\LaunchpadToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class LaunchpadController extends Controller
{
    public function __construct(private readonly RecoverEvmAddress $recoverEvmAddress)
    {
    }

    /** Return metadata for every token registered with the Launchpad. */
    public function index(): JsonResponse
    {
        $tokens = LaunchpadToken::orderByDesc('created_at')->get()->map(function (LaunchpadToken $t) {
            return $this->serialize($t);
        });

        return response()->json(['tokens' => $tokens]);
    }

    /**
     * Save off-chain metadata for a launched token. Gated on a personal-sign
     * signature by the token's creator (first signer wins; after that, only
     * that address can edit).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'message' => ['required', 'string', 'max:500'],
            'signature' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{130}$/'],
            'name' => ['nullable', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'max:2048'],
            'html' => ['nullable', 'file', 'mimes:html,htm,txt', 'max:2048'],
        ]);

        $address = Str::lower($data['address']);
        $message = $data['message'];
        $signature = $data['signature'];

        // 1. Message must reference this token and be recent (replay guard).
        if (! str_contains(Str::lower($message), $address)) {
            return response()->json([
                'message' => 'Signed message must include the token address.',
            ], 422);
        }
        if (! $this->messageIsFresh($message)) {
            return response()->json([
                'message' => 'Signed message is missing a recent timestamp (within 10 minutes).',
            ], 422);
        }

        // 2. Recover the signer.
        $signer = $this->recoverEvmAddress->handle($message, $signature);
        if (! $signer) {
            return response()->json(['message' => 'Invalid signature.'], 422);
        }
        $signer = Str::lower($signer);

        // 3. Authorize: signer must equal the stored creator. First touch claims
        //    ownership; subsequent edits require a match.
        $existing = LaunchpadToken::find($address);
        if ($existing && $existing->creator && Str::lower($existing->creator) !== $signer) {
            return response()->json([
                'message' => 'Only the token creator can edit this metadata.',
            ], 403);
        }

        $payload = [
            'address' => $address,
            'creator' => $signer, // First signer claims; later edits must match.
            'name' => $data['name'] ?? ($existing->name ?? null),
            'symbol' => $data['symbol'] ?? ($existing->symbol ?? null),
            'description' => $data['description'] ?? ($existing->description ?? null),
            'image_path' => $existing->image_path ?? null,
            'html_path' => $existing->html_path ?? null,
        ];

        if ($request->hasFile('image')) {
            if (! empty($payload['image_path'])) {
                Storage::disk('public')->delete($payload['image_path']);
            }
            $payload['image_path'] = $request->file('image')->store('launchpad', 'public');
        }

        if ($request->hasFile('html')) {
            if (! empty($payload['html_path'])) {
                Storage::disk('public')->delete($payload['html_path']);
            }
            $relative = 'launchpad-sites/'.$address.'.html';
            Storage::disk('public')->putFileAs(
                'launchpad-sites',
                $request->file('html'),
                $address.'.html',
            );
            $payload['html_path'] = $relative;
        }

        $token = LaunchpadToken::updateOrCreate(['address' => $address], $payload);

        return response()->json(['token' => $this->serialize($token)]);
    }

    /**
     * Serve the static HTML page uploaded for `$address`. Sent with a strict
     * Content-Security-Policy sandbox so it can't touch the main app's cookies
     * or hit same-origin endpoints — arbitrary HTML upload is otherwise an XSS
     * pit.
     */
    public function showSite(string $address): Response
    {
        $address = Str::lower($address);
        $token = LaunchpadToken::find($address);

        abort_if(! $token || ! $token->html_path, 404);
        abort_unless(Storage::disk('public')->exists($token->html_path), 404);

        $html = Storage::disk('public')->get($token->html_path);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => 'sandbox allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    /** Returns true if the message contains an ISO-ish timestamp not older than 10 min. */
    private function messageIsFresh(string $message): bool
    {
        if (! preg_match('/(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)/', $message, $m)) {
            return false;
        }
        try {
            $signed = strtotime($m[1]);
        } catch (\Throwable) {
            return false;
        }
        if ($signed === false) {
            return false;
        }

        return abs(time() - $signed) <= 600;
    }

    private function serialize(LaunchpadToken $t): array
    {
        return [
            'address' => Str::lower($t->address),
            'creator' => $t->creator ? Str::lower($t->creator) : null,
            'name' => $t->name,
            'symbol' => $t->symbol,
            'description' => $t->description,
            'image_url' => $t->image_path ? Storage::disk('public')->url($t->image_path) : null,
            'site_url' => $t->html_path ? URL::to('/launchpad/sites/'.Str::lower($t->address)) : null,
        ];
    }
}
