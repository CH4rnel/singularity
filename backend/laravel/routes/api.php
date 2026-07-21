<?php

use App\Http\Controllers\Api\BridgeController;
use App\Http\Controllers\Api\BridgeEventController;
use App\Http\Controllers\Api\LaunchpadController;
use App\Http\Controllers\Api\NFTController;
use App\Http\Controllers\Api\SlotsController;
use App\Http\Controllers\Api\SolanaWalletAuthController;
use App\Http\Controllers\Api\TgWhaleController;
use App\Http\Controllers\Api\WalletAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

// EVM wallet auth (MetaMask)
Route::prefix('wallet')->group(function () {
    Route::post('nonce', [WalletAuthController::class, 'generateNonce']);
    Route::post('verify', [WalletAuthController::class, 'verify']);
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

// Cyberia RPC proxy (avoids mixed content on HTTPS sites)
Route::post('rpc/cyberia', function (Request $request) {
    $response = Http::post(config('services.ethereum.rpc_url', 'https://rpc.cyberia.church'), $request->all());

    return response($response->body(), $response->status())
        ->header('Content-Type', 'application/json');
});
