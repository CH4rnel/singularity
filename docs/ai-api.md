# Cyberia inference API

OpenAI-compatible inference at `https://cyberia.church/api/ai/v1`, in front of
the provider accounts that already live on the Cyberia host. There is no
signup: a key is issued to an EVM address that holds its share of the gate
token, and it keeps working for exactly as long as that holding does.

Implementation: `backend/laravel/config/ai.php`, `app/Services/Ai/`,
`app/Http/Controllers/Api/Ai/`, `app/Http/Middleware/AuthenticateAiApiKey.php`,
tests in `tests/Feature/Ai/AiApiTest.php`.

## Getting a key

Three calls, all unauthenticated, all signed with the wallet that holds the
token. The signature is EIP-191 (`personal_sign`) over the exact message the
server returns — its wording differs from the login and holders'-room
challenges, so a signature made here is worth nothing at either.

```bash
# 1. ask for a challenge
curl -sX POST https://cyberia.church/api/ai/keys/nonce \
  -H 'content-type: application/json' \
  -d '{"address":"0xYOURADDRESS"}'
# -> {"message":"Cyberia AI API — manage the inference keys…","expires_in":300}

# 2. sign `message` with that address, then:
curl -sX POST https://cyberia.church/api/ai/keys \
  -H 'content-type: application/json' \
  -d '{"address":"0xYOURADDRESS","signature":"0x…","name":"laptop"}'
# -> {"key":"sk-cyb-…","record":{…},"gate":{…}}
```

The key is shown once. Only its SHA-256 is stored, so a lost key is replaced,
never recovered.

Two more, same signature flow, no holding required — someone who sold out must
still be able to see and kill what they left behind:

- `POST /api/ai/keys/list` → `{address, signature}`
- `POST /api/ai/keys/revoke` → `{address, signature, id}`

Each challenge answers exactly once; ask for a new nonce per call.

## Calling it

```bash
curl -sX POST https://cyberia.church/api/ai/v1/chat/completions \
  -H "authorization: Bearer $CYBERIA_AI_KEY" \
  -H 'content-type: application/json' \
  -d '{
    "model": "lain-fast",
    "messages": [{"role":"user","content":"what is the wired?"}]
  }'
```

Any OpenAI client works unchanged — point its base URL at
`https://cyberia.church/api/ai/v1` and give it the `sk-cyb-…` key:

```python
from openai import OpenAI

client = OpenAI(base_url="https://cyberia.church/api/ai/v1",
                api_key=os.environ["CYBERIA_AI_KEY"])
client.chat.completions.create(model="lain-fast", messages=[...])
```

`stream: true` returns the usual `data:` frames ending in `data: [DONE]`.
Tool calls (`tools`, `tool_choice`), `response_format`, `seed`, `stop` and the
sampling parameters are forwarded to the provider untouched; fields this server
does not understand are dropped rather than passed on.

### Endpoints

| Method | Path | Auth | What it does |
|---|---|---|---|
| `GET` | `/api/ai/v1` | — | What this API is: gate, limits, model ids |
| `GET` | `/api/ai/v1/models` | — | The catalogue, in OpenAI's list shape |
| `POST` | `/api/ai/v1/chat/completions` | key | The completion |
| `GET` | `/api/ai/v1/me` | key | This key, its gate reading, today's usage |
| `POST` | `/api/ai/keys*` | signature | Issue, list, revoke |

## Models

Ids are Cyberia's, not the providers'. An upstream model can be repointed
without breaking a client that pinned one, and each entry names the model it
falls back to when its provider rate-limits, times out or drops it:

| id | upstream | provider |
|---|---|---|
| `lain-fast` | `llama-3.1-8b-instant` | Groq |
| `lain-large` | `llama-3.3-70b-versatile` | Groq |
| `lain-reason` | `openai/gpt-oss-120b` | Groq |
| `lain-reason-mini` | `openai/gpt-oss-20b` | Groq |
| `lain-free` | OpenRouter's free router | OpenRouter |

