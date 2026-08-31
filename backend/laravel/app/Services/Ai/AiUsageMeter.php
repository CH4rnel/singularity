<?php

namespace App\Services\Ai;

use App\Exceptions\AiApiException;
use App\Models\AiApiKey;
use App\Models\AiApiRequest;
use App\Models\X402Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * What one key is allowed to spend, and what it has spent.
 *
 * Two bounds, because they stop different things: a per-minute limit keeps one
 * caller from crowding out the rest in a burst, and a daily one keeps a
 * patient caller from draining the shared upstream account overnight. Both are
 * per key rather than per address, so issuing more keys buys more of neither.
 *
 * The minute window lives in the rate limiter (cheap, resets by itself) and
 * the day in the request log (durable, survives a cache flush — the thing a
 * quota must not be resettable by).
 */
class AiUsageMeter
{
    /**
     * Refuse the call if this key is over either limit.
     *
     * Called before anything reaches a provider: the point of a quota is to
     * not spend the request, so a rejection here costs nothing upstream.
     */
    public function enforce(AiApiKey $key): void
    {
        $perMinute = (int) config('ai.limits.requests_per_minute', 20);

        if ($perMinute > 0 && RateLimiter::tooManyAttempts($this->minuteKey($key), $perMinute)) {
            throw AiApiException::rateLimited(
                "This key is limited to {$perMinute} requests per minute.",
                max(1, RateLimiter::availableIn($this->minuteKey($key))),
            );
        }

        $perDay = (int) config('ai.limits.requests_per_day', 2000);

        if ($perDay > 0 && $this->todayCount($key) >= $perDay) {
            throw AiApiException::rateLimited(
                "This key is limited to {$perDay} requests per day.",
                max(1, Carbon::now()->endOfDay()->diffInSeconds(Carbon::now(), absolute: true)),
                'daily_quota_exceeded',
            );
        }

        RateLimiter::hit($this->minuteKey($key), 60);
    }

    /**
     * Log one completed call.
     *
     * The key is optional because the caller may not have one: a call paid for
     * over x402 is metered exactly like a key's, and names the payment that
     * bought it instead of the credential that was presented.
     *
     * @param  array{model: string, served_model: string, provider: string, status: int, streamed: bool, usage?: array<string, mixed>|null, payment?: ?X402Payment}  $call
     */
    public function record(?AiApiKey $key, array $call): AiApiRequest
    {
        $usage = is_array($call['usage'] ?? null) ? $call['usage'] : [];
        $payment = $call['payment'] ?? null;

        return AiApiRequest::create([
            'ai_api_key_id' => $key?->id,
            'x402_payment_id' => $payment instanceof X402Payment ? $payment->id : null,
            'model' => $call['model'],
            'served_model' => $call['served_model'],
            'provider' => $call['provider'],
            'prompt_tokens' => max(0, (int) ($usage['prompt_tokens'] ?? 0)),
            'completion_tokens' => max(0, (int) ($usage['completion_tokens'] ?? 0)),
            'status' => $call['status'],
            'streamed' => $call['streamed'],
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * This key's day so far: calls made, and what is left of each limit.
     *
     * @return array{requests_today: int, requests_per_day: int, requests_per_minute: int, tokens_today: int}
     */
    public function summary(AiApiKey $key): array
    {
        $today = AiApiRequest::where('ai_api_key_id', $key->id)
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->selectRaw('count(*) as requests, coalesce(sum(prompt_tokens + completion_tokens), 0) as tokens')
            ->first();

        return [
            'requests_today' => (int) ($today->requests ?? 0),
            'tokens_today' => (int) ($today->tokens ?? 0),
            'requests_per_day' => (int) config('ai.limits.requests_per_day', 2000),
            'requests_per_minute' => (int) config('ai.limits.requests_per_minute', 20),
        ];
    }

    private function todayCount(AiApiKey $key): int
    {
        return AiApiRequest::where('ai_api_key_id', $key->id)
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->count();
    }

    private function minuteKey(AiApiKey $key): string
    {
        return "ai-api:{$key->id}";
    }
}
