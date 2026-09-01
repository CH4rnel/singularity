<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAvatarRequest;
use App\Http\Requests\UpdateNicknameRequest;
use App\Models\User;
use App\Services\AchievementService;
use App\Services\Ai\AiKeyService;
use App\Services\BridgeConfigService;
use App\Services\GamificationService;
use App\Services\ProfileOnchainService;
use App\Services\UserDepositAddressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use RuntimeException;

/**
 * Signed-in user's own profile page (the public one is UserProfileController).
 * Alongside account info it lists a deposit address for every enabled chain,
 * but ONLY personal CEX-style ones (derived per user, deposits attributable
 * without a bridge request). Shared bridge wallets (relayer EOA, hot wallets)
 * are never shown here — a bare deposit to them cannot be credited to anyone;
 * chains without a personal address advertise one as coming soon instead.
 * On-chain identity (CyberiaProfile contract) also lives here: a globally
 * unique nickname and service-usage achievements, both minted on Cyberia.
 */
class ProfileController extends Controller
{
    public function show(
        Request $request,
        BridgeConfigService $bridgeConfig,
        UserDepositAddressService $depositAddresses,
        ProfileOnchainService $onchain,
        AchievementService $achievements,
        GamificationService $gamification,
    ) {
        $user = $request->user();
        $nickname = $user->wallet_address && $onchain->enabled()
            ? $onchain->syncNickname($user)
            : null;

        return Inertia::render('Profile', [
            // Level, streak and the live quest board — the part of the page a
            // returning user actually comes back for.
            'progress' => $gamification->progressFor($user),
            'depositChains' => $this->depositChains(
                $bridgeConfig,
                $depositAddresses->addressesFor($user),
                $depositAddresses->mergedHistoryFor($user),
            ),
            'profileContract' => $onchain->contractAddress(),
            'nickname' => $nickname,
            'achievements' => $onchain->enabled() ? $achievements->forProfile($user) : [],
            'posts' => $user->posts()
                ->with(['user:id,name,onchain_nickname,avatar_path,wallet_address'])
                ->latest('id')
                ->paginate(10, pageName: 'posts'),
        ]);
    }

    public function updateAvatar(UpdateAvatarRequest $request): RedirectResponse
    {
        $user = $request->user();
        $oldPath = $user->avatar_path;
        $path = $request->file('avatar')?->storePublicly(
            "avatars/{$user->id}",
            'public',
        );

        if (! is_string($path)) {
            throw new RuntimeException('The avatar could not be stored.');
        }

        $user->forceFill(['avatar_path' => $path])->save();

        if ($oldPath !== null && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }

        return back()->with('status', 'avatar-updated');
    }

    /**
     * Claim/change the on-chain nickname. The relayer submits the transaction
     * (setNicknameFor), so web2-onboarded users without gas still get one.
     */
    public function updateNickname(
        UpdateNicknameRequest $request,
        ProfileOnchainService $onchain,
        AchievementService $achievements,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user->wallet_address) {
            return back()->withErrors(['nickname' => 'Connect an EVM wallet first — nicknames live on-chain.']);
        }

        if (! $onchain->enabled()) {
            return back()->withErrors(['nickname' => 'The profile contract is not configured yet.']);
        }

        $nickname = (string) $request->validated('nickname');
        $owner = $onchain->nicknameOwner($nickname);

        if ($owner !== null && strtolower($owner) !== strtolower($user->wallet_address)) {
            return back()->withErrors(['nickname' => 'This nickname is already taken.']);
        }

        if ($onchain->setNickname($user->wallet_address, $nickname) === null) {
            return back()->withErrors(['nickname' => 'On-chain transaction failed — try again in a minute.']);
        }

        $user->forceFill([
            'name' => $nickname,
            'onchain_nickname' => $nickname,
        ])->save();

        // Claiming a nickname is itself an achievement (best-effort).
        $achievements->check($user);

        return back()->with('status', 'nickname-updated');
    }

    /**
     * Spend experience on something permanent.
     *
     * Every refusal is named rather than collapsed into one, because they are
     * different things to do about it: `level` means go and use the chain,
     * `xp` means the standing is fine and the balance is not, `requires` means
     * the rung below is unbought, and `owned` means it already happened —
     * which a double-submitted form will produce and which is not an error
     * worth alarming anybody about.
     */
    public function enchant(
        Request $request,
        GamificationService $gamification,
        AiKeyService $keys,
    ): RedirectResponse {
        $key = (string) $request->validate([
            'key' => ['required', 'string', 'max:64'],
        ])['key'];

        $result = $gamification->enchant($request->user(), $key);

        if (! $result['ok']) {
            return $result['reason'] === 'owned'
                ? back()->with('status', 'enchant-owned')
                : back()->withErrors(['enchant' => $result['reason']]);
        }

        // One effect needs something issued rather than merely recorded. It is
        // done after the spend and its failure is reported rather than
        // rolled back: the enchantment is owned either way, and an operator
        // re-issuing a key is a smaller problem than a charge that vanished.
        if (($result['enchantment']['effects']['ai_access'] ?? 0) > 0) {
            $token = $this->issueInferenceKey($request->user(), $keys);

            if ($token !== null) {
                return back()->with('status', 'enchant-key:'.$token);
            }
        }

        return back()->with('status', 'enchant-bought:'.$key);
    }

    /**
     * A gate-exempt inference key for somebody who paid for one.
     *
     * `AiHolderGate` otherwise re-reads a $LAIN holding on every request, so
     * the exemption is the whole product here. The plaintext token leaves in
     * this one response and is never recoverable — the same contract the
     * console's issuance already makes.
     */
    private function issueInferenceKey(User $user, AiKeyService $keys): ?string
    {
        if (! $user->wallet_address) {
            return null;
        }

        try {
            // CLIENT_API, not CLIENT_LAINOS: a LainOS key is bound to an
            // installation UUID and is what an operator issues at the console.
            // This one belongs to a person.
            return $keys->issue(
                address: $user->wallet_address,
                name: 'Key to Lain',
                gateExempt: true,
            )['token'] ?? null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Re-detect qualifying activity and mint any newly earned achievements.
     */
    public function checkAchievements(Request $request, AchievementService $achievements)
    {
        $awarded = $achievements->check($request->user());

        return back()->with(
            'status',
            $awarded === [] ? 'achievements-none' : 'achievements-awarded:'.count($awarded),
        );
    }

    /**
     * Deposit address per enabled chain — personal per-user addresses only.
     * Shared bridge wallets are deliberately never exposed here: a deposit to
     * them can't be attributed to a user, and those chains will get personal
     * addresses of their own later. Yenten without a configured seed carries
     * a oneTime flag instead (its request flow binds deposits to one-time HD
     * addresses on the Bridge page).
     *
     * @param  array<string, string>  $personal  chain key => personal address
     * @param  array<string, array<int, string>>  $history  chain key => addresses from merged-in accounts
     * @return array<int, array<string, mixed>>
     */
    private function depositChains(BridgeConfigService $bridgeConfig, array $personal, array $history = []): array
    {
        return array_map(function (array $chain) use ($personal, $history) {
            $personalAddress = $personal[$chain['key']] ?? null;

            return [
                'key' => $chain['key'],
                'label' => $chain['label'],
                'type' => $chain['type'],
                'addressType' => $chain['addressType'],
                'address' => $personalAddress,
                'personal' => $personalAddress !== null,
                'oneTime' => $personalAddress === null && $chain['type'] === 'yenten',
                'history' => $history[$chain['key']] ?? [],
            ];
        }, $bridgeConfig->publicChains());
    }
}