The catalogue is an allowlist, not a passthrough: the account being spent is
Cyberia's, so an unknown model id is a `400`, never a bill. A model whose
provider has no key on the host disappears from `/v1/models` instead of being
offered and then failing.

When a fallback answers, the response still reports the model that was asked
for and adds `served_by` naming what actually replied.

## The gate

An address qualifies by holding at least `AI_GATE_MINIMUM_SHARE_BPS` of the
live supply of `AI_GATE_TOKEN_ADDRESS` on Cyberia — $LAIN at 0.5% by default,
deliberately far below the 10% that opens the holders' room in the wallet.

The holding is re-read on every request (cached ~60s), so the key is a pointer
to a position rather than a permanent grant: sell it and the API closes with a
`403 insufficient_holding`, buy back in and it opens again, with nothing to
revoke or reissue either way. The gate fails closed — if the Cyberia RPC cannot
be read the answer is `503 gate_unreadable`, because an unreadable balance is
not a passing balance.

Cyberia's own daemons (LainOS, the Telegram bot) cannot hold a position, so
they get service keys issued at the console:

```bash
php artisan ai:key issue 0x… --service --name=lainos
php artisan ai:key list 0x…
php artisan ai:key revoke 12
```

A service key skips the gate and nothing else.

## Limits

Per key, not per address — issuing more keys buys more of neither:

| Limit | Default | Env |
|---|---|---|
| Requests per minute | 20 | `AI_LIMIT_RPM` |
| Requests per day | 2000 | `AI_LIMIT_RPD` |
| Output tokens | 4096 (clamped, not refused) | `AI_LIMIT_MAX_OUTPUT_TOKENS` |
| Input characters | 120000 | `AI_LIMIT_MAX_INPUT_CHARS` |
| Keys per address | 5 | `AI_LIMIT_KEYS_PER_ADDRESS` |

The minute window lives in the rate limiter; the day is counted from the usage
log, which a cache flush cannot reset.

## Errors

OpenAI's envelope, so a client pointed at this host never parses two shapes:

```json
{"error":{"message":"…","type":"permission_error","code":"insufficient_holding","param":null}}
```

| Status | `type` | Meaning |
|---|---|---|
| 400 | `invalid_request_error` | The body, the model id or the message list |
| 401 | `authentication_error` | Missing, unknown or revoked key |
| 403 | `permission_error` | The holding behind the key is gone |
| 429 | `rate_limit_error` | Per-minute or daily quota (see `Retry-After`) |
| 502/503/504 | `api_error` | The provider, or the gate's view of the chain |

An upstream 401 surfaces as a `502`, never as "your key is invalid" — the key
that was rejected is this server's, not the caller's.

## What is stored

One metering row per call: key id, model, provider, token counts, status,
whether it streamed. No prompt, no completion, not even their lengths — the
table has no column one could go in. Rows are dropped after
`AI_USAGE_RETENTION_DAYS` (90) by the daily `ai:prune-usage` command.

Providers see the prompts, as they must to answer them. Their retention is
theirs, not Cyberia's.

## Operating it

`GROQ_API_KEY` is the only new secret; `OPENROUTER_API_KEY` is the one the
"Talk to Lain" chat already uses, and this API shares that account rather than
opening a second one. Set them in the prod `.env`, then:

```bash
php artisan migrate
php artisan config:cache   # prod config IS cached
php artisan ai:providers --probe
```

`ai:providers` lists the providers that hold a key and the models each one
serves; `--probe` spends one two-token completion per model to prove the key
in the environment is accepted upstream. It never prints a key, so it is the
safe way to answer "is Groq actually wired up on this host".

Dropping a provider's key from the environment is a supported way to take its
models off the menu: the catalogue shrinks, `lain-free` remains as long as
OpenRouter has a key, and nothing 500s.
