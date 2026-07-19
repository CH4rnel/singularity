<?php

namespace App\Http\Controllers;

use App\Models\LainChatMessage;
use App\Services\LainChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class LainChatController extends Controller
{
    public function __construct(private LainChatService $lain) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('LainChat', [
            'enabled' => $this->lain->enabled(),
            'messages' => $user === null ? [] : LainChatMessage::currentConversation($user->id)
                ->where('role', '!=', LainChatMessage::ROLE_RESET)
                ->get()
                ->map(fn (LainChatMessage $m) => [
                    'id' => $m->id,
                    'role' => $m->role,
                    'text' => $m->content,
                ])
                ->values(),
        ]);
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
        ]);

        if (! $this->lain->enabled()) {
            return response()->json(['message' => 'Lain is not wired up on this server yet.'], 503);
        }

        $user = $request->user();
        $text = trim($data['text']);

        try {
            $reply = $this->lain->reply($user, $text);
        } catch (Throwable $e) {
            Log::warning('Lain chat request failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Lain is unreachable right now. Try again in a moment.',
            ], 503);
        }

        // Persist only turns that actually happened: the user line is not
        // stored on model failure, so a retry doesn't duplicate it.
        $user->lainChatMessages()->create([
            'role' => LainChatMessage::ROLE_USER,
            'content' => $text,
        ]);
        $message = $user->lainChatMessages()->create([
            'role' => LainChatMessage::ROLE_LAIN,
            'content' => $reply['text'],
            'model' => $reply['model'],
        ]);

        return response()->json([
            'id' => $message->id,
            'text' => $reply['text'],
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $user = $request->user();

        // A boundary row instead of deletion: the transcript stays analyzable,
        // the model context starts fresh.
        if (LainChatMessage::currentConversation($user->id)->exists()) {
            $user->lainChatMessages()->create([
                'role' => LainChatMessage::ROLE_RESET,
                'content' => '',
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
