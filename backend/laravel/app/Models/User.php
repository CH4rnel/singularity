<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTeams;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

#[Fillable(['name', 'email', 'password', 'current_team_id', 'wallet_address', 'solana_wallet_address', 'twitter_id', 'twitter_username'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, HasTeams, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function proposalVotes(): HasMany
    {
        return $this->hasMany(ProposalVote::class);
    }

    public function proposalComments(): HasMany
    {
        return $this->hasMany(ProposalComment::class);
    }

    public function lainChatMessages(): HasMany
    {
        return $this->hasMany(LainChatMessage::class);
    }

    public function lainChatSessions(): HasMany
    {
        return $this->hasMany(LainChatSession::class);
    }

    public function solanaStakingPosition(): HasOne
    {
        return $this->hasOne(SolanaStakingPosition::class);
    }

    /**
     * The surviving account this user was merged into, if any. A non-null
     * value means this account is absorbed: it can no longer authenticate
     * and its identity columns have been cleared.
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_into_id');
    }

    /**
     * Accounts previously merged into this one.
     */
    public function mergedAccounts(): HasMany
    {
        return $this->hasMany(User::class, 'merged_into_id');
    }
}
