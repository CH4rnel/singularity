# Buying crypto with a card

The wallet's other screens all assume coins already exist. This is the one that
answers a person holding a bank card and nothing else — and it is also where
the awkward half of that question lives, which is Мир.

Two sentences summarise the whole design. **We are not the seller**: a provider
takes the card, runs its own identity check and settles the crypto to an
address this wallet derived, so no licence, no merchant of record, no PCI scope
and no chargeback liability lands on this host. And **a purchase does not
arrive on Cyberia**: no on-ramp settles to chain 49406, so it lands on a chain
they do serve and the wallet's own cross-chain swap is the second step.

Code: `config/onramp.php`, `App\Services\Onramp\*`, `Api\WalletOnrampController`,
`resources/js/lib/wallet/onramp.ts`, `components/wallet/WalletBuy.vue`, table
`onramp_orders`. Tests: `tests/Feature/WalletOnrampTest.php`,
`tests/Frontend/WalletOnrampTest.mjs`.

## 1. What was looked at, and what it costs

Four providers are in the registry. All four are the same *kind* of
arrangement — a hosted checkout on their origin, a partner key on ours, a
revenue share configured in their dashboard — and the differences that made
each one worth a row are noted below.

| Provider | Card fee (typical) | Bank transfer | Coverage | Why it is in the list |
|---|---|---|---|---|
| **Transak** | 0.99–5% by method and country | SEPA ≈ 0.99% | 160+ countries, 49 US states | Local rails nothing else has: UPI (India), PIX (Brazil), SEPA Instant. Plainest quote API of the four, and a webhook signed as a JWT with the partner secret. |
| **MoonPay** | ≈ 4% card, ≈ 2% ACH/SEPA | yes | ≈ 160 countries, 50 US states | The widest card acceptance, PayPal, Apple/Google Pay. Requires the handoff URL to be **signed** — see §4. |
| **Ramp Network** | ≈ 2.9% card | 0.49–0.99%, free SEPA/open banking | 150+ countries, 50 fiat | Cheapest way in for somebody using a bank transfer rather than a card. Webhooks are ECDSA-signed; we do not verify them, so its orders are not tracked and the screen says so. |
| **Onramper** | no added fee — it routes to the others | inherits | 170+ geographies | An aggregator: one key in front of ~30 on-ramps, including ones we will never have an account with. Kept *inside* the registry rather than instead of it, because an aggregator is a single point of failure and a second set of terms on top of the first. |

Banxa (1.99% card, free SEPA/Interac/iDEAL, strong in AU/CA) is the obvious
fifth and is not wired up only because it needs an account; adding it is one
class plus a row in the config.

**What every one of them requires:** a company, a KYB review with that
company's documents, and a partner dashboard where the revenue share is
configured. None of them requires a licence *from us* — the buyer contracts
with the provider, not with Cyberia. A partner fee of up to ~5% can be added on
top of their own, and it is paid out monthly to an address or account named in
that dashboard.

**What none of them does:** settle to Cyberia. Chain support is a compliance
review per chain, and a chain with one bridge and one DEX is not on that list.
So the delivery rows are Base (USDC/ETH), Solana (SOL/USDC) and BNB, all at the
*same address* this wallet derives there.

## 2. Мир — the corridor that is not there

Stated on the screen as a corridor with a reason, exactly like a switched-off
bridge route, because leaving it off reads as an oversight to the people who
hold that card. The facts, so nobody has to rediscover them:

- **The rails end at the border.** Мир is NSPK's own network and authorises
  only where NSPK is wired in — Russia, Belarus, and partially a few
  neighbours. Presented to a processor outside those rails there is no route
  for the authorisation at all; this is not a policy setting anybody can flip.
- **NSPK is sanctioned.** Every provider above is licensed somewhere that
  enforces those sanctions. Providers that tried serving Russian users from
  Europe have been designated for it — three entities under the Mercuryo brand
  were sanctioned by Poland in 2026 for exactly this — and Mercuryo lists the
  Russian Federation among the countries it does not operate in.
- **The EU closed the workaround.** The 20th package (2026) bans EU persons
  from crypto-asset transactions with Russian and Belarusian crypto-asset
  service providers outright, so fronting a Russian processor from an EU-facing
  integration is not available either.
- **Inside Russia the direction itself is prohibited.** Since 1 September 2026
  a Russian legal entity or tax resident may not accept digital currency as
  consideration for goods, works or services. Acquirers refuse the MCC
  regardless; crypto exchange is not a business a Russian bank writes a
  card-acquiring contract for.

What is left is a grey segment — processors routing Мир through card-to-card
and QR rails with no merchant contract, at 7%+, where account freezes and
chargebacks are the normal case and the people whose cards are used now carry
criminal exposure under the 2026 "dropper" rules. **This project does not put
one of those behind a button that looks like the other four.**

So the slot is a link, not a driver: `ONRAMP_MIR_URL` and `ONRAMP_MIR_OPERATOR`
point at whatever rail the operator chooses (an exchanger, a P2P desk, a
Telegram flow), and the wallet shows it for what it is — somebody else's
service, somebody else's custody, opened in a new tab. Unset, the screen says
Мир is not served and why.

The honest routes for a Russian buyer today, none of which this app can perform
for them: an exchange's own СБП/QR top-up followed by P2P, an exchanger listed
on an aggregator, or somebody they already trust. The wallet's job is to be
ready to *receive*, which it is on every chain listed above.

## 3. The shape of the integration

