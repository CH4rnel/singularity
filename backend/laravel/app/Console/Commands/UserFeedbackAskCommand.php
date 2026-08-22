<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\FeedbackRequestNotification;
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
        {user : The users.id to notify}
        {--title= : Headline shown in the bell}
        {--body= : The sentence under it}
        {--url= : Where the notice links (an external URL is fine)}
        {--dry-run : Print what would be sent and send nothing}';

    protected $description = 'Send one user an in-app request for feedback';

    public function handle(): int
    {
        $user = User::find((int) $this->argument('user'));

        if ($user === null) {
            $this->error('No such user.');

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
}
