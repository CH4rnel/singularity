<?php

use App\Http\Controllers\Api\BridgeController;
use App\Http\Controllers\Api\WalletAttachController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\Auth\Web3LoginController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DaoController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\ProposalCommentController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\ProposalVoteController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::get('/', [ApiController::class, 'index'])->name('home');

Route::post('login/web3', Web3LoginController::class)->name('web3.login');

Route::get('/wallet-login', fn () => inertia('auth/WalletLogin'))->name('wallet.login')->middleware('guest');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
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
    Route::resource('dao', DaoController::class)->except(['create', 'edit']);

    // Proposals (nested under dao)
    Route::post('dao/{dao}/proposals', [ProposalController::class, 'store'])->name('dao.proposals.store');

    // Proposal detail
    Route::get('proposals/{proposal}', [ProposalController::class, 'show'])->name('proposals.show');
    Route::put('proposals/{proposal}', [ProposalController::class, 'update'])->name('proposals.update');
    Route::delete('proposals/{proposal}', [ProposalController::class, 'destroy'])->name('proposals.destroy');

    // Comments on proposals
    Route::post('proposals/{proposal}/comments', [ProposalCommentController::class, 'store'])->name('proposals.comments.store');
    Route::delete('comments/{comment}', [ProposalCommentController::class, 'destroy'])->name('comments.destroy');

    // Votes on proposals
    Route::post('proposals/{proposal}/votes', [ProposalVoteController::class, 'store'])->name('proposals.votes.store');

    // Wallet attach/detach
    Route::post('wallets/evm/attach', [WalletAttachController::class, 'attachEvm'])->name('wallets.evm.attach');
    Route::post('wallets/solana/attach', [WalletAttachController::class, 'attachSolana'])->name('wallets.solana.attach');
    Route::delete('wallets/evm/detach', [WalletAttachController::class, 'detachEvm'])->name('wallets.evm.detach');
    Route::delete('wallets/solana/detach', [WalletAttachController::class, 'detachSolana'])->name('wallets.solana.detach');

});

// Bridge (public — no auth required, controller handles optional user)
Route::post('bridge/submit', [BridgeController::class, 'submit'])->name('bridge.submit');

require __DIR__.'/settings.php';
