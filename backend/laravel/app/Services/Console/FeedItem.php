<?php

namespace App\Services\Console;

use Carbon\CarbonImmutable;

/**
 * One line of the queue.
 *
 * Two decisions are baked into the shape. First, an item carries how long it
 * has been in this state, not when it was found: twelve minutes and three
 * hours ask for different things and look identical on a status board, so the
 * duration is the priority and it gets the left-hand column.
 *
 * Second, the item carries a key and parameters instead of a sentence. The
 * console is read in Russian and in English, and a server that composes prose
 * ends up owning two copies of every phrase; the browser holds the dictionary
 * and fills `{placeholders}` from `params`.
 */
readonly class FeedItem
{
    /**
     * @param  string  $key  Stable across sweeps — the same tank is the same item, so it can be snoozed.
     * @param  'critical'|'warning'|'money'|'neutral'|'unknown'  $severity
     * @param  array<string, string|int|float|null>  $params
     * @param  array<string, mixed>|null  $evidence  What is drawn on the right: a day strip, a spark, a number.
     * @param  array{key: string, href: string, external?: bool}|null  $action
     */
    public function __construct(
        public string $key,
        public string $kind,
        public string $severity,
        public string $titleKey,
        public string $bodyKey,
        public array $params = [],
        public ?CarbonImmutable $since = null,
        public ?array $evidence = null,
        public ?array $action = null,
        public bool $snoozable = true,
        public ?CarbonImmutable $snoozedUntil = null,
    ) {}

    public function durationSeconds(): ?int
    {
        return $this->since === null ? null : max(0, CarbonImmutable::now()->getTimestamp() - $this->since->getTimestamp());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind,
            'severity' => $this->severity,
            'title' => $this->titleKey,
            'body' => $this->bodyKey,
            'params' => $this->params,
            'since' => $this->since?->toIso8601String(),
            'duration_seconds' => $this->durationSeconds(),
            'evidence' => $this->evidence,
            'action' => $this->action,
            'snoozable' => $this->snoozable,
            'snoozed_until' => $this->snoozedUntil?->toIso8601String(),
        ];
    }

    /** Ranking weight of the severities, worst first. */
    public static function weight(string $severity): int
    {
        return match ($severity) {
            'critical' => 0,
            'warning' => 1,
            'money' => 2,
            'neutral' => 3,
            default => 4,
        };
    }
}
