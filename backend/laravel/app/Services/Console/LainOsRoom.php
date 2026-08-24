<?php

namespace App\Services\Console;

use App\Models\CrmChatFile;
use App\Models\CrmChatMessage;
use App\Services\LainChatService;
use Illuminate\Support\Facades\Cache;
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

    /** How long a reading of the daemon's live provider is reused. */
    private const PROVIDER_TTL = 60;

    private const PROVIDER_KEY = 'crm-chat:lainos:provider';

    /**
     * Which backends could answer, and — for the daemon — which model would.
     *
     * The first half is configuration. The second is a **probe**, because
     * "which model is LainOS on right now" is not a config value at all: the
     * daemon switches providers at runtime (TUI `/model`, its own action, this
     * console) and only it knows what it currently delegates to. One cheap
     * reading, cached for a minute, and an unreadable daemon says so instead
     * of showing the last thing it happened to say.
     *
     * @return array{daemon: bool, persona: bool, backend: string|null, provider: array<string, mixed>|null, choices: list<array<string, string>>, probe: string}
     */
    public function status(): array
    {
        $daemon = $this->daemonUrl() !== null;
        $persona = $this->persona->enabled() && (bool) config('crm.chat.lainos.fallback', true);
        $reading = $daemon ? $this->daemonProvider() : null;

        return [
            'daemon' => $daemon,
            'persona' => $persona,
            'backend' => match (true) {
                $daemon => 'daemon',
                $persona => 'persona',
                default => null,
            },
            'provider' => $reading['provider'] ?? null,
            'choices' => $reading['choices'] ?? [],
            'probe' => match (true) {
                ! $daemon => 'off',
                ($reading['provider'] ?? null) !== null => 'ok',
                default => 'unreadable',
            },
        ];
    }

    /**
     * The daemon's live chat provider: kind, ensemble name and the model id
     * that answers a chat turn, plus what it can be switched to.
     *
     * @return array{provider: array<string, mixed>|null, choices: list<array<string, string>>}
     */
    public function daemonProvider(bool $fresh = false): array
    {
        $empty = ['provider' => null, 'choices' => []];
        $url = $this->daemonUrl();

        if ($url === null) {
            return $empty;
        }

        if (! $fresh) {
            $cached = Cache::get(self::PROVIDER_KEY);

            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(2)
                ->timeout(4)
                ->get(rtrim($url, '/').'/provider');

            $reading = $response->successful() && is_array($response->json('provider'))
                ? [
                    'provider' => $response->json('provider'),
                    'choices' => array_values((array) $response->json('choices', [])),
                ]
                : $empty;
        } catch (Throwable $exception) {
            Log::warning('LainOS room: provider unreadable', ['error' => $exception->getMessage()]);
            $reading = $empty;
        }

        // A failed reading is cached too, and for the same minute: otherwise a
        // dead daemon costs every page load its connect timeout.
        Cache::put(self::PROVIDER_KEY, $reading, self::PROVIDER_TTL);

        return $reading;
    }

    /**
     * Re-route the daemon's live replies to another provider.
     *
     * The console is one of three switch surfaces the daemon already has, and
     * the only one an operator has open while reading its answers. It fails
     * loudly — a missing CLI or key must never land the room on a mock model
     * without saying so.
     *
     * @return array{ok: bool, provider?: array<string, mixed>, error?: string}
     */
    public function switchProvider(string $kind): array
    {
        $url = $this->daemonUrl();

        if ($url === null) {
            return ['ok' => false, 'error' => 'no_daemon'];
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(2)
                ->timeout(15)
                ->post(rtrim($url, '/').'/provider', ['provider' => $kind]);
        } catch (Throwable $exception) {
            Log::warning('LainOS room: provider switch failed', ['error' => $exception->getMessage()]);

            return ['ok' => false, 'error' => 'unreachable'];
        }

        Cache::forget(self::PROVIDER_KEY);

        if (! $response->successful() || ! is_array($response->json('provider'))) {
            return [
                'ok' => false,
                'error' => is_string($response->json('error'))
                    ? $response->json('error')
                    : 'http_'.$response->status(),
            ];
        }

        $this->daemonProvider(fresh: true);

        return ['ok' => true, 'provider' => $response->json('provider')];
    }

    public function enabled(): bool
    {
        return $this->status()['backend'] !== null;
    }

    /**
     * Answer one call, and account for the attempt either way.
     *
     * Always returns the same shape: `text` is null when nothing answered,
     * and `attempts` is what was tried and what came back — the room prints
     * that under "LainOS не отвечает" so a failure is debuggable from the
     * screen where it happened rather than from laravel.log.
     *
     * @return array{text: string|null, meta: array<string, mixed>, attempts: list<array<string, mixed>>}
     */
    public function answer(CrmChatMessage $call): array
    {
        $context = $this->context($call);
        $asked = trim((string) $call->body);
        $who = $call->sender?->name ?? 'оператор';
        $attempts = [];

        if ($this->daemonUrl() !== null) {
            $answer = $this->askDaemon($context, $asked, $who, $call, $attempts);

            if ($answer !== null) {
                return $answer + ['attempts' => $attempts];
            }
        }

        if ($this->persona->enabled() && (bool) config('crm.chat.lainos.fallback', true)) {
            $answer = $this->askPersona($context, $asked, $who, $attempts);

            if ($answer !== null) {
                return $answer + ['attempts' => $attempts];
            }
        }

        return ['text' => null, 'meta' => [], 'attempts' => $attempts];
    }

    /**
     * The daemon: one flat message, because that is its whole protocol.
     *
     * @param  array{lines: list<string>, files: list<string>, quoted: string|null, count: int}  $context
     * @return array{text: string, meta: array<string, mixed>}|null
     */
    private function askDaemon(array $context, string $asked, string $who, CrmChatMessage $call, array &$attempts): ?array
    {
        $started = microtime(true);
        // Read *before* the turn: the daemon may be switched from three other
        // surfaces, and the model that answers is the one live at this moment.
        $reading = $this->daemonProvider();

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
                $attempts[] = $this->attempt('daemon', 'http_'.$response->status(), $started, $reading);

                return null;
            }

            $text = $response->json('text');
            $text = is_string($text) ? trim($text) : '';

            // The turn reports the model that actually produced it —
            // "codex/gpt-5.6-sol" rather than the probe's "codex". Provenance
            // beats a reading taken a moment earlier, so this wins when the
            // daemon sends it and the probe is only the fallback.
            $turnModel = $response->json('model');
            $turnModel = is_string($turnModel) && $turnModel !== '' ? $turnModel : null;

            if ($text === '') {
                $attempts[] = $this->attempt('daemon', 'empty', $started, $reading, $turnModel);

                return null;
            }

            $attempts[] = $this->attempt('daemon', 'ok', $started, $reading, $turnModel);

            return [
                'text' => $text,
                'meta' => $this->meta('daemon', $context, $started, $reading, $turnModel),
            ];
        } catch (Throwable $exception) {
            Log::warning('LainOS room: daemon unreachable', ['error' => $exception->getMessage()]);
            $attempts[] = $this->attempt(
                'daemon',
                str_contains($exception->getMessage(), 'imed out') ? 'timeout' : 'unreachable',
                $started,
                $reading,
            );

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
    private function askPersona(array $context, string $asked, string $who, array &$attempts): ?array
    {
        $started = microtime(true);

        try {
            $reply = $this->persona->replyForConsole(
                $this->prompt($context, $asked, $who),
            );
        } catch (Throwable $exception) {
            Log::warning('LainOS room: persona failed', ['error' => $exception->getMessage()]);
            $attempts[] = $this->attempt('persona', 'failed', $started, null);

            return null;
        }

        if (trim($reply['text']) === '') {
            $attempts[] = $this->attempt('persona', 'empty', $started, null);

            return null;
        }

        $attempts[] = $this->attempt('persona', 'ok', $started, null, $reply['model']);

        return [
            'text' => trim($reply['text']),
            // The served model, straight from the provider's own answer.
            'meta' => $this->meta(
                'persona',
                $context,
                $started,
                ['provider' => ['kind' => 'openrouter', 'name' => 'openrouter']],
                // OpenRouter names the model it actually served in the reply.
                $reply['model'],
            ),
        ];
    }

    /**
     * One line of the attempt log: who was asked, what came back, how long.
     *
     * @param  array{provider?: array<string, mixed>|null}|null  $reading
     * @return array<string, mixed>
     */
    private function attempt(
        string $backend,
        string $outcome,
        float $started,
        ?array $reading,
        ?string $model = null,
    ): array {
        return [
            'backend' => $backend,
            'outcome' => $outcome,
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'model' => $model ?? $reading['provider']['model'] ?? null,
        ];
    }

    /**
     * The stamp the room prints under an answer.
     *
     * Written at answer time and never back-filled: switching the daemon
     * tomorrow must not rewrite what answered today.
     *
     * Two sources for the model and they are not equal. `turn` is what the
     * daemon says produced this very reply; `probe` is what it said it was on
     * a moment earlier, which is a good-faith reading and not proof. The
     * source is recorded, because "which model answered" is the question this
     * stamp exists for.
     *
     * @param  array{lines: list<string>, files: list<string>, quoted: string|null, count: int}  $context
     * @param  array{provider?: array<string, mixed>|null}|null  $reading
     * @return array<string, mixed>
     */
    private function meta(
        string $backend,
        array $context,
        float $started,
        ?array $reading,
        ?string $turnModel = null,
    ): array {
        $provider = $reading['provider'] ?? null;

        return [
            'backend' => $backend,
            'model' => $turnModel ?? $provider['model'] ?? null,
            'model_source' => $turnModel !== null ? 'turn' : ($provider === null ? null : 'probe'),
            'provider' => $provider['kind'] ?? null,
            'ensemble' => $provider['name'] ?? null,
            'overridden' => $provider['overridden'] ?? null,
            'ms' => (int) round((microtime(true) - $started) * 1000),
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
