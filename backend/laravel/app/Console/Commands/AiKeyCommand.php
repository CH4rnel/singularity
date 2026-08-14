<?php

namespace App\Console\Commands;

use App\Models\AiApiKey;
use App\Services\Ai\AiKeyService;
use App\Services\Ai\AiUsageMeter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Operator-side key administration for the inference API.
 *
 * Holders issue their own keys by signing a challenge; this command exists for
 * the two cases that flow cannot cover: a key for one of Cyberia's own
 * services (which holds nothing and never will, hence --service), and killing
 * a key when its holder cannot.
 *
 * The key is printed once, here, and is unrecoverable afterwards — only its
 * hash is stored. Treat the output like any other secret: it is one.
 */
class AiKeyCommand extends Command
{
    protected $signature = 'ai:key
        {action : issue, list or revoke}
        {target? : an 0x address (issue, list) or a key id (revoke)}
        {--name= : a label for the key}
        {--service : issue a key exempt from the holder gate (Cyberia services only)}';

    protected $description = 'Issue, list or revoke keys for the Cyberia inference API';

    public function handle(AiKeyService $keys, AiUsageMeter $meter): int
    {
        return match ($this->argument('action')) {
            'issue' => $this->issue($keys),
            'list' => $this->list($keys, $meter),
            'revoke' => $this->revoke($keys),
            default => $this->refuse('Unknown action. Use issue, list or revoke.'),
        };
    }

    private function issue(AiKeyService $keys): int
    {
        $address = $this->address();

        if ($address === null) {
            return self::FAILURE;
        }

        $service = (bool) $this->option('service');

        ['key' => $key, 'token' => $token] = $keys->issue($address, $this->option('name'), $service);

        $this->info("Key #{$key->id} issued to {$address}".($service ? ' (gate-exempt service key)' : ''));
        $this->newLine();
        $this->line($token);
        $this->newLine();
        $this->warn('Copy it now — it is stored only as a hash and cannot be shown again.');

        return self::SUCCESS;
    }

    private function list(AiKeyService $keys, AiUsageMeter $meter): int
    {
        $address = $this->address();

        if ($address === null) {
            return self::FAILURE;
        }

        $rows = $keys->forAddress($address)->map(fn (AiApiKey $key): array => [
            $key->id,
            $key->prefix.'…',
            $key->name ?? '—',
            $key->gate_exempt ? 'service' : 'holder',
            $key->last_used_at?->diffForHumans() ?? 'never',
            $key->revoked() ? 'revoked' : 'active',
            $meter->summary($key)['requests_today'],
        ])->all();

        if ($rows === []) {
            $this->warn("No keys for {$address}.");

            return self::SUCCESS;
        }

        $this->table(['id', 'prefix', 'name', 'kind', 'last used', 'state', 'today'], $rows);

        return self::SUCCESS;
    }

    private function revoke(AiKeyService $keys): int
    {
        $id = (int) $this->argument('target');
        $key = AiApiKey::find($id);

        if ($key === null) {
            return $this->refuse("No key with id {$id}.");
        }

        $keys->revoke($key);
        $this->info("Key #{$key->id} ({$key->prefix}…, {$key->address}) revoked.");

        return self::SUCCESS;
    }

    private function address(): ?string
    {
        $address = Str::lower(trim((string) $this->argument('target')));

        if (! preg_match('/^0x[a-f0-9]{40}$/', $address)) {
            $this->error('Pass an 0x EVM address as the target.');

            return null;
        }

        return $address;
    }

    /** Laravel's own fail() throws; this one just reports and exits non-zero. */
    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
