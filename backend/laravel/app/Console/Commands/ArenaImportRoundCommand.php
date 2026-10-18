<?php

namespace App\Console\Commands;

use App\Models\ArenaSeason;
use App\Services\Arena\ArenaSeasonManager;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;

class ArenaImportRoundCommand extends Command
{
    protected $signature = 'arena:import-round {season} {file : Local JSON evidence file, maximum 32 KB}';

    protected $description = 'Validate operator-supplied decisions and price evidence and append one replayable paper round';

    public function handle(ArenaSeasonManager $manager): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 32768) {
            $this->error('Supply a readable local JSON file no larger than 32 KB.');

            return self::FAILURE;
        }
        $season = ArenaSeason::query()->where('slug', $this->argument('season'))->first();
        if (! $season) {
            $this->error('Create the season first with arena:season.');

            return self::FAILURE;
        }
        try {
            $payload = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || array_is_list($payload)) {
                throw new DomainException('Expected a JSON object.');
            }
            $round = $manager->import($season, $payload, CarbonImmutable::now());
        } catch (JsonException|ValidationException|DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Accepted paper round '.$round->sequence.'. Record hash: '.$round->record_hash);

        return self::SUCCESS;
    }
}
