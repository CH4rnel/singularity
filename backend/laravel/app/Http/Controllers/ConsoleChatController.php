<?php

namespace App\Http\Controllers;

use App\Models\CrmChatFile;
use App\Models\CrmChatMessage;
use App\Models\CrmTask;
use App\Services\Console\ChatRoom;
use App\Services\Console\ConsoleFeed;
use App\Services\Console\LainOsRoom;
use App\Services\Console\TaskLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Чат" — the operators' room, and the files that came into it.
 *
 * The room is the sixth lens rather than a separate application: it holds the
 * talking that the other five lenses cause, and the one action on a line is
 * to turn it into a task, because a room is where work is decided and the
 * board is where it is remembered.
 *
 * Files never get a public address. They are written to the private disk and
 * handed back only through `download`, which lives behind the same operator
 * gate as everything else under /crm — a link that works for a stranger is
 * the one door this console cannot afford.
 */
class ConsoleChatController extends Controller
{
    public function __construct(
        private ChatRoom $room,
        private LainOsRoom $lainos,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $before = $request->integer('before') ?: null;
        $page = $this->room->page($user, $before);

        // Opening the room is reading it. The badge counts what nobody has
        // looked at, and it must not survive someone looking.
        $this->room->markRead($user);

        return Inertia::render('crm/Chat', $page);
    }

    /**
     * What has been said since message $after.
     *
     * Two operators look at this lens at the same time — that is the whole
     * point of it — so the page keeps itself current instead of waiting for
     * someone to press reload.
     */
    public function since(Request $request): JsonResponse
    {
        $user = $request->user();
        $after = $request->integer('after');
        $payload = $this->room->since($user, $after);

        $this->room->markRead($user);

        return response()->json($payload);
    }

