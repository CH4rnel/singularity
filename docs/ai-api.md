# Cyberia inference API

OpenAI-compatible inference at `https://cyberia.church/api/ai/v1`, in front of
the provider accounts that already live on the Cyberia host. There is no
signup: a holder may self-issue a key tied to its position, an operator may
issue a quota-limited free grant to an installed LainOS instance, and a caller
who holds nothing may simply pay per call over x402.

Implementation: `backend/laravel/config/ai.php`, `app/Services/Ai/`,
`app/Http/Controllers/Api/Ai/`, `app/Http/Middleware/AuthenticateAiApiKey.php`,
tests in `tests/Feature/Ai/AiApiTest.php`. The paid door is
`config/x402.php`, `app/Services/X402/`, `app/Http/Middleware/X402Paywall.php`
and `tests/Feature/Ai/AiX402Test.php`.

## Getting a key

There are two issuance paths. For a free user-installed LainOS grant, an
operator opens `/crm/api-keys`, enters the owner's EVM address and an optional
instance label, then presses **Create API key**. The page creates the instance
UUID and shows this setup exactly once:

```dotenv
LAINOS_MODEL_PROVIDER=cyberia
CYBERIA_AI_KEY=sk-cyb-…
```

That grant is `client=lainos` and skips the holding gate, but keeps the normal
per-minute and per-day quotas. Only its SHA-256 and visible prefix remain after
the response.

The second path is self-service access for a `$LAIN` holder:

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

A holder-authorised LainOS may instead send its stable installation UUID in
the signed self-service request:

```json
{"client":"lainos","instance_id":"9cc28b0c-20a5-4d67-9faa-5a72507b2192"}
```

That grant remains subject to the holding gate. In both paths the token becomes
LainOS's Bearer credential for `https://cyberia.church/api/ai/v1`.

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

Most other configured providers get one `lain-<provider>` entry using the
reference project's current example model. Override that upstream id with
`AI_MODEL_<PROVIDER>` (for example `AI_MODEL_OPENAI`); OpenAI, Azure and local
providers deliberately have no guessed default because their model/deployment
id belongs to the operator. More allowlisted entries can be added without code
changes:

```dotenv
AI_EXTRA_MODELS_JSON='[{"id":"lain-coder","label":"Lain Coder","provider":"deepinfra","upstream":"org/model","context":131072,"fallback":"lain-free"}]'
```

The catalogue is an allowlist, not a passthrough: the account being spent is
Cyberia's, so an unknown model id is a `400`, never a bill. A model whose
provider is not configured on the host disappears from `/v1/models` instead of being
offered and then failing.

When a fallback answers, the response still reports the model that was asked
for and adds `served_by` naming what actually replied.

## Providers

