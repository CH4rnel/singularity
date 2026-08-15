<?php

namespace App\Http\Controllers\Api\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiHolderGate;
use App\Services\Ai\AiModelCatalog;
use Illuminate\Http\JsonResponse;

/**
 * The catalogue, and the terms for reaching it.
 *
 * Both are public. What models exist and what a key costs in held supply are
 * exactly the facts someone needs *before* they hold anything — putting them
 * behind the gate would mean you must qualify to find out what qualifying is
 * for.
 */
class ModelsController extends Controller
{
    public function __construct(
        private AiModelCatalog $catalog,
        private AiHolderGate $gate,
    ) {}

    /** GET /api/ai/v1/models — OpenAI's list shape, with our ids. */
    public function index(): JsonResponse
    {
        return response()->json([
            'object' => 'list',
            'data' => array_map(fn (array $model): array => [
                'id' => $model['id'],
                'object' => 'model',
                'owned_by' => $model['provider'],
                'name' => $model['label'],
                'context_window' => $model['context'],
                'fallback' => $model['fallback'],
            ], $this->catalog->models()),
        ]);
    }

    /**
     * GET /api/ai/v1 — what this endpoint is, in one response.
     *
     * Enough for a person with curl to get from "what is this" to a working
     * request without another page: the base URL to point a client at, the
     * gate, the limits, and where a key comes from.
     */
    public function root(): JsonResponse
    {
        return response()->json([
            'object' => 'api',
            'name' => (string) config('ai.name', 'cyberia'),
            'description' => 'OpenAI-compatible inference for holders of the Cyberia gate token.',
            'base_url' => url('/api/ai/v1'),
            'endpoints' => [
                'chat_completions' => url('/api/ai/v1/chat/completions'),
                'models' => url('/api/ai/v1/models'),
                'key_status' => url('/api/ai/v1/me'),
                'issue_key' => url('/api/ai/keys'),
            ],
            'gate' => $this->gate->terms(),
            'limits' => [
                'requests_per_minute' => (int) config('ai.limits.requests_per_minute'),
                'requests_per_day' => (int) config('ai.limits.requests_per_day'),
                'max_output_tokens' => (int) config('ai.limits.max_output_tokens'),
                'max_input_chars' => (int) config('ai.limits.max_input_chars'),
                'keys_per_address' => (int) config('ai.limits.keys_per_address'),
            ],
            'models' => array_column($this->catalog->models(), 'id'),
        ]);
    }
}
