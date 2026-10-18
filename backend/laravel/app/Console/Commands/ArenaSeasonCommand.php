<?php

namespace App\Console\Commands;

use App\Services\Arena\ArenaSeasonManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class ArenaSeasonCommand extends Command
{
    protected $signature = 'arena:season {slug=season-zero} {--automatic : Freeze the bounded model and market configuration} {--start= : Explicit UTC start timestamp; omitted creates an unstarted draft}';

    protected $description = 'Create a frozen two-agent paper season without inference, signing or market requests';

    public function handle(ArenaSeasonManager $manager): int
    {
        $slug = (string) $this->argument('slug');
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $slug)) {
            $this->error('Use a lowercase season slug.');

            return self::FAILURE;
        }
        try {
            $start = $this->option('start') ? CarbonImmutable::parse((string) $this->option('start'), 'UTC') : null;
            $season = $manager->create($slug, $start, (bool) $this->option('automatic'));
        } catch (\DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Could not create the season. Check the start timestamp and local database.');

            return self::FAILURE;
        }
        $this->line(json_encode([
            'slug' => $season->slug, 'starts_at' => $season->starts_at?->toIso8601String(),
            'rules_hash' => $season->rules_hash, 'mode' => $season->rules['mode'],
            'note' => 'No agents or paid APIs were started. Rules and an existing start time cannot be overwritten.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
