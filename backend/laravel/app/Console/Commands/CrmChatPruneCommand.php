<?php

namespace App\Console\Commands;

use App\Models\CrmChatFile;
use App\Models\CrmChatMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Let the room forget, and take the disk with it.
 *
 * The console's room is a working record and not an archive: the decisions
 * worth keeping left it as tasks, and what stays behind is a year of "поднял
 * воркер" plus the gigabytes attached to it. A file goes with the message
 * that brought it, because a file whose reason has been deleted is exactly
 * the orphan this feature exists to avoid.
 */
class CrmChatPruneCommand extends Command
{
    protected $signature = 'crm:chat-prune';

    protected $description = 'Delete console chat messages and their files past the retention window';

    public function handle(): int
    {
        $days = (int) config('crm.chat.files.retention_days');

        if ($days <= 0) {
            $this->warn('Retention is disabled (crm.chat.files.retention_days <= 0); nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $files = 0;
        $bytes = 0;

        // The rows cascade, but the bytes do not: delete what is on disk
        // first, in batches, so a room with thousands of attachments does not
        // load them all to remember their paths.
        CrmChatFile::query()
            ->whereHas('message', fn ($query) => $query->where('created_at', '<', $cutoff))
            ->chunkById(200, function ($chunk) use (&$files, &$bytes): void {
                foreach ($chunk as $file) {
                    Storage::disk('local')->delete($file->path);
                    $files++;
                    $bytes += $file->size;
                }
            });

        $messages = CrmChatMessage::query()->where('created_at', '<', $cutoff)->delete();

        $this->info(sprintf(
            'Pruned %d message(s) and %d file(s) (%s MB) older than %d day(s).',
            $messages,
            $files,
            number_format($bytes / 1_048_576, 1),
            $days,
        ));

        return self::SUCCESS;
    }
}
