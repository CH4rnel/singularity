<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LaunchpadToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LaunchpadController extends Controller
{
    /** Return metadata for every token registered with the Launchpad. */
    public function index(): JsonResponse
    {
        $tokens = LaunchpadToken::orderByDesc('created_at')->get()->map(function (LaunchpadToken $t) {
            return [
                'address' => strtolower($t->address),
                'creator' => $t->creator ? strtolower($t->creator) : null,
                'name' => $t->name,
                'symbol' => $t->symbol,
                'description' => $t->description,
                'image_url' => $t->image_path ? Storage::disk('public')->url($t->image_path) : null,
            ];
        });

        return response()->json(['tokens' => $tokens]);
    }

    /**
     * Save off-chain metadata (description + image) for a token launched via
     * the on-chain Launchpad contract. We trust the address the caller sends;
     * the front-end only stores entries for addresses it has just successfully
     * launched, and the UI later cross-references with on-chain events before
     * displaying anything.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'creator' => ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'name' => ['nullable', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'max:4096'], // 4 MB
        ]);

        $address = strtolower($data['address']);

        $existing = LaunchpadToken::find($address);

        $payload = [
            'address' => $address,
            'creator' => isset($data['creator']) ? strtolower($data['creator']) : ($existing->creator ?? null),
            'name' => $data['name'] ?? ($existing->name ?? null),
            'symbol' => $data['symbol'] ?? ($existing->symbol ?? null),
            'description' => $data['description'] ?? ($existing->description ?? null),
            'image_path' => $existing->image_path ?? null,
        ];

        if ($request->hasFile('image')) {
            // Drop the previous image if a new one is uploaded.
            if (! empty($payload['image_path'])) {
                Storage::disk('public')->delete($payload['image_path']);
            }
            $payload['image_path'] = $request->file('image')->store('launchpad', 'public');
        }

        $token = LaunchpadToken::updateOrCreate(['address' => $address], $payload);

        return response()->json([
            'token' => [
                'address' => strtolower($token->address),
                'creator' => $token->creator ? strtolower($token->creator) : null,
                'name' => $token->name,
                'symbol' => $token->symbol,
                'description' => $token->description,
                'image_url' => $token->image_path ? Storage::disk('public')->url($token->image_path) : null,
            ],
        ]);
    }
}
