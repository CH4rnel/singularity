<?php

namespace App\Console\Commands;

use App\Models\BridgeRequest;
use App\Rules\ValidDestinationAddress;
use App\Support\BridgeIntent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

#[Signature('bridge:attest-recipient
    {id? : BridgeRequest id (omit to list transfers held for a recipient proof)}
    {--recipient= : Pay this address instead of the one the claim named}
    {--note= : What proved ownership (required): who checked what, and how}')]
#[Description('Release a bridge transfer held because nobody proved who may name its recipient (B03).')]
class BridgeAttestRecipientCommand extends Command
{
    /**
     * The by-hand half of B03. A deposit whose claimant could not sign — an
     * exchange withdrawal, a contract wallet, a registration made before the
     * signature existed — is held, and an operator who has established who
     * sent it some other way says so here. The command records that and puts
     * the row back in line; it pays nothing itself, so `bridge:relay` still
     * verifies the deposit and still checks capacity before anything moves.
     */
    public function handle(): int
    {
        $id = $this->argument('id');

        if (! $id) {
            $this->showHeld();

            return self::SUCCESS;
        }

        $request = BridgeRequest::find($id);

        if (! $request) {
            $this->error("BridgeRequest #{$id} not found");

            return self::FAILURE;
        }

        if ($request->hasPayout() || $request->isCompleted()) {
            $this->error("Request #{$request->id} already has a payout; there is nothing to attest.");

            return self::FAILURE;
        }

        if (! $request->isAwaitingRecipient() && BridgeIntent::isProven($request)) {
            $this->info("Request #{$request->id} already has a proven recipient.");

            return self::SUCCESS;
        }

        $note = trim((string) $this->option('note'));

        if ($note === '') {
            $this->error('--note is required: write down what proved who owns this deposit.');

            return self::FAILURE;
        }

        $recipient = trim((string) ($this->option('recipient') ?: $request->recipient_address));
        $valid = Validator::make(
            ['recipient' => $recipient],
            ['recipient' => ['required', 'string', new ValidDestinationAddress($request->direction)]],
        );

        if ($valid->fails()) {
            $this->error($valid->errors()->first('recipient'));

            return self::FAILURE;
        }

        Log::warning('Bridge: recipient attested by an operator', [
            'id' => $request->id,
            'claimed_recipient' => $request->recipient_address,
            'recipient' => $recipient,
            'note' => $note,
        ]);

        $request->update([
            'recipient_address' => $recipient,
            'recipient_proof' => BridgeIntent::OPERATOR,
            'recipient_proven_at' => now(),
            'status' => BridgeRequest::PENDING,
            'error_message' => 'Recipient attested by an operator: '.$note,
        ]);

        $this->info("Request #{$request->id} will be paid to {$recipient}.");
        $this->line("Relay it with:  <fg=cyan>php artisan bridge:relay {$request->id}</>");

        return self::SUCCESS;
    }

    private function showHeld(): void
    {
        $held = BridgeRequest::where('status', BridgeRequest::AWAITING_RECIPIENT)
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($held->isEmpty()) {
            $this->info('No transfers are held for a recipient proof.');

            return;
        }

        $this->table(
            ['id', 'created', 'direction', 'token', 'amount', 'sender', 'claimed recipient', 'source tx'],
            $held->map(fn (BridgeRequest $r) => [
                $r->id,
                $r->created_at?->format('Y-m-d H:i'),
                $r->direction,
                $r->token,
                (string) $r->amount,
                $r->sender_address,
                $r->recipient_address,
                $r->source_tx_hash,
            ])->all(),
        );

        $this->newLine();
        $this->line('The claimed recipient is what somebody typed, not what the depositor proved.');
        $this->line('Release one:  <fg=cyan>php artisan bridge:attest-recipient {id} --note="…" [--recipient=…]</>');
    }
}
