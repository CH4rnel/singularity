<?php

namespace App\Console\Commands;

use App\Services\X402\FacilitatorClient;
use App\Services\X402\PaymentTerms;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Does the paywall actually work — asked before a caller has to find out.
 *
 * An x402 seller can be wrong in ways nothing else notices: the facilitator
 * may not serve the network we quote, the price may round to zero atomic
 * units, the payee may be unset, or the whole thing may be pointed at the
 * testnet facilitator while quoting mainnet terms. Each of those produces a
 * paywall that looks configured and collects nothing.
 *
 * Reads only, and needs no key: the facilitator holds the funds' path, this
 * host holds none of it.
 */
#[Signature('x402:check')]
#[Description('Check the x402 paywall: terms, payee, and whether the facilitator serves them')]
class X402CheckCommand extends Command
{
    public function handle(PaymentTerms $terms, FacilitatorClient $facilitator): int
    {
        if (! (bool) config('x402.enabled')) {
            $this->warn('x402 is off. Set X402_ENABLED=true once the settings below are right.');
        }

        if (($missing = $terms->missing()) !== []) {
            $this->error('Unset: '.implode(', ', $missing));
            $this->line('  The paywall stands aside entirely until these are set.');

            return self::FAILURE;
        }

        $network = (string) config('x402.network');
        $scheme = (string) config('x402.scheme');
        $price = (string) config('x402.ai.price');

        $this->line('Terms');
        $this->line("  network      {$network}");
        $this->line("  scheme       {$scheme}");
        $this->line(sprintf(
            '  asset        %s (%s, %d decimals)',
            (string) config('x402.asset.symbol'),
            (string) config('x402.asset.address'),
            (int) config('x402.asset.decimals'),
        ));
        $this->line(sprintf(
            '  eip-712      name "%s", version "%s"',
            (string) config('x402.asset.name'),
            (string) config('x402.asset.version'),
        ));
        $this->line('  pay to       '.(string) config('x402.pay_to'));

        try {
            $atomic = $terms->atomic($price);
        } catch (\RuntimeException $e) {
            $this->error('  price        '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("  price        {$price} ({$atomic} atomic units per call)");
        $this->newLine();

        $url = (string) config('x402.facilitator.url');
        $this->line("Facilitator {$url}");

        $supported = $facilitator->supported();

        if (! $supported['ok']) {
            $this->error('  unreachable: '.$supported['error']);

            return self::FAILURE;
        }

        $serves = array_filter(
            $supported['kinds'],
            static fn (array $kind): bool => ($kind['network'] ?? null) === $network
                && ($kind['scheme'] ?? null) === $scheme,
        );

        if ($serves === []) {
            $this->error("  it does not serve {$scheme} on {$network}.");
            $this->line('  It serves: '.($this->kinds($supported['kinds']) ?: 'nothing it would name'));
            $this->line('  Either point X402_FACILITATOR_URL at one that does, or change X402_NETWORK.');

            return self::FAILURE;
        }

        $this->info("  serves {$scheme} on {$network}.");

        foreach ((array) $supported['signers'] as $pattern => $signers) {
            $this->line("  signs as     {$pattern}: ".implode(', ', array_map('strval', (array) $signers)));
        }

        if ($supported['extensions'] !== []) {
            $this->line('  extensions   '.implode(', ', array_map('strval', $supported['extensions'])));
        }

        $this->newLine();
        $this->info((bool) config('x402.enabled')
            ? 'The paywall is live: an unpaid call to /api/ai/v1/chat/completions is answered 402 with these terms.'
            : 'These terms are sound. X402_ENABLED=true switches them on.');

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $kinds */
    private function kinds(array $kinds): string
    {
        $names = array_map(
            static fn (array $kind): string => sprintf('%s on %s', $kind['scheme'] ?? '?', $kind['network'] ?? '?'),
            $kinds,
        );

        return implode(', ', array_slice(array_unique($names), 0, 12));
    }
}
