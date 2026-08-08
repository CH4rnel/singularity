<?php

namespace App\Http\Controllers;

use App\Exceptions\OpenRouterException;
use App\Models\LainChatMessage;
use App\Models\LainChatSession;
use App\Models\User;
use App\Services\LainChatService;
use App\Services\LainHolderAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class LainChatController extends Controller
{
    public function __construct(
        private LainChatService $lain,
        private LainHolderAccessService $holderAccess,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $sessions = $user === null
            ? collect()
            : $user->lainChatSessions()
                ->latest('updated_at')
                ->latest('id')
                ->get();
        $active = $sessions->first();

        return Inertia::render('LainChat', [
            'enabled' => $this->lain->enabled(),
            'gate' => $this->gateFor($user),
            'sessions' => $sessions->map(fn (LainChatSession $s) => $this->sessionPayload($s))->values(),
            'activeSessionId' => $active?->id,
            'messages' => $active === null ? [] : $this->messagesPayload($active),
        ]);
    }

    /** Messages of one past session, for switching in the sidebar. */
    public function session(Request $request, LainChatSession $session): JsonResponse
    {
        abort_unless($session->user_id === $request->user()->id, 404);

        return response()->json([
            'session' => $this->sessionPayload($session),
            'messages' => $this->messagesPayload($session),
        ]);
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:'.LainChatService::MAX_MESSAGE_CHARS],
            'session_id' => ['nullable', 'integer'],
        ]);

        if (! $this->lain->enabled()) {
            return response()->json(['message' => 'Lain is not wired up on this server yet.'], 503);
        }

        $user = $request->user();
        $session = null;

        if (isset($data['session_id'])) {
            $session = $user->lainChatSessions()->find($data['session_id']);

            if ($session === null) {
                return response()->json(['message' => 'Unknown session.'], 404);
            }
        }

        $gate = $this->gateFor($user);

        if (! $gate['qualifies']) {
            if ($gate['state'] === 'error') {
                return response()->json([
                    'message' => 'Could not verify the LAIN balance on Cyberia. Try again shortly.',
                    'gate' => $gate,
                ], 503);
            }

            return response()->json([
                'message' => 'This wallet holds less than the required share of the LAIN supply.',
                'gate' => $gate,
            ], 403);
        }

        $text = trim($data['text']);

        try {
            $reply = $this->lain->reply($user, $session, $text);
        } catch (OpenRouterException $exception) {
            Log::warning('Lain chat request failed', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $exception->category === 'policy_denied'
                    ? 'Lain’s model provider rejected this message. Try rephrasing it.'
                    : 'Lain is unreachable right now. Try again in a moment.',
            ], 503);
        } catch (Throwable $e) {
            Log::warning('Lain chat request failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Lain is unreachable right now. Try again in a moment.',
            ], 503);
        }

        // The session row and both turns are persisted only for answered
        // turns, so a failed send leaves nothing behind and can be retried.
        $session ??= $user->lainChatSessions()->create([
            'title' => Str::limit($text, 48),
        ]);
        $session->messages()->create([
            'user_id' => $user->id,
            'role' => LainChatMessage::ROLE_USER,
            'content' => $text,
        ]);
        $message = $session->messages()->create([
            'user_id' => $user->id,
            'role' => LainChatMessage::ROLE_LAIN,
            'content' => $reply['text'],
            'model' => $reply['model'],
        ]);

        return response()->json([
            'id' => $message->id,
            'text' => $reply['text'],
            'session' => $this->sessionPayload($session->refresh()),
        ]);
    }

    /** @return array{id: int, title: string, updatedAt: string} */
    private function sessionPayload(LainChatSession $session): array
    {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'updatedAt' => $session->updated_at->toIso8601String(),
        ];
    }

    /** @return list<array{id: int, role: string, text: string}> */
    private function messagesPayload(LainChatSession $session): array
    {
        return $session->messages()
            ->orderBy('id')
            ->get()
            ->map(fn (LainChatMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'text' => $m->content,
            ])
            ->values()
            ->all();
    }

    /**
     * Holder-gate status for the page and the chat endpoint. States: guest
     * (not signed in), no_wallet (no EVM wallet on the account), error (RPC
     * unreachable), checked (balance read; see qualifies). The gate locks
     * sending only — users can always read their own transcripts.
     *
     * @return array{state: string, qualifies: bool, tokenAddress: string, minimumShareBps: int, balance?: string, minimumBalance?: string, shareBps?: int}
     */
    private function gateFor(?User $user): array
    {
        $base = [
            'qualifies' => false,
            'tokenAddress' => (string) config('services.lain.token_address'),
            'minimumShareBps' => (int) config('services.lain.minimum_share_bps', 1000),
        ];

        if ($user === null) {
            return ['state' => 'guest'] + $base;
        }

        if (! $user->wallet_address) {
            return ['state' => 'no_wallet'] + $base;
        }

        try {
            $status = $this->holderAccess->status($user->wallet_address);
        } catch (Throwable $e) {
            Log::warning('LAIN holder check failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return ['state' => 'error'] + $base;
        }

        return [
            'state' => 'checked',
            'balance' => $status['balance'],
            'minimumBalance' => $status['minimum_balance'],
            'shareBps' => $status['share_bps'],
        ] + ['qualifies' => $status['qualifies']] + $base;
    }
}
