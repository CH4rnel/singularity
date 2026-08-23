<?php

namespace App\Services\Console;

use App\Models\CrmChatFile;
use App\Models\CrmChatMessage;
use App\Services\LainChatService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * LainOS as a participant in the operators' room.
 *
 * Two correspondents answer to that name and they are not interchangeable.
 * The **daemon** (services/lainos, `POST /chat` on the host's loopback) has
 * tools, memory and a wallet: it can go and look. The **persona**
 * (LainChatService, straight to OpenRouter) has none of that and can only
 * reason about what it was handed. So every answer is stamped with which one
 * gave it, and when neither can be reached the room says exactly that instead
 * of producing an answer nobody stands behind.
 *
 * What goes up is deliberately narrow and is printed under every answer:
 * the last N lines of the room, the names and sizes of their files, and the
 * contents of a text file only when it is attached to the line that called.
 * Nothing here reads .env, a key, a wallet or another surface's messages.
 */
class LainOsRoom
{
    public function __construct(private LainChatService $persona) {}

    /**
     * Which backends could answer right now.
     *
     * A configuration answer, not a probe: an operator opening the room
     * should not cost a request to the daemon, and "reachable" is only ever
     * known by trying.
     *
     * @return array{daemon: bool, persona: bool, backend: string|null}
     */
    public function status(): array
    {
        $daemon = $this->daemonUrl() !== null;
        $persona = $this->persona->enabled() && (bool) config('crm.chat.lainos.fallback', true);

        return [
            'daemon' => $daemon,
            'persona' => $persona,
            'backend' => match (true) {
                $daemon => 'daemon',
                $persona => 'persona',
                default => null,
            },
        ];
    }

    public function enabled(): bool
    {
        return $this->status()['backend'] !== null;
    }

    /**
     * Answer one call.
     *
     * Returns the text and the stamp that goes under it, or null when no
     * backend produced anything — the caller writes the room's "does not
     * answer" state from that, and never a sentence of its own.
     *
     * @return array{text: string, meta: array<string, mixed>}|null
     */
    public function answer(CrmChatMessage $call): ?array
    {
        $context = $this->context($call);
        $asked = trim((string) $call->body);
        $who = $call->sender?->name ?? 'оператор';

        if ($this->daemonUrl() !== null) {
            $answer = $this->askDaemon($context, $asked, $who, $call);

            if ($answer !== null) {
                return $answer;
            }
        }

        if ($this->persona->enabled() && (bool) config('crm.chat.lainos.fallback', true)) {
            return $this->askPersona($context, $asked, $who);
        }

        return null;
    }

    /**
     * The daemon: one flat message, because that is its whole protocol.
     *
     * @param  array{lines: list<string>, files: list<string>, quoted: string|null, count: int}  $context
     * @return array{text: string, meta: array<string, mixed>}|null
     */
    private function askDaemon(array $context, string $asked, string $who, CrmChatMessage $call): ?array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout((int) config('services.lainos.timeout_seconds', 60))
                ->post(rtrim((string) $this->daemonUrl(), '/').'/chat', [
                    'roomId' => (string) config('services.lainos.room', 'cyberia-console'),
                    'userId' => 'console:'.($call->user_id ?? 0),
                    'text' => $this->prompt($context, $asked, $who),
                ]);

            if (! $response->successful()) {
                Log::warning('LainOS room: daemon refused', ['status' => $response->status()]);

                return null;
            }

            $text = $response->json('text');
            $text = is_string($text) ? trim($text) : '';

            if ($text === '') {
                return null;
            }

            return [
                'text' => $text,
                'meta' => $this->meta('daemon', 'lainos', $context),
            ];
        } catch (Throwable $exception) {
            Log::warning('LainOS room: daemon unreachable', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * The persona: the same conversation as a message list, which is what an
     * OpenAI-shaped endpoint wants.
     *
     * @param  array{lines: list<string>, files: list<string>, quoted: string|null, count: int}  $context
     * @return array{text: string, meta: array<string, mixed>}|null
     */
    private function askPersona(array $context, string $asked, string $who): ?array
    {
        try {
            $reply = $this->persona->replyForConsole(
                $this->prompt($context, $asked, $who),
            );
        } catch (Throwable $exception) {
            Log::warning('LainOS room: persona failed', ['error' => $exception->getMessage()]);

            return null;
        }

        if (trim($reply['text']) === '') {
            return null;
        }

        return [
            'text' => trim($reply['text']),
            'meta' => $this->meta('persona', $reply['model'], $context),
        ];
    }

    /**
     * The stamp the room prints under an answer.
     *
     * @param  array{lines: list<string>, files: list<string>, quoted: string|null, count: int}  $context
     * @return array<string, mixed>
     */
    private function meta(string $backend, string $model, array $context): array
    {
        return [
            'backend' => $backend,
            'model' => $model,
            'context' => [
                'messages' => $context['count'],
                'files' => $context['files'],
                'quoted' => $context['quoted'],
            ],
        ];
    }

    /**
     * Everything the call is allowed to see, in one block.
     *
     * @param  array{lines: list<string>, files: list<string>, quoted: string|null, count: int}  $context
     */
    private function prompt(array $context, string $asked, string $who): string
    {
        $parts = [
            'Ты — LainOS в рабочей комнате пульта Cyberia. В комнате только операторы проекта; отвечай коротко и по делу, на языке вопроса.',
            'Ты видишь ровно то, что ниже: последние сообщения комнаты и, если файл приложен к вопросу, его начало. Ничего другого у тебя нет — не выдумывай числа, балансы и состояние сервисов, а говори, где их посмотреть.',
            '',
            '# Последние сообщения',
            ...$context['lines'],
        ];

        if ($context['files'] !== []) {
            $parts[] = '';
            $parts[] = '# Файлы в этих сообщениях';
            $parts = [...$parts, ...array_map(fn (string $file) => '- '.$file, $context['files'])];
        }

        if ($context['quoted'] !== null) {
            $parts[] = '';
            $parts[] = '# Начало приложенного файла';
            $parts[] = $context['quoted'];
        }

        $parts[] = '';
        $parts[] = "# Вопрос от {$who}";
        $parts[] = $asked;

        return implode("\n", $parts);
    }

    /**
     * The room as LainOS gets it: the last N lines, their files, and the
     * text of a file attached to the call itself.
     *
     * @return array{lines: list<string>, files: list<string>, quoted: string|null, count: int}
     */
    private function context(CrmChatMessage $call): array
    {
        $limit = max(1, (int) config('crm.chat.lainos.context_messages', 20));

        $messages = CrmChatMessage::query()
            ->with(['sender:id,name', 'files'])
            ->where('id', '<=', $call->id)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $lines = [];
        $files = [];

        foreach ($messages as $message) {
            $name = $message->isFromLainos()
                ? 'LainOS'
                : ($message->sender?->name ?? 'оператор');
            $time = $message->created_at?->timezone(config('crm.console.timezone'))->format('H:i');
            $body = trim((string) $message->body);

            $lines[] = "[{$time}] {$name}: ".($body === '' ? '(файл без подписи)' : $body);

            foreach ($message->files as $file) {
                $files[] = $file->name.' ('.$this->human($file->size).')';
            }
        }

        return [
            'lines' => $lines,
            'files' => array_values(array_unique($files)),
            'quoted' => $this->quote($call),
            'count' => $messages->count(),
        ];
    }

    /**
     * The head of a text file attached to the calling line.
     *
     * Attaching the file to the question is the ask — there is no other way
     * to get a file's contents in front of LainOS, and a file attached to
     * some other line stays a name and a size.
     */
    private function quote(CrmChatMessage $call): ?string
    {
        $file = $call->files->first(fn (CrmChatFile $file) => $file->isReadableText());

        if ($file === null) {
            return null;
        }

        $bytes = max(500, (int) config('crm.chat.lainos.file_bytes', 8000));

        try {
            $contents = Storage::disk('local')->get($file->path);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        return $file->name.":\n".Str::limit($contents, $bytes, "\n… (обрезано)");
    }

    private function human(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_048_576 => round($bytes / 1_048_576, 1).' МБ',
            $bytes >= 1024 => round($bytes / 1024).' КБ',
            default => $bytes.' Б',
        };
    }

    /** The daemon's address, or null when it was never wired up here. */
    private function daemonUrl(): ?string
    {
        $url = trim((string) config('services.lainos.url', ''));

        return $url === '' ? null : $url;
    }
}
