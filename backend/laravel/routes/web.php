<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Api\BridgeController;
use App\Http\Controllers\Api\LaunchpadController;
use App\Http\Controllers\Api\SiteEventController;
use App\Http\Controllers\Api\TgWhaleController;
use App\Http\Controllers\Api\WalletAttachController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\Auth\TwitterAuthController;
use App\Http\Controllers\Auth\Web3LoginController;
use App\Http\Controllers\BridgeAnalyticsController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CrmAnalyticsController;
use App\Http\Controllers\CrmContactController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\CrmNoteController;
use App\Http\Controllers\DaoController;
use App\Http\Controllers\FediverseController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\LiquidityController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProposalCommentController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\ProposalVoteController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\TokenController;
use App\Http\Controllers\UserProfileController;
use App\Http\Middleware\EnsureBridgeAdmin;
use App\Services\DexAprService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->file(resource_path('views/landing/index.html')))->name('home');
Route::get('/thesis', fn () => response()->file(resource_path('views/landing/index1.html')))->name('thesis');
// Cached per-pool LP APR (written by the scheduled dex:apr command). Public:
// the landing hero and any external site can quote real yield numbers.
Route::get('/api/dex/apr', fn () => response()->json(
    DexAprService::cached()
        ?? ['updated_at' => null, 'window_hours' => 24, 'pools' => []],
))->name('dex.apr');
// Funnel-event ingest (page views, wallet connects, swaps, LP adds) feeding
// the CRM analytics page. Web middleware so the session resolves the user;
// CSRF-exempt in bootstrap/app.php so the static landing can report too.
Route::post('/api/events', [SiteEventController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('site.events');
// Session probe for the static landing: the React nav swaps the Connect CTA
// for an avatar + username chip when a session cookie is present. Lives in
// web.php (not routes/api.php) so the session guard resolves the user.
Route::get('/api/session-user', function (Request $request) {
    $user = $request->user();

    return $user
        ? response()->json([
            'authenticated' => true,
            'name' => $user->name,
            'avatar' => $user->avatar ?? null,
        ])
        : response()->json(['authenticated' => false]);
})->name('session.user');
Route::get('/tonconnect-manifest.json', fn () => response()->json([
    'url' => 'https://cyberia.church/bridge',
    'name' => 'Cyberia Bridge',
    'iconUrl' => 'https://cyberia.church/apple-touch-icon.png',
    'termsOfUseUrl' => 'https://cyberia.church',
    'privacyPolicyUrl' => 'https://cyberia.church',
], 200, [], JSON_UNESCAPED_SLASHES))->name('tonconnect.manifest');
Route::get('/bridge', [ApiController::class, 'index'])->name('bridge');
Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
// Public token directory + per-token pages the analytics list links into.
// {token} accepts a 0x address or a symbol (e.g. /token/CYBER.sol).
Route::get('/tokens', [TokenController::class, 'index'])->name('tokens.index');
Route::get('/token/{token}', [TokenController::class, 'show'])->name('tokens.show');
Route::inertia('/market', 'Market')->name('market');
Route::inertia('/pixels', 'PixelBattle')->name('pixels');
Route::get('/liquidity', [LiquidityController::class, 'index'])->name('liquidity');
// On-site swap UI over the Ritual router (same pool seed as /liquidity).
Route::get('/swap', [LiquidityController::class, 'swap'])->name('swap');
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
Route::inertia('/predictions', 'Predictions')->name('predictions');
Route::get('dao', [DaoController::class, 'index'])->name('dao.index');
Route::get('dao/{dao}', [DaoController::class, 'show'])->name('dao.show');
Route::get('proposals/{proposal}', [ProposalController::class, 'show'])->name('proposals.show');
Route::get('u/{user}', [UserProfileController::class, 'show'])->name('users.show');

Route::post('login/web3', Web3LoginController::class)->name('web3.login');

// X (Twitter) OAuth: guests log in / register, signed-in users link the
// account to their profile.
Route::get('/auth/twitter', [TwitterAuthController::class, 'redirect'])->name('twitter.redirect');
Route::get('/auth/twitter/callback', [TwitterAuthController::class, 'callback'])->name('twitter.callback');

Route::get('/wallet-login', fn () => inertia('auth/WalletLogin'))->name('wallet.login')->middleware('guest');

// Telegram whales-chat verification page (opened from the bot's one-time link).
Route::get('/tg/cyber-sol', [TgWhaleController::class, 'page'])->name('tg.cyber-sol');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['auth'])->group(function () {
    // Own profile: account info + bridge deposit addresses for every chain.
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    // On-chain identity: nickname (relayer-submitted) and achievement checks.
    // Throttled — each hit can cost a relayer transaction on Cyberia.
    Route::patch('profile/nickname', [ProfileController::class, 'updateNickname'])
        ->middleware('throttle:6,1')->name('profile.nickname');
    Route::post('profile/achievements/check', [ProfileController::class, 'checkAchievements'])
        ->middleware('throttle:6,1')->name('profile.achievements.check');

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

    // Emoji reactions on proposals/comments (toggle)
    Route::post('reactions', [ReactionController::class, 'toggle'])->name('reactions.toggle');

    // In-app notifications (bell dropdown + 30s poll)
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    // Web Push subscriptions (service worker)
    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');

    // CRM — contacts, notes and data-source sync. Static routes (sync/export)
    // are declared before the {contact} wildcard so they take precedence.
    Route::prefix('crm')->name('crm.')->group(function () {
        Route::post('sync', [CrmController::class, 'sync'])->name('sync');
        Route::get('export', [CrmController::class, 'export'])->name('export');
        Route::get('analytics', [CrmAnalyticsController::class, 'index'])->name('analytics');
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
// Two-phase one-time-address routes (Yenten): prepare commits the recipient
// and returns a unique deposit address; claim verifies the deposit landed on it.
Route::post('bridge/prepare', [BridgeController::class, 'prepare'])->name('bridge.prepare');
Route::post('bridge/claim', [BridgeController::class, 'claim'])->name('bridge.claim');
// Live withdrawal capacity (relayer inventory on the destination chain).
Route::get('bridge/capacity', [BridgeController::class, 'capacity'])->name('bridge.capacity');

// Bridge analytics (admin only)
Route::middleware(['auth', EnsureBridgeAdmin::class])
    ->get('admin/bridge-analytics', [BridgeAnalyticsController::class, 'index'])
    ->name('admin.bridge-analytics');

require __DIR__.'/settings.php';