    /**
     * Say something, with or without files attached.
     *
     * `#name` in the text attaches the person it names (the same grammar the
     * task composer uses), and `@lainos` marks the line as a call. Neither is
     * stripped from the body: the room shows what was typed.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.(int) config('crm.chat.max_chars', 8000)],
            'files' => ['nullable', 'array', 'max:'.(int) config('crm.chat.files.max_per_message', 5)],
            'files.*' => [
                'file',
                'max:'.((int) config('crm.chat.files.max_mb', 25) * 1024),
                fn (string $attribute, mixed $value, callable $fail) => $this->refuseExecutable($value, $fail),
            ],
        ]);

        $body = trim((string) ($data['body'] ?? ''));
        $files = $request->file('files', []);

        if ($body === '' && $files === []) {
            return back()->withErrors(['body' => 'Nothing to send.']);
        }

        $calls = CrmChatMessage::mentionsLainos($body);
        $available = $this->lainos->enabled();

        $message = CrmChatMessage::create([
            'user_id' => $request->user()->getKey(),
            'author' => CrmChatMessage::AUTHOR_OPERATOR,
            'body' => $body === '' ? null : $body,
            'calls_lainos' => $calls,
            'lainos_state' => match (true) {
                ! $calls => null,
                $available => CrmChatMessage::LAINOS_AWAITING,
                default => CrmChatMessage::LAINOS_FAILED,
            },
            'lainos_note' => $calls && ! $available ? 'disabled' : null,
            'crm_contact_id' => TaskLine::parse($body)['crm_contact_id'],
        ]);

        foreach ($files as $file) {
            $this->attach($message, $file, $request->user()->getKey());
        }

        return back();
    }

    /**
     * Run one call to LainOS and write the answer into the room.
     *
     * Deliberately a request of its own rather than a background job: this
     * server's queue has no worker guaranteed to be running, and an answer
     * that silently never arrives is worse than one an operator can retry.
     * The lock is what keeps two open tabs from asking the same question
     * twice.
     */
    public function answer(Request $request, CrmChatMessage $message): JsonResponse
    {
        abort_unless($message->calls_lainos, 404);

        if ($message->lainos_state === CrmChatMessage::LAINOS_ANSWERED) {
            return response()->json(['state' => CrmChatMessage::LAINOS_ANSWERED]);
        }

        $lock = Cache::lock("crm-chat:answer:{$message->id}", 180);

        if (! $lock->get()) {
            return response()->json(['state' => 'running'], 409);
        }

        try {
            $reply = $this->lainos->answer($message->load('files'));

            if ($reply === null) {
                $message->update([
                    'lainos_state' => CrmChatMessage::LAINOS_FAILED,
                    'lainos_note' => $this->lainos->status()['backend'] === null ? 'disabled' : 'unreachable',
                ]);

                return response()->json([
                    'state' => CrmChatMessage::LAINOS_FAILED,
                    'note' => $message->fresh()?->lainos_note,
                ]);
            }

            CrmChatMessage::create([
                'user_id' => null,
                'author' => CrmChatMessage::AUTHOR_LAINOS,
                'body' => $reply['text'],
                'meta' => $reply['meta'],
                'crm_contact_id' => $message->crm_contact_id,
            ]);

            $message->update([
                'lainos_state' => CrmChatMessage::LAINOS_ANSWERED,
                'lainos_note' => null,
            ]);

            return response()->json(['state' => CrmChatMessage::LAINOS_ANSWERED]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Turn a line into a task.
     *
     * The one action on a message, because a room is not a place to pin
     * things in: a pinned message is one nobody does, and a task with an
     * owner and a date is one somebody does. The line keeps the task's
     * number, so the evening view can say what the day produced.
     */
    public function task(Request $request, CrmChatMessage $message): RedirectResponse
    {
        if ($message->crm_task_id !== null) {
            return back();
        }

        $line = trim((string) $message->body);

        if ($line === '') {
            $line = 'Файл из чата: '.($message->files()->value('name') ?? '—');
        }

        $parsed = TaskLine::parse($line);

        $task = CrmTask::create([
            'title' => Str::limit($parsed['title'], 250),
            'description' => mb_strlen($line) > 250 ? $line : null,
            'status' => 'open',
            'priority' => 'normal',
            'assigned_to_user_id' => $parsed['assigned_to_user_id'],
            'due_at' => $parsed['due_at'],
            'crm_contact_id' => $parsed['crm_contact_id'] ?? $message->crm_contact_id,
            'created_by_user_id' => $request->user()?->getKey(),
        ]);

        $message->update(['crm_task_id' => $task->id]);

        ConsoleFeed::forget();

        return back();
    }

    /**
     * Take a line back.
     *
     * Your own, and an answer from LainOS — that one belongs to nobody, and a
     * room where the noise can only be removed by the participant that has no
     * hands is a room that accumulates it. A line takes its files with it:
     * "ask an admin" is not a removal path in a room of three people.
     */
    public function destroy(Request $request, CrmChatMessage $message): RedirectResponse
    {
        abort_unless(
            $message->isFromLainos()
                || ($message->user_id !== null && $message->user_id === $request->user()?->getKey()),
            403,
        );

        foreach ($message->files as $file) {
            Storage::disk('local')->delete($file->path);
        }

        $message->delete();

        return back();
    }

    /** The same room, read as the pile of files it collected. */
    public function files(Request $request): Response
    {
        return Inertia::render(
            'crm/ChatFiles',
            $this->room->files((string) $request->query('segment', 'all')),
        );
    }

    /**
     * Hand one file back.
     *
     * The only way to read a file from this room, and it is inside the
     * console's own gate — the private disk has no URL of its own.
     */
    public function download(CrmChatFile $file): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        // Always an attachment, never inline: this is content one operator
        // handed the browser of another, and an SVG rendered on this origin
        // would run there. A thumbnail still works — an <img> subresource
        // ignores the disposition and cannot run a script either way.
        return Storage::disk('local')->download($file->path, $file->name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Write one upload to the private disk under a name of our own.
     *
     * The client's filename is kept as a label and never as a path: it is
     * text somebody typed, and the disk is not the place to find out what
     * they typed.
     */
    private function attach(CrmChatMessage $message, UploadedFile $file, int $userId): void
    {
        $path = $file->store('crm-chat/'.now()->format('Y/m'), 'local');

        if (! is_string($path)) {
            return;
        }

        $name = Str::limit(
            (string) preg_replace('/[\x00-\x1f\/\\\\]+/u', '', $file->getClientOriginalName()),
            180,
            '',
        );
        $name = $name === '' ? 'файл' : $name;

        $message->files()->create([
            'user_id' => $userId,
            'path' => $path,
            'name' => $name,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
            'kind' => CrmChatFile::kindFor($name),
        ]);
    }

    /**
     * Refuse anything runnable.
     *
     * Not an antivirus: a shared room where one drag leaves an executable on
     * the server is a room with a threat model nobody chose.
     */
    private function refuseExecutable(mixed $value, callable $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $extension = Str::lower(Str::afterLast($value->getClientOriginalName(), '.'));

        if (in_array($extension, (array) config('crm.chat.files.blocked_extensions', []), true)) {
            $fail('Executable files are not accepted in the room.');
        }
    }
}
