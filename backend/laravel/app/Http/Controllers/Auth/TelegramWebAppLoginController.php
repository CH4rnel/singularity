<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Teams\CreateTeam;
use App\Actions\Wallet\VerifyTelegramWebAppData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sign in inside Telegram, with nothing to type.
 *
 * The Mini App sends the `initData` Telegram launched it with; once its
 * signature checks out, the Telegram account *is* the profile. Which profile:
 * the one whose wallet this Telegram account linked through the bot
 * (`tg_wallets`, proven from the wallet's side by a signature) when the
 * profile already on this Telegram account is an empty one made by an earlier
 * launch; else the one on this Telegram account; else that linked one; else a
 * new one. That is what lets a wallet made on the site come back inside
 * Telegram, whichever was opened first.
 */
class TelegramWebAppLoginController extends Controller
{
    public function __construct(
        private VerifyTelegramWebAppData $verify,
        private CreateTeam $createTeam,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'init_data' => ['required', 'string', 'max:4096'],
        ]);

        $telegram = $this->verify->handle($data['init_data']);

        if ($telegram === null) {
            return response()->json(['message' => 'Telegram did not vouch for this launch.'], 401);
        }

        $user = DB::transaction(fn () => $this->profileFor($telegram));

        $request->session()->regenerate();
        auth()->login($user, true);

        return response()->json(['user' => ['id' => $user->id, 'name' => $user->name]]);
    }

    /**
     * @param  array{id: string, username: ?string, first_name: ?string}  $telegram
     */
    private function profileFor(array $telegram): User
    {
        $current = User::query()->where('telegram_id', $telegram['id'])->first();
        $linked = $this->profileByLinkedWallet($telegram['id']);

        if ($current && (! $linked || $linked->is($current) || ! $this->isEmpty($current))) {
            return $current;
        }

        // An empty Telegram-only profile gives the account up to the wallet's.
        $current?->forceFill(['telegram_id' => null, 'telegram_username' => null])->save();

        $user = $linked;

        if (! $user) {
            $user = User::create([
                'name' => $telegram['first_name'] ?: ($telegram['username'] ?: "Telegram {$telegram['id']}"),
                'email' => "telegram_{$telegram['id']}@localhost",
                'password' => null,
            ]);

            $this->createTeam->handle($user, "User's Team", isPersonal: true);
        }

        $user->forceFill([
            'telegram_id' => $telegram['id'],
            'telegram_username' => $telegram['username'],
        ])->save();

        return $user;
    }

    private function profileByLinkedWallet(string $telegramId): ?User
    {
        if (! Schema::hasTable('tg_wallets')) {
            return null;
        }

        $address = DB::table('tg_wallets')->where('user_id', $telegramId)->value('address');

        if (! is_string($address) || $address === '') {
            return null;
        }

        return User::query()
            ->whereRaw('lower(wallet_address) = ?', [strtolower($address)])
            ->first();
    }

    /** Made by a Telegram launch and never used: no wallet, nothing kept. */
    private function isEmpty(User $user): bool
    {
        return ! $user->wallet_address && ! $user->walletSyncBackup()->exists();
    }
}
