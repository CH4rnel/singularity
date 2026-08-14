<?php

use App\Http\Controllers\Api\Ai\AiKeyController;
use App\Http\Controllers\Api\Ai\ChatCompletionsController;
use App\Http\Controllers\Api\Ai\ModelsController;
use App\Http\Controllers\Api\BridgeController;
use App\Http\Controllers\Api\BridgeEventController;
use App\Http\Controllers\Api\LaunchpadController;
use App\Http\Controllers\Api\NFTController;
use App\Http\Controllers\Api\SlotsController;
use App\Http\Controllers\Api\SolanaWalletAuthController;
use App\Http\Controllers\Api\TgWhaleController;
use App\Http\Controllers\Api\WalletAuthController;
use App\Http\Controllers\Api\WalletGasController;
use App\Http\Controllers\Api\WalletIpfsController;
use App\Http\Middleware\AuthenticateAiApiKey;
use App\Services\WalletPriceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

// EVM wallet auth (MetaMask)
Route::prefix('wallet')->group(function () {
    Route::post('nonce', [WalletAuthController::class, 'generateNonce']);
    Route::post('verify', [WalletAuthController::class, 'verify']);

    // USD quotes for the unified wallet's portfolio total. Public and cached:
    // it says nothing about any account, only what a coin is worth.
    Route::get('prices', fn (WalletPriceService $prices) => response()->json($prices->quotes()))
        ->middleware('throttle:60,1');

    // Pinning for the wallet: bytes in, a CID out. Kubo listens on localhost
    // and can run any node command, so the browser never talks to it directly.
    Route::post('ipfs/file', [WalletIpfsController::class, 'file'])->middleware('throttle:20,1');
    Route::post('ipfs/page', [WalletIpfsController::class, 'page'])->middleware('throttle:20,1');

    // Sponsored fees on Cyberia: an address that owns something here but holds
    // no CYBER is handed enough CYBER to move it. Unsigned on purpose — a drip
    // can only ever arrive at the address that was named. See GasSponsorService.
    Route::get('gas', [WalletGasController::class, 'status'])->middleware('throttle:60,1');
    Route::post('gas/claim', [WalletGasController::class, 'claim'])->middleware('throttle:10,1');
});

// Solana wallet auth (Phantom)
Route::prefix('solana-wallet')->group(function () {
    Route::post('nonce', [SolanaWalletAuthController::class, 'generateNonce']);
    Route::post('verify', [SolanaWalletAuthController::class, 'verify']);
});

// Telegram "whales chat" gate — prove Phantom ownership + read CYBER.sol balance.
Route::prefix('tg/cyber-sol')->group(function () {
    Route::post('nonce', [TgWhaleController::class, 'nonce'])->middleware('throttle:30,1');
    Route::post('verify', [TgWhaleController::class, 'verify'])->middleware('throttle:30,1');
});

// Bridge (public)
Route::get('bridge/{bridgeRequest}/status', [BridgeController::class, 'status']);
Route::post('bridge/events', [BridgeEventController::class, 'store'])->middleware('throttle:60,1');

// Slot machine ("одноручный бандит") — Solana-only, decoupled from bridge.
Route::prefix('slots')->group(function () {
    Route::get('pool', [SlotsController::class, 'pool']);
    Route::post('spin/prepare', [SlotsController::class, 'prepare'])->middleware('throttle:6,1');
    Route::post('spin/confirm', [SlotsController::class, 'confirm'])->middleware('throttle:30,1');
});

// NFT metadata pin (image + ERC-721 JSON) → tokenURI
Route::post('nft/upload', [NFTController::class, 'upload'])->middleware('throttle:30,1');

// Launchpad off-chain metadata and sandboxed token sites.
Route::prefix('launchpad')->group(function () {
    Route::get('tokens', [LaunchpadController::class, 'index']);
    Route::post('tokens', [LaunchpadController::class, 'store'])->middleware('throttle:30,1');
});

/*
 * The Cyberia inference API: OpenAI-compatible endpoints in front of providers
 * whose keys live on this server, opened by holding the gate token rather than
 * by signing up. See config/ai.php and docs/ai-api.md.
 *
 * Two halves with different credentials. Key self-service is proved by a
 * wallet signature (no session, no account); the API itself is proved by the
 * key that flow hands out, checked in AuthenticateAiApiKey together with the
 * holding behind it and the quota on it.
 */
Route::prefix('ai')->group(function () {
    Route::post('keys/nonce', [AiKeyController::class, 'nonce'])->middleware('throttle:30,1');
    Route::post('keys', [AiKeyController::class, 'store'])->middleware('throttle:10,1');
    Route::post('keys/list', [AiKeyController::class, 'index'])->middleware('throttle:30,1');
    Route::post('keys/revoke', [AiKeyController::class, 'revoke'])->middleware('throttle:30,1');

    Route::prefix('v1')->group(function () {
        // Public: what this is, and what it serves. Both are things you need
        // before you hold anything, so neither sits behind the gate.
        Route::get('/', [ModelsController::class, 'root'])->middleware('throttle:60,1');
        Route::get('models', [ModelsController::class, 'index'])->middleware('throttle:60,1');

        Route::middleware(AuthenticateAiApiKey::class)->group(function () {
            Route::get('me', [AiKeyController::class, 'status']);
            // Per-key limits are enforced in the middleware; this coarse IP
            // throttle is only there to keep a flood off the database.
            Route::post('chat/completions', [ChatCompletionsController::class, 'store'])
                ->middleware('throttle:120,1');
        });
    });
});

// Cyberia RPC proxy (avoids mixed content on HTTPS sites)
Route::post('rpc/cyberia', function (Request $request) {
    $response = Http::post(config('services.ethereum.rpc_url', 'https://rpc.cyberia.church'), $request->all());

    return response($response->body(), $response->status())
        ->header('Content-Type', 'application/json');
});