The registry mirrors the 49-provider catalogue in
[`free-claude-code`](https://github.com/Alishahryar1/free-claude-code): NVIDIA
NIM, OpenRouter, Groq, ClinePass, OpenAI, xAI, both QwenCloud plans, Together,
DeepInfra, SiliconFlow, Nebius, Chutes, Featherless, Agnes, ZenMux, W&B, Azure
OpenAI, Gemini, Vertex, DeepSeek, both Mistral endpoints, both OpenCode plans,
Vercel, Bedrock, Hugging Face, Cohere, GitHub Models, Wafer, both Kimi
endpoints, Kilo, MiniMax, Cerebras, SambaNova, Fireworks, Novita, Cloudflare,
both Z.ai endpoints, TokenRouter, NaraRoute, Poolside, Ollama Cloud, LM Studio,
llama.cpp and local Ollama.

They remain dormant until their credential is present. Cloudflare additionally
needs `CLOUDFLARE_ACCOUNT_ID`; Azure needs its complete
`AZURE_OPENAI_BASE_URL`; local providers require an explicit
`AI_ENABLE_*`. Vertex uses `VERTEX_ACCESS_TOKEN`, `VERTEX_PROJECT_ID` and
`VERTEX_LOCATION`; token renewal is the host's responsibility. The OpenAI slot
uses a server API key, not free-claude-code's interactive ChatGPT OAuth flow.

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

Cyberia's own server-side daemons (the hosted LainOS, the Telegram bot) cannot
hold a position, so they get service keys issued through the CLI:

```bash
php artisan ai:key issue 0x… --service --name=lainos
php artisan ai:key list 0x…
php artisan ai:key revoke 12
```

A service key skips the gate and nothing else.

## Paying instead of holding (x402)

The gate asks for a position. [x402](https://docs.x402.org) asks for a cent,
and asks it of a caller with no account, no key and nothing held — which is
what an autonomous agent arriving at this URL for the first time actually is.

One exchange, entirely in headers:

```bash
# 1. ask without paying
curl -isX POST https://cyberia.church/api/ai/v1/chat/completions \
  -H 'content-type: application/json' \
  -d '{"model":"lain-fast","messages":[{"role":"user","content":"hi"}]}'
# -> HTTP/1.1 402 Payment Required
# -> PAYMENT-REQUIRED: <base64 of {"x402Version":2,"accepts":[…]}>

# 2. sign an authorization for exactly those terms and repeat the request
#    with `PAYMENT-SIGNATURE: <base64 payload>`. Any x402 client does this
#    for you — x402-fetch, x402-axios, the Python and Go middlewares.
# -> HTTP/1.1 200 OK
# -> PAYMENT-RESPONSE: <base64 of {"success":true,"transaction":"0x…"}>
```

Default terms: `exact` on Base (`eip155:8453`), native USDC, one cent a call,
`X402_AI_PRICE` to change it and `x402.ai.models` to price individual models
apart. The same terms are published unpaid at `GET /api/ai/v1` under
`payment`, so an agent can decide what this costs before spending a request
finding out.

This server holds no wallet and signs nothing. Verification and settlement are
a **facilitator's** job — it is the party that pays gas and broadcasts — so
accepting payment costs this host no key, no RPC and no nonce. The facilitator
is chosen in the environment, and the pairing is checked before a caller has
to discover it is wrong:

```bash
php artisan x402:check
```

That command refuses a payee that is unset, a price that rounds to zero atomic
units, and — the failure worth naming — a facilitator that does not serve the
network being quoted. The default `https://x402.org/facilitator` serves Base
**Sepolia** only; pointing it at mainnet terms is a paywall that quotes and
never collects. Mainnet means Coinbase's CDP facilitator (credentials, and a
fee per settlement above a free tier) or one of the free third-party ones.

Order is the design. Verification is free and happens before the work, so a bad
authorization costs nothing upstream; settlement happens once the answer exists
but before a byte of it is sent — a stream has already pulled its first chunk
by then — so nothing is charged for a request that failed, and no answer is
given away for a payment that did not settle. The requirements handed to the
facilitator are always rebuilt from this server's own configuration, never read
back from the payload, because a payer's copy of the terms is a payer's
opinion.

Prices are flat per call, because `exact` charges before the token count
exists. Metering an inference call to its actual usage is what x402's `upto`
scheme is for, and it is deliberately not implemented yet.

Two doors, one URL. A request carrying a key uses the key door and never
touches a facilitator; a request carrying a payment skips the key and the
holding entirely. A paid call is metered like any other, and names the payment
that bought it instead of the credential that was presented
(`x402_payments`, `ai_api_requests.x402_payment_id`). An unsettled payment row
is the record of money promised and never collected.

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

A paying caller answers to neither — they are paying — but to one burst limit
per payer address (`X402_REQUESTS_PER_MINUTE`, 60), so a single payer cannot
hold the upstream open between settlements.

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

One metering row per call: the key id **or** the payment id, model, provider,
token counts, status, whether it streamed. No prompt, no completion, not even
their lengths — the table has no column one could go in. Rows are dropped after
`AI_USAGE_RETENTION_DAYS` (90) by the daily `ai:prune-usage` command.

A settled payment keeps its own receipt in `x402_payments`: payer address,
network, asset, atomic amount, transaction hash, and the resource path it
bought. That is the whole identity of a paying caller — no account, no session,
no key — and it is also the limit of what the row may ever say about a person.

Providers see the prompts, as they must to answer them. Their retention is
theirs, not Cyberia's.

## Operating it

Set one or more credentials from `backend/laravel/.env.example` in the prod
`.env`; `OPENROUTER_API_KEY` remains shared with "Talk to Lain". Then:

```bash
php artisan migrate
php artisan config:cache   # prod config IS cached
php artisan ai:providers --probe
php artisan x402:check     # only if the paid door is switched on
```

`ai:providers` lists the providers that hold a key and the models each one
serves; `--probe` spends one two-token completion per model to prove the key
in the environment is accepted upstream. It never prints a key, so it is the
safe way to answer which upstreams are actually wired up on this host.

Dropping a provider's key from the environment is a supported way to take its
models off the menu: the catalogue shrinks, `lain-free` remains as long as
OpenRouter has a key, and nothing 500s.
