<?php

namespace App\Http\Controllers;

use App\Services\AchievementService;
use App\Services\BridgeConfigService;
use App\Services\ProfileOnchainService;
use App\Services\UserDepositAddressService;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
    ) {
        $user = $request->user();

        return Inertia::render('Profile', [
            'depositChains' => $this->depositChains(
                $bridgeConfig,
                $depositAddresses->addressesFor($user),
            ),
            'profileContract' => $onchain->contractAddress(),
            'nickname' => $user->wallet_address && $onchain->enabled()
                ? $onchain->nicknameOf($user->wallet_address)
                : null,
            'achievements' => $onchain->enabled() ? $achievements->forProfile($user) : [],
        ]);
    }

    /**
     * Claim/change the on-chain nickname. The relayer submits the transaction
     * (setNicknameFor), so web2-onboarded users without gas still get one.
     */
    public function updateNickname(
        Request $request,
        ProfileOnchainService $onchain,
        AchievementService $achievements,
    ) {
        $validated = $request->validate([
            'nickname' => ['required', 'string', 'regex:/^[a-z0-9_]{3,20}$/'],
        ], [
            'nickname.regex' => 'Nicknames are 3-20 characters: lowercase letters, digits and underscores.',
        ]);

        $user = $request->user();

        if (! $user->wallet_address) {
            return back()->withErrors(['nickname' => 'Connect an EVM wallet first — nicknames live on-chain.']);
        }

        if (! $onchain->enabled()) {
            return back()->withErrors(['nickname' => 'The profile contract is not configured yet.']);
        }

        $nickname = $validated['nickname'];
        $owner = $onchain->nicknameOwner($nickname);

        if ($owner !== null && strtolower($owner) !== strtolower($user->wallet_address)) {
            return back()->withErrors(['nickname' => 'This nickname is already taken.']);
        }

        if ($onchain->setNickname($user->wallet_address, $nickname) === null) {
            return back()->withErrors(['nickname' => 'On-chain transaction failed — try again in a minute.']);
        }

        $user->update(['name' => $nickname]);

        // Claiming a nickname is itself an achievement (best-effort).
        $achievements->check($user);

        return back()->with('status', 'nickname-updated');
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
     * @return array<int, array<string, mixed>>
     */
    private function depositChains(BridgeConfigService $bridgeConfig, array $personal): array
    {
        return array_map(function (array $chain) use ($personal) {
            $personalAddress = $personal[$chain['key']] ?? null;

            return [
                'key' => $chain['key'],
                'label' => $chain['label'],
                'type' => $chain['type'],
                'addressType' => $chain['addressType'],
                'address' => $personalAddress,
                'personal' => $personalAddress !== null,
                'oneTime' => $personalAddress === null && $chain['type'] === 'yenten',
            ];
        }, $bridgeConfig->publicChains());
    }
}
