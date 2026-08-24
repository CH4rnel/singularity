<?php

namespace App\Console\Commands;

use App\Services\Ai\AiModelCatalog;
use App\Services\Ai\Providers\AiProviderRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Is the inference API actually wired up on this host?
 *
 * The question a deploy needs answered, and the one thing a key cannot be
 * asked about safely any other way: `--probe` spends one two-token completion
 * per model to prove that the key in the environment is accepted upstream.
 * Nothing here prints a key, and nothing needs one pasted into a shell.
 */
class AiProvidersCommand extends Command
{
    protected $signature = 'ai:providers {--probe : send one tiny completion per model}';

    protected $description = 'Show which inference providers are configured, and optionally prove their keys work';

    public function handle(AiProviderRegistry $registry, AiModelCatalog $catalog): int
    {
        $configured = $registry->names();

        if ($configured === []) {
            $this->error('No inference provider has a key on this host — /api/ai/v1 serves nothing.');
            $this->line('Set at least one provider credential from `.env.example`, then run `php artisan config:cache`.');

            return self::FAILURE;
        }

        $this->info('Providers with a key: '.implode(', ', $configured));

        $models = $catalog->models();

        if ($models === []) {
            $this->warn('No catalogue model maps to a configured provider.');

            return self::FAILURE;
        }

        $rows = [];

        foreach ($models as $model) {
            $row = [$model['id'], $model['provider'], $model['upstream'], $model['fallback'] ?? '—'];

            if ($this->option('probe')) {
                $row[] = $this->probe($registry, $model);
            }

            $rows[] = $row;
        }

        $this->table(
            array_merge(['id', 'provider', 'upstream', 'fallback'], $this->option('probe') ? ['probe'] : []),
            $rows,
        );

        return self::SUCCESS;
    }

    /** @param  array{provider: string, upstream: string}  $model */
    private function probe(AiProviderRegistry $registry, array $model): string
    {
        try {
            $body = $registry->get($model['provider'])->chat($model['upstream'], [
                'messages' => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 2,
            ]);

            return is_string($body['choices'][0]['message']['content'] ?? null) ? 'ok' : 'empty reply';
        } catch (Throwable $e) {
            return 'failed: '.$e->getMessage();
        }
    }
}
