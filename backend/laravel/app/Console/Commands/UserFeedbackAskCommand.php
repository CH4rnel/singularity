<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\FeedbackRequestNotification;
use App\Services\Console\IdentityGraph;
use Illuminate\Console\Command;

/**
 * Ask one named person for feedback, from the operator's own hands.
 *
 * A command rather than a screen because this is not a feature: it is an
 * operator deciding, about one person whose session they have read, that the
 * thing worth knowing is not in the database. It refuses to run without a
 * user id for the same reason — there is no "all users" here, and the day
 * there is, it should be a different tool with a different name.
 *
 * `--dry-run` prints exactly what the person would see. Use it: the reader is
 * a stranger who is currently owed money, and the wording is the whole thing.
 */
class UserFeedbackAskCommand extends Command
{
    protected $signature = 'user:ask-feedback
        {user : A users.id, an EVM address or a Solana address}
        {--title= : Headline shown in the bell}
        {--body= : The sentence under it}
        {--url= : Where the notice links (an external URL is fine)}
        {--dry-run : Print what would be sent and send nothing}';

    protected $description = 'Send one user an in-app request for feedback';

    public function handle(IdentityGraph $identities): int
    {
        $user = $this->resolve((string) $this->argument('user'), $identities);

        if ($user === null) {
            $this->error('Nobody to notify: no account, and no link from that address to one. Try `crm:link-identities`, or link it by hand on the person\'s page.');

            return self::FAILURE;
        }

        $title = (string) ($this->option('title') ?: 'How did it go?');
        $body = (string) ($this->option('body') ?: 'We would like to hear what happened. Telegram @rtutin, or @cyberia_temple on X.');
        $url = (string) ($this->option('url') ?: 'https://x.com/cyberia_temple');

        $this->newLine();
        $this->line("  to     #{$user->id}  {$user->name}");
        $this->line("  title  {$title}");
        $this->line("  body   {$body}");
        $this->line("  url    {$url}");
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing sent.');

            return self::SUCCESS;
        }

        $user->notify(new FeedbackRequestNotification($title, $body, $url));

        $this->info('Sent.');

        return self::SUCCESS;
    }

    /**
     * Find the person behind whatever was typed.
     *
     * An address is how an operator knows somebody — it is what the explorer
     * shows and what a bridge row holds — but a notification has to land on an
     * account, and a wallet address is not one. So an address is resolved
     * through the same-person graph, which is exactly the case this was built
     * for: a visitor who signed in with Solana and bridged from an EVM key is
     * one person the console used to file as two.
     */
    private function resolve(string $who, IdentityGraph $identities): ?User
    {
        if (ctype_digit($who)) {
            return User::find((int) $who);
        }

        $kind = match (true) {
            preg_match('/^0x[0-9a-fA-F]{40}$/', $who) === 1 => 'evm',
            preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $who) === 1 => 'solana',
            default => null,
        };

        if ($kind === null) {
            return null;
        }

        $user = $identities->userForAddress($kind, $who);

        if ($user !== null) {
            $this->line("  resolved {$kind} {$who} → account #{$user->id}");
        }

        return $user;
    }
}
