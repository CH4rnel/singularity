<?php

namespace App\Actions\Auth;

use App\Enums\SocialProvider;
use App\Exceptions\SocialIdentityConflictException;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LinkSocialIdentity
{
    /**
     * @throws SocialIdentityConflictException
     */
    public function handle(
        User $user,
        SocialProvider $provider,
        string $providerId,
        ?string $username,
    ): void {
        try {
            DB::transaction(function () use ($user, $provider, $providerId, $username): void {
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $idColumn = $provider->idColumn();
                $usernameColumn = $provider->usernameColumn();

                if ($lockedUser->merged_into_id !== null) {
                    throw SocialIdentityConflictException::invalidIntent();
                }

                if ($lockedUser->{$idColumn} !== null && $lockedUser->{$idColumn} !== $providerId) {
                    throw SocialIdentityConflictException::alreadyLinked($provider);
                }

                $owner = User::query()
                    ->where($idColumn, $providerId)
                    ->lockForUpdate()
                    ->first();

                if ($owner !== null && ! $owner->is($lockedUser)) {
                    throw SocialIdentityConflictException::alreadyOwned($provider);
                }

                $lockedUser->forceFill([
                    $idColumn => $providerId,
                    $usernameColumn => $username,
                ])->save();
            });
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

            if (Str::startsWith($sqlState, ['19', '23'])) {
                throw SocialIdentityConflictException::alreadyOwned($provider);
            }

            throw $exception;
        }
    }
}
