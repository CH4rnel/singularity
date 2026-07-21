<?php

namespace App\Services;

use App\Exceptions\AccountMergeConflictException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Merges one user account into another when a wallet-attach request proves
 * ownership of a wallet that already belongs to a different account. The
 * survivor is always the currently authenticated account; the absorbed
 * account is soft-marked (never hard-deleted) and can never authenticate
 * again.
 *
 * user_deposit_addresses and team_members are deliberately never touched:
 * deposit addresses derive their spending key from the absorbed account's
 * original numeric id at sweep time (UserDepositAddressService), so
 * reassigning ownership would permanently break the ability to sweep funds
 * already sent to that address; team_members is skipped because every
 * account has exactly one auto-created personal team and reassigning
 * membership would violate that invariant.
 */
class AccountMergeService
{
    /**
     * Identity fields copied from the absorbed account to the survivor when
     * the survivor doesn't already hold a conflicting value.
     */
    private const IDENTITY_FIELDS = ['wallet_address', 'solana_wallet_address', 'twitter_id', 'twitter_username'];

    /**
     * Tables with a plain user_id column and no unique constraint involving
     * it — safe to bulk-reassign.
     */
    private const SIMPLE_TABLES = [
        'activities', 'proposals', 'proposal_comments', 'lain_chat_messages',
        'lain_chat_sessions', 'bridge_requests', 'bridge_events', 'site_events',
        'slot_spins', 'crm_contacts', 'crm_notes', 'daos',
    ];

    /**
     * @throws AccountMergeConflictException if the two accounts hold different
     *                                       non-null values for the same identity field
     */
    public function merge(User $survivor, User $absorbed): void
    {
        if ($survivor->is($absorbed)) {
            return;
        }

        DB::transaction(function () use ($survivor, $absorbed) {
            // Lock in a stable order so two concurrent merges can never deadlock.
            $ids = collect([$survivor->id, $absorbed->id])->sort()->values();
            $locked = User::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

            $survivor = $locked[$survivor->id];
            $absorbed = $locked[$absorbed->id];

            if ($absorbed->merged_into_id !== null) {
                return;
            }

            $this->assertNoIdentityConflicts($survivor, $absorbed);

            // Absorbed's identity columns are cleared before they're copied
            // onto the survivor — both share a unique index, so setting the
            // survivor's copy first (while absorbed still holds the same
            // value) would violate the constraint mid-transaction.
            $identityUpdates = $this->identityFieldsToCopy($survivor, $absorbed);
            $this->markAbsorbed($survivor, $absorbed);
            $this->applyIdentityFields($survivor, $identityUpdates);

            $this->reassignSimpleTables($survivor, $absorbed);
            $this->reassignProposalVotes($survivor, $absorbed);
            $this->reassignReactions($survivor, $absorbed);
            $this->reassignNotifications($survivor, $absorbed);
            $this->reassignPushSubscriptions($survivor, $absorbed);
            $this->revokeCredentials($absorbed);

            Log::info('Accounts merged', ['survivor_id' => $survivor->id, 'absorbed_id' => $absorbed->id]);
        });
    }

    private function assertNoIdentityConflicts(User $survivor, User $absorbed): void
    {
        foreach (self::IDENTITY_FIELDS as $field) {
            if ($survivor->{$field} !== null && $absorbed->{$field} !== null && $survivor->{$field} !== $absorbed->{$field}) {
                throw AccountMergeConflictException::forField($field);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function identityFieldsToCopy(User $survivor, User $absorbed): array
    {
        $updates = [];

        foreach (self::IDENTITY_FIELDS as $field) {
            if ($survivor->{$field} === null && $absorbed->{$field} !== null) {
                $updates[$field] = $absorbed->{$field};
            }
        }

        return $updates;
    }

    /**
     * @param  array<string, string>  $updates
     */
    private function applyIdentityFields(User $survivor, array $updates): void
    {
        if ($updates !== []) {
            $survivor->forceFill($updates)->save();
        }
    }

    private function reassignSimpleTables(User $survivor, User $absorbed): void
    {
        foreach (self::SIMPLE_TABLES as $table) {
            DB::table($table)->where('user_id', $absorbed->id)->update(['user_id' => $survivor->id]);
        }
    }

    /**
     * proposal_votes has UNIQUE(proposal_id, user_id): where the survivor
     * already voted on the same proposal, drop the absorbed account's
     * duplicate and keep the survivor's own vote.
     */
    private function reassignProposalVotes(User $survivor, User $absorbed): void
    {
        $survivorProposalIds = DB::table('proposal_votes')->where('user_id', $survivor->id)->pluck('proposal_id');

        DB::table('proposal_votes')
            ->where('user_id', $absorbed->id)
            ->whereIn('proposal_id', $survivorProposalIds)
            ->delete();

        DB::table('proposal_votes')->where('user_id', $absorbed->id)->update(['user_id' => $survivor->id]);
    }

    /**
     * reactions has UNIQUE(user_id, reactable_type, reactable_id, emoji):
     * where the survivor already reacted the same way on the same target,
     * drop the absorbed account's duplicate.
     */
    private function reassignReactions(User $survivor, User $absorbed): void
    {
        $survivorReactions = DB::table('reactions')
            ->where('user_id', $survivor->id)
            ->get(['reactable_type', 'reactable_id', 'emoji']);

        foreach ($survivorReactions as $reaction) {
            DB::table('reactions')
                ->where('user_id', $absorbed->id)
                ->where('reactable_type', $reaction->reactable_type)
                ->where('reactable_id', $reaction->reactable_id)
                ->where('emoji', $reaction->emoji)
                ->delete();
        }

        DB::table('reactions')->where('user_id', $absorbed->id)->update(['user_id' => $survivor->id]);
    }

    private function reassignNotifications(User $survivor, User $absorbed): void
    {
        DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $absorbed->id)
            ->update(['notifiable_id' => $survivor->id]);
    }

    private function reassignPushSubscriptions(User $survivor, User $absorbed): void
    {
        DB::connection(config('webpush.database_connection'))
            ->table(config('webpush.table_name'))
            ->where('subscribable_type', User::class)
            ->where('subscribable_id', $absorbed->id)
            ->update(['subscribable_id' => $survivor->id]);
    }

    private function revokeCredentials(User $absorbed): void
    {
        $absorbed->tokens()->delete();

        DB::table('sessions')->where('user_id', $absorbed->id)->delete();
    }

    private function markAbsorbed(User $survivor, User $absorbed): void
    {
        $absorbed->forceFill([
            'wallet_address' => null,
            'solana_wallet_address' => null,
            'twitter_id' => null,
            'twitter_username' => null,
            'merged_into_id' => $survivor->id,
        ])->save();
    }
}
