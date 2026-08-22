<?php

namespace App\Http\Controllers;

use App\Models\AiApiKey;
use App\Models\AiApiRequest;
use App\Models\CrmContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Read-only operator view of OpenAI-compatible keys issued to LainOS. */
class ConsoleAiKeysController extends Controller
{
    private const ROW_LIMIT = 200;

    public function index(): Response
    {
        $base = AiApiKey::query()->where('client', AiApiKey::CLIENT_LAINOS);
        $keys = (clone $base)->latest('id')->limit(self::ROW_LIMIT)->get();
        $keyIds = $keys->pluck('id');
        $addresses = $keys->pluck('address')->unique()->values();

        $usageToday = $this->usageFor($keyIds, true);
        $usageLifetime = $this->usageFor($keyIds);
        $users = User::query()
            ->whereIn('wallet_address', $addresses)
            ->get(['id', 'name', 'wallet_address'])
            ->keyBy(fn (User $user): string => Str::lower((string) $user->wallet_address));
        $contacts = CrmContact::query()
            ->whereIn('evm_address', $addresses)
            ->get(['id', 'name', 'evm_address'])
            ->keyBy(fn (CrmContact $contact): string => Str::lower((string) $contact->evm_address));

        $today = AiApiRequest::query()
            ->whereHas('key', fn (Builder $query) => $query->where('client', AiApiKey::CLIENT_LAINOS))
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('count(*) as requests, count(distinct ai_api_key_id) as keys, coalesce(sum(prompt_tokens + completion_tokens), 0) as tokens')
            ->first();

        return Inertia::render('crm/AiKeys', [
            'summary' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->whereNull('revoked_at')->whereNotNull('last_used_at')->count(),
                'waiting' => (clone $base)->whereNull('revoked_at')->whereNull('last_used_at')->count(),
                'revoked' => (clone $base)->whereNotNull('revoked_at')->count(),
                'used_today' => (int) ($today?->keys ?? 0),
                'requests_today' => (int) ($today?->requests ?? 0),
                'tokens_today' => (int) ($today?->tokens ?? 0),
            ],
            'keys' => $keys->map(function (AiApiKey $key) use ($usageToday, $usageLifetime, $users, $contacts): array {
                $address = Str::lower($key->address);
                $user = $users->get($address);
                $contact = $contacts->get($address);
                $today = $usageToday->get($key->id, ['requests' => 0, 'tokens' => 0]);
                $lifetime = $usageLifetime->get($key->id, ['requests' => 0, 'tokens' => 0]);
                $status = $key->revoked()
                    ? 'revoked'
                    : ($key->last_used_at === null ? 'waiting' : 'active');

                return [
                    'id' => $key->id,
                    'name' => $key->name,
                    'prefix' => $key->prefix,
                    'address' => $key->address,
                    'instance_id' => $key->instance_id,
                    'owner' => $user === null ? null : [
                        'id' => $user->id,
                        'name' => $user->name,
                    ],
                    'contact' => $contact === null ? null : [
                        'id' => $contact->id,
                        'name' => $contact->name,
                    ],
                    'status' => $status,
                    'state_since' => match ($status) {
                        'revoked' => $key->revoked_at?->toIso8601String(),
                        'active' => $key->last_used_at?->toIso8601String(),
                        default => $key->created_at?->toIso8601String(),
                    },
                    'created_at' => $key->created_at?->toIso8601String(),
                    'last_used_at' => $key->last_used_at?->toIso8601String(),
                    'revoked_at' => $key->revoked_at?->toIso8601String(),
                    'usage' => [
                        'today' => $today,
                        'lifetime' => $lifetime,
                    ],
                ];
            })->values(),
            'row_limit' => self::ROW_LIMIT,
        ]);
    }

    /**
     * @param  Collection<int, int>  $keyIds
     * @return Collection<int, array{requests: int, tokens: int}>
     */
    private function usageFor(Collection $keyIds, bool $today = false): Collection
    {
        if ($keyIds->isEmpty()) {
            return collect();
        }

        $query = AiApiRequest::query()->whereIn('ai_api_key_id', $keyIds);

        if ($today) {
            $query->where('created_at', '>=', now()->startOfDay());
        }

        return $query
            ->selectRaw('ai_api_key_id, count(*) as requests, coalesce(sum(prompt_tokens + completion_tokens), 0) as tokens')
            ->groupBy('ai_api_key_id')
            ->get()
            ->mapWithKeys(fn (AiApiRequest $usage): array => [
                $usage->ai_api_key_id => [
                    'requests' => (int) $usage->getAttribute('requests'),
                    'tokens' => (int) $usage->getAttribute('tokens'),
                ],
            ]);
    }
}
