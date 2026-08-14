<?php

namespace App\Http\Controllers\Api\Ai;

use App\Exceptions\AiApiException;
use App\Http\Controllers\Controller;
use App\Models\AiApiKey;
use App\Services\Ai\AiChatPayload;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiModelCatalog;
use App\Services\Ai\AiUsageMeter;
use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * POST /api/ai/v1/chat/completions — the endpoint the whole thing exists for.
 *
 * OpenAI-compatible in both directions: the body is theirs, the response is
 * theirs, and `stream: true` produces the same `data:` frames ending in
 * `[DONE]`. An existing client only has to change its base URL and its key.
 *
 * Nothing about the conversation is stored. What is written down is one row of
 * metering per call (model, provider, token counts, outcome) — see
 * AiApiRequest, which has no column a prompt could go in.
 */
class ChatCompletionsController extends Controller
{
    public function __construct(
        private AiModelCatalog $catalog,
        private AiGateway $gateway,
        private AiUsageMeter $meter,
    ) {}

    public function store(Request $request): JsonResponse|StreamedResponse
    {
        $input = $request->json()->all();

        if (! is_array($input) || $input === []) {
            throw AiApiException::invalidRequest('The request body must be a JSON object.');
        }

        $model = $this->catalog->resolve(is_string($input['model'] ?? null) ? $input['model'] : null);
        $payload = AiChatPayload::fromRequest($input);
        $key = $this->key($request);

        if ($payload->stream) {
            return $this->streamed($key, $model, $payload);
        }

        $result = $this->gateway->complete($model, $payload);

        $this->meter->record($key, [
            'model' => $model['id'],
            'served_model' => $result['served'],
            'provider' => $result['provider'],
            'status' => 200,
            'streamed' => false,
            'usage' => $result['body']['usage'] ?? null,
        ]);

        return response()->json($result['body']);
    }

    /**
     * The streamed form.
     *
     * The generator is advanced to its first chunk *before* the response is
     * returned, so a failure that happens before any output — an unreachable
     * provider, a rejected prompt — is still an HTTP error with a status the
     * client can read, instead of a 200 whose body says otherwise.
     *
     * @param  array{id: string, provider: string, upstream: string, fallback: ?string}  $model
     */
    private function streamed(AiApiKey $key, array $model, AiChatPayload $payload): StreamedResponse
    {
        $chunks = $this->gateway->stream($model, $payload);
        $chunks->rewind();

        return response()->stream(
            function () use ($chunks, $key, $model): void {
                $usage = null;
                $served = $model['id'];
                $provider = $model['provider'];
                $status = 200;

                try {
                    foreach ($this->drain($chunks) as $chunk) {
                        // Usage arrives in the last frame, and only when the
                        // caller asked for it (`stream_options`).
                        $usage = is_array($chunk['usage'] ?? null) ? $chunk['usage'] : $usage;
                        $served = is_string($chunk['served_by'] ?? null) ? $chunk['served_by'] : $served;
                        $provider = is_string($chunk['provider'] ?? null) ? $chunk['provider'] : $provider;

                        $this->send(json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    }
                } catch (AiApiException $e) {
                    $status = $e->status;

                    // Mid-stream there is no status line left to change, so the
                    // error is delivered as a frame — the same shape the body
                    // of a failed request would have had.
                    $this->send(json_encode([
                        'error' => [
                            'message' => $e->getMessage(),
                            'type' => $e->type,
                            'code' => $e->errorCode,
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }

                $this->send('[DONE]');

                $this->meter->record($key, [
                    'model' => $model['id'],
                    'served_model' => $served,
                    'provider' => $provider,
                    'status' => $status,
                    'streamed' => true,
                    'usage' => $usage,
                ]);
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                // nginx buffers proxied responses by default, which would hold
                // a stream until it ended — the one thing a stream must not do.
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * The generator's chunks, including the one already fetched by rewind().
     *
     * @param  Generator<int, array<string, mixed>>  $chunks
     * @return Generator<int, array<string, mixed>>
     */
    private function drain(Generator $chunks): Generator
    {
        while ($chunks->valid()) {
            yield $chunks->current();

            $chunks->next();
        }
    }

    private function send(string $data): void
    {
        echo 'data: '.$data."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    private function key(Request $request): AiApiKey
    {
        $key = $request->attributes->get('ai_api_key');

        if (! $key instanceof AiApiKey) {
            throw AiApiException::unauthorized('This endpoint requires an API key.');
        }

        return $key;
    }
}
