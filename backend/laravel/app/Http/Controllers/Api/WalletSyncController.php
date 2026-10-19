<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The profile's wallet, as ciphertext.
 *
 * The browser seals the vault under the sync password (PBKDF2-SHA-256 →
 * AES-256-GCM, the same scheme as a password-protected vault) and only the
 * result comes here. Replacing a backup that holds a different wallet needs
 * `replace`, so one device can't silently overwrite the wallet another device
 * restored from.
 */
class WalletSyncController extends Controller
{
    private const MIN_ITERATIONS = 310_000;

    public function show(Request $request): JsonResponse
    {
        $backup = $request->user()->walletSyncBackup()->first();

        return response()->json([
            'backup' => $backup ? [
                'address' => $backup->address,
                'payload' => $backup->payload,
                'updatedAt' => $backup->updated_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'replace' => ['sometimes', 'boolean'],
            'payload' => ['required', 'array'],
            'payload.version' => ['required', 'integer', 'in:2'],
            'payload.kdf' => ['required', 'string', 'in:PBKDF2-SHA-256'],
            'payload.iterations' => ['required', 'integer', 'min:'.self::MIN_ITERATIONS, 'max:10000000'],
            'payload.salt' => ['required', 'string', 'max:64'],
            'payload.iv' => ['required', 'string', 'max:64'],
            'payload.ciphertext' => ['required', 'string', 'max:262144'],
        ]);

        $user = $request->user();
        $address = Str::lower($data['address']);
        $existing = $user->walletSyncBackup()->first();

        if ($existing && $existing->address !== $address && ! ($data['replace'] ?? false)) {
            return response()->json([
                'message' => 'This profile keeps a different wallet.',
                'address' => $existing->address,
            ], 409);
        }

        $payload = collect($data['payload'])
            ->only(['version', 'kdf', 'iterations', 'salt', 'iv', 'ciphertext'])
            ->all();

        $user->walletSyncBackup()->updateOrCreate([], [
            'address' => $address,
            'payload' => $payload,
        ]);

        return response()->json(['address' => $address]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->walletSyncBackup()->delete();

        return response()->json(['backup' => null]);
    }
}
