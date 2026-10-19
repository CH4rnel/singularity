<?php

namespace Database\Factories;

use App\Models\LaunchpadToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LaunchpadToken>
 */
class LaunchpadTokenFactory extends Factory
{
    /**
     * A launched token with no homepage yet: a vacant lot in the underground.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $symbol = Str::upper(fake()->unique()->lexify('???'));

        return [
            'chain_id' => 49406,
            'address' => '0x'.Str::lower(fake()->unique()->regexify('[a-f0-9]{40}')),
            'creator' => '0x'.Str::lower(fake()->regexify('[a-f0-9]{40}')),
            'name' => fake()->words(2, true),
            'symbol' => $symbol,
            'description' => fake()->sentence(),
            'launched_at' => now()->subDays(fake()->numberBetween(1, 60)),
        ];
    }

    /** A token that moved in: an uploaded page on its own subdomain. */
    public function withSite(?string $subdomain = null): static
    {
        return $this->state(fn (array $attributes) => [
            'html_path' => 'launchpad-sites/'.$attributes['chain_id'].'-'.$attributes['address'].'.html',
            'site_subdomain' => $subdomain ?? Str::lower($attributes['symbol']),
            'site_updated_at' => now(),
        ]);
    }
}
