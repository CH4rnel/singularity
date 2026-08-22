<?php

namespace App\Console\Commands;

use App\Models\BridgeRequest;
use App\Models\CrmContact;
use App\Models\CrmIdentityLink;
use App\Models\User;
use App\Services\Console\IdentityGraph;
use Illuminate\Console\Command;

/**
 * Read the evidence that was already in the database.
 *
 * Nobody has to be asked who is who: the rows say it. A bridge request holds
 * the account that submitted it and the address that signed the deposit; an
 * account holds the keys attached to it; a contact record holds whatever
 * identities the sync found together. Until now none of that was read, so the
 * console showed one visitor as two strangers — an account that signed in with
 * Solana, and an EVM address that bridged, with `bridge_requests` #68 naming
 * both in a single row.
 *
 * Three grades of evidence, and the difference decides whether a link joins a
 * person on its own:
 *
 *   `account`         — a key attached to an account. It got there by signing
 *                       a challenge, so it is the account's own. **Strong.**
 *   `bridge_sender`   — the address that signed a deposit, under a session
 *                       belonging to that account. Also signed. **Strong.**
 *   `contact`         — two identities the sync already filed on one record.
 *                       Somebody or something concluded this before. **Strong.**
 *   `bridge_recipient`— the address a bridge paid out to. People pay their
 *                       friends, so this is a guess and waits to be confirmed
 *                       by hand. **Weak.**
 *
 * Idempotent: re-running adds nothing that is already there and never
 * downgrades a link an operator confirmed.
 */
class CrmLinkIdentitiesCommand extends Command
{
    protected $signature = 'crm:link-identities
        {--dry-run : Report what would be linked and write nothing}';

    protected $description = 'Derive same-person links between accounts and wallet addresses';

    public function handle(IdentityGraph $graph): int
    {
        $dry = (bool) $this->option('dry-run');
        $counts = ['created' => 0, 'upgraded' => 0, 'kept' => 0];

        /*
         * Dry runs need the same answer as real ones without writing, so the
         * outcome is decided here from what is already stored rather than by
         * asking the graph to imagine it. Only what actually changes is
         * printed: a derivation that lists every bridge request it re-read is
         * a wall of text nobody checks.
         */
        $link = function (array $a, array $b, string $source, ?string $evidence, string $confidence) use ($graph, $dry, &$counts): void {
            if ($a[1] === null || $b[1] === null || trim((string) $a[1]) === '' || trim((string) $b[1]) === '') {
                return;
            }

            if ($dry) {
                [$lk, $lv, $rk, $rv] = CrmIdentityLink::order($a[0], (string) $a[1], $b[0], (string) $b[1]);

                $existing = CrmIdentityLink::query()
                    ->where('left_kind', $lk)->where('left_value', $lv)
                    ->where('right_kind', $rk)->where('right_value', $rv)
                    ->first();

                $outcome = match (true) {
                    $existing === null => 'created',
                    $confidence === 'strong' && $existing->confidence !== 'strong' => 'upgraded',
                    default => 'kept',
                };
            } else {
                [, $outcome] = $graph->linkWithOutcome(
                    $a[0], (string) $a[1], $b[0], (string) $b[1], $source, $evidence, $confidence,
                );
            }

            $counts[$outcome]++;

            if ($outcome === 'kept') {
                return;
            }

            $this->line(sprintf(
                '  %-8s %-6s %-8s %s  <->  %-8s %s   (%s)',
                $outcome,
                $confidence,
                $a[0],
                $this->shorten((string) $a[1]),
                $b[0],
                $this->shorten((string) $b[1]),
                $evidence ?? $source,
            ));
        };

        $this->line('accounts:');
        foreach (User::query()->get(['id', 'wallet_address', 'solana_wallet_address']) as $user) {
            $link(['user', (string) $user->id], ['evm', $user->wallet_address], 'account', "users #{$user->id}", 'strong');
            $link(['user', (string) $user->id], ['solana', $user->solana_wallet_address], 'account', "users #{$user->id}", 'strong');
        }

        $this->line('bridge requests:');
        foreach (BridgeRequest::query()->whereNotNull('user_id')->get() as $request) {
            $sender = $this->kindOf((string) $request->sender_address);
            $recipient = $this->kindOf((string) $request->recipient_address);

            if ($sender !== null) {
                $link(
                    ['user', (string) $request->user_id],
                    [$sender, (string) $request->sender_address],
                    'bridge_sender',
                    "bridge_requests #{$request->id}",
                    'strong',
                );
            }

            if ($recipient !== null) {
                $link(
                    ['user', (string) $request->user_id],
                    [$recipient, (string) $request->recipient_address],
                    'bridge_recipient',
                    "bridge_requests #{$request->id}",
                    'weak',
                );
            }
        }

        $this->line('contacts already holding two identities:');
        foreach (CrmContact::query()->whereNotNull('evm_address')->whereNotNull('solana_address')->get() as $contact) {
            $link(
                ['evm', (string) $contact->evm_address],
                ['solana', (string) $contact->solana_address],
                'contact',
                "crm_contacts #{$contact->id}",
                'strong',
            );
        }

        $this->newLine();
        $this->line(sprintf(
            '%s %d new, %d upgraded, %d already known.',
            $dry ? 'Would write:' : 'Wrote:',
            $counts['created'],
            $counts['upgraded'],
            $counts['kept'],
        ));

        return self::SUCCESS;
    }

    /**
     * Which chain an address belongs to, by its shape.
     *
     * Read off the value rather than the request's direction, because the
     * sender is EVM on one leg and Solana on the other and a table of
     * direction-to-side would have to be kept in step with every corridor
     * added. `0x` and forty hex digits is not a base58 key and never will be.
     */
    private function kindOf(string $address): ?string
    {
        $address = trim($address);

        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $address) === 1) {
            return 'evm';
        }

        // Base58, and long enough to be a Solana public key rather than a
        // truncated label. Anything else (a TON address, a Bitcoin address, a
        // one-time deposit address) is not an identity this graph knows.
        if (preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address) === 1) {
            return 'solana';
        }

        return null;
    }

    private function shorten(string $value): string
    {
        return strlen($value) > 18
            ? substr($value, 0, 8).'…'.substr($value, -6)
            : $value;
    }
}