```
config/onramp.php          the registry: one row per provider, one per delivery pair
OnrampRequest              one purchase, as four dialects are asked about it
OnrampProvider             what every provider must answer (base class)
  ├── TransakProvider      quote · widget URL · JWT webhook
  ├── MoonPayProvider      quote · SIGNED widget URL · HMAC webhook
  ├── RampProvider         quote (all methods at once) · widget URL · no tracking
  └── OnramperProvider     aggregated quote (best of ~30) · widget URL · no tracking
OnrampRouter               asks everybody, ranks by payout, mints the reference,
                           writes the row, files verified webhooks
```

Five questions, the same for every provider:

1. **`unavailable()`** — can it serve this purchase, answered from config with
   no network call: `provider_off`, `unconfigured`, `country_restricted`,
   `fiat_unsupported`, `method_unsupported`. A refusal keeps its reason,
   because "we have not connected it" and "it refuses your country" are
   different sentences and the person can act on one of them.
2. **`quote()`** — what it costs, live. Ranked on what *arrives*, never on the
   fee line: a low fee on a bad rate is the oldest trick in this business.
3. **`checkout()`** — a URL on the provider's own origin, carrying the
   destination address and our reference, signed where the provider demands it.
4. **`webhook()`** — verified, or the payload does not exist. Statuses are
   normalised to `pending · paid · delivering · delivered · failed · expired`.
5. **`tracking`** — declared in config as `webhook` or `none`. A provider whose
   signature we cannot verify is declared untracked and the screen says the
   coins will simply appear, which is true and is what the person is watching.

Adding a fifth provider is one subclass plus a config row. Nothing above the
base class knows a provider name.

## 4. The three things that are easy to get wrong

**The fee is a disclosure, not an enforcement.** Unlike the cross-chain router
— where Cyberia's cut is a field in the quote request and `CrosschainRouter`
composes it server-side precisely so a browser cannot delete it — an on-ramp
partner fee lives inside the provider's dashboard and is applied by them. So
`declared_bps` is what the operator says they set over there, printed as a
disclosure and clamped so an env typo cannot promise 30%; the authoritative
number is always the fee breakdown inside the quote that came back. **This app
never adds a number of its own to a price.**

**MoonPay refuses an unsigned handoff.** A widget URL carrying `walletAddress`
must be signed with the partner secret (HMAC-SHA256 over the query string,
leading `?` included). That secret cannot live in a bundle, which is most of
the argument for this server-side layer existing at all.

**The reference is ours.** It is minted server-side and travels into the
provider's own partner-order field. A caller-supplied reference would be a
caller-supplied way to attach a stranger's webhook to somebody else's order, so
a `reference` in the request body is ignored. Webhooks answer `202` in every
case — good payload, forged one, or an order nobody here started — because an
endpoint that answers differently is an oracle for guessing references, and
every provider retries anything that is not a 2xx.

## 5. Switching it on

Nothing here works until a provider account exists; until then every route
renders as `unconfigured` with that reason, which is the intended resting
state. Per provider, only the env names (never the values):

```
ONRAMP_ENABLED
ONRAMP_TRANSAK_ENABLED   ONRAMP_TRANSAK_KEY   ONRAMP_TRANSAK_SECRET   ONRAMP_TRANSAK_PARTNER_BPS
ONRAMP_MOONPAY_ENABLED   ONRAMP_MOONPAY_KEY   ONRAMP_MOONPAY_SECRET   ONRAMP_MOONPAY_PARTNER_BPS
ONRAMP_RAMP_ENABLED      ONRAMP_RAMP_KEY      ONRAMP_RAMP_SECRET      ONRAMP_RAMP_PARTNER_BPS
ONRAMP_ONRAMPER_ENABLED  ONRAMP_ONRAMPER_KEY  ONRAMP_ONRAMPER_SECRET  ONRAMP_ONRAMPER_PARTNER_BPS
ONRAMP_MIR_URL           ONRAMP_MIR_OPERATOR
```

Webhook addresses to register in each dashboard:
`https://cyberia.church/api/wallet/onramp/webhook/{transak|moonpay}`.

Order of work: Transak first (widest useful rails, simplest API, verifiable
webhook), Onramper second (coverage for everything we will not integrate),
MoonPay third (card acceptance, needs the signing secret), Ramp when bank
transfers matter.

## 6. Privy

Privy is not an on-ramp. It is embedded-wallet and auth infrastructure — email,
SMS, social and passkey login that produces a wallet for the user — acquired by
Stripe in June 2025 and now sitting beside Stripe's Bridge stablecoin rails. It
does have a funding widget, which routes to Stripe, Meld, MoonPay and Coinbase
and adds no fee of its own; the free tier is 50k signatures and $1M monthly
volume, then $299/mo from 500 MAU and $499/mo from 2,500.

**Its custody model is the reason it is not adopted here.** A Privy wallet is
2-of-3 Shamir shares — device, Privy's TEE, and a recovery share — so the user
cannot reconstruct the key without Privy's infrastructure being alive and
willing. That is a real improvement on a custodial exchange account and a real
regression from what this wallet already is: twelve words generated in the
browser, which derive every chain here, including three that Privy does not
support at all (Monero, Bitcoin, Litecoin). Adopting it would mean a second
kind of wallet with a different recovery story, a per-MAU bill, and a dependency
whose failure mode is "nobody can sign".

**It is also no help with the actual problem.** Every funding provider it routes
to — Stripe, MoonPay, Coinbase, Meld — excludes Russia, so Privy's onramp is
strictly a subset of what the registry above already reaches.

What is worth taking from it, without taking it: **email/social login is a real
gap** in onboarding, and the answer that fits this wallet is a *social login
that unlocks the existing seed vault* rather than a hosted key — the seed stays
in the browser, the login only guards it. That is a separate piece of work and
deliberately not started here.
