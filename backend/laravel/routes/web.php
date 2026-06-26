<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Api\BridgeController;
use App\Http\Controllers\Api\LaunchpadController;
use App\Http\Controllers\Api\TgWhaleController;
use App\Http\Controllers\Api\WalletAttachController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\Auth\Web3LoginController;
use App\Http\Controllers\BridgeAnalyticsController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CrmContactController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\CrmNoteController;
use App\Http\Controllers\DaoController;
use App\Http\Controllers\FediverseController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\ProposalCommentController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\ProposalVoteController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureBridgeAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->file(resource_path('views/landing/index.html')))->name('home');
Route::get('/bridge', [ApiController::class, 'index'])->name('bridge');
Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
Route::inertia('/market', 'Market')->name('market');
Route::inertia('/pixels', 'PixelBattle')->name('pixels');
// Fixed-rate redeemer for bridged CYBER.sol -> native CYBER. It's a conversion,
// not a swap, hence /convert; the old /cyber-sol-swap path 301s here.
Route::inertia('/convert', 'CyberSolSwap')->name('convert');
Route::permanentRedirect('/cyber-sol-swap', '/convert');
Route::inertia('/lending', 'Lending')->name('lending');
Route::inertia('/farm', 'Farm')->name('farm');
Route::inertia('/lending/liquidate', 'Liquidate')->name('lending.liquidate');
Route::inertia('/launchpad', 'Launchpad')->name('launchpad');
Route::get('/launchpad/sites/{address}', [LaunchpadController::class, 'showSite'])
    ->where('address', '0x[a-fA-F0-9]{40}')
    ->name('launchpad.site');
Route::inertia('/slots', 'Slots')->name('slots');
Route::get('dao', [DaoController::class, 'index'])->name('dao.index');
Route::get('dao/{dao}', [DaoController::class, 'show'])->name('dao.show');
Route::get('proposals/{proposal}', [ProposalController::class, 'show'])->name('proposals.show');

Route::post('login/web3', Web3LoginController::class)->name('web3.login');

Route::get('/wallet-login', fn () => inertia('auth/WalletLogin'))->name('wallet.login')->middleware('guest');

// Telegram whales-chat verification page (opened from the bot's one-time link).
Route::get('/tg/cyber-sol', [TgWhaleController::class, 'page'])->name('tg.cyber-sol');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['auth'])->group(function () {
    Route::get('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::resource('links', LinkController::class)->names([
        'index' => 'links',
        'store' => 'links.store',
        'update' => 'links.update',
        'destroy' => 'links.destroy',
    ]);
    Route::resource('categories', CategoryController::class)->except(['show', 'create', 'edit']);

    // Fediverse: resolve an ActivityPub handle/URL to its actor profile.
    Route::get('fediverse', [FediverseController::class, 'index'])->name('fediverse');
    Route::resource('dao', DaoController::class)->only(['store', 'update', 'destroy']);

    // Proposals (nested under dao)
    Route::post('dao/{dao}/proposals', [ProposalController::class, 'store'])->name('dao.proposals.store');

    // Proposal detail
    Route::put('proposals/{proposal}', [ProposalController::class, 'update'])->name('proposals.update');
    Route::delete('proposals/{proposal}', [ProposalController::class, 'destroy'])->name('proposals.destroy');

    // Comments on proposals
    Route::post('proposals/{proposal}/comments', [ProposalCommentController::class, 'store'])->name('proposals.comments.store');
    Route::delete('comments/{comment}', [ProposalCommentController::class, 'destroy'])->name('comments.destroy');

    // Votes on proposals
    Route::post('proposals/{proposal}/votes', [ProposalVoteController::class, 'store'])->name('proposals.votes.store');

    // CRM — contacts, notes and data-source sync. Static routes (sync/export)
    // are declared before the {contact} wildcard so they take precedence.
    Route::prefix('crm')->name('crm.')->group(function () {
        Route::post('sync', [CrmController::class, 'sync'])->name('sync');
        Route::get('export', [CrmController::class, 'export'])->name('export');
        Route::get('/', [CrmContactController::class, 'index'])->name('index');
        Route::post('/', [CrmContactController::class, 'store'])->name('store');
        Route::get('{contact}', [CrmContactController::class, 'show'])->name('show');
        Route::put('{contact}', [CrmContactController::class, 'update'])->name('update');
        Route::delete('{contact}', [CrmContactController::class, 'destroy'])->name('destroy');
        Route::post('{contact}/notes', [CrmNoteController::class, 'store'])->name('notes.store');
        Route::delete('notes/{note}', [CrmNoteController::class, 'destroy'])->name('notes.destroy');
    });

    // Wallet attach/detach
    Route::post('wallets/evm/attach', [WalletAttachController::class, 'attachEvm'])->name('wallets.evm.attach');
    Route::post('wallets/solana/attach', [WalletAttachController::class, 'attachSolana'])->name('wallets.solana.attach');
    Route::delete('wallets/evm/detach', [WalletAttachController::class, 'detachEvm'])->name('wallets.evm.detach');
    Route::delete('wallets/solana/detach', [WalletAttachController::class, 'detachSolana'])->name('wallets.solana.detach');

});

// Bridge (public — no auth required, controller handles optional user)
Route::post('bridge/submit', [BridgeController::class, 'submit'])->name('bridge.submit');

// Bridge analytics (admin only)
Route::middleware(['auth', EnsureBridgeAdmin::class])
    ->get('admin/bridge-analytics', [BridgeAnalyticsController::class, 'index'])
    ->name('admin.bridge-analytics');

require __DIR__.'/settings.php';
