# Мостик — the operator console

`/crm` and everything under it. Behind the wallet allowlist in
`config/crm.php`; everyone else gets a 404, so it is not discoverable by an
ordinary signed-in user.

---

## 1. Why it is a queue and not a list

The old CRM answered the question *"what do you want to look at"*: five
sections, each with filters and a table. There are three operators and they
come in between other work, so the question they actually arrive with is the
other one — **what requires me right now** — and no page answered it.

So the home of the console is a stream, not a list. An incident, an overdue
promise, a whale's first trade, a sagging retention curve and an emptying gas
tank stand in one queue ordered by urgency. The old sections became lenses on
the same material rather than separate applications.

Four decisions hold it up:

1. **The left column of every row is time-in-state, not an icon.** Twelve
   minutes and three hours ask for different things and look identical on a
   status board. The duration column *is* the priority.
2. **One action per row.** Not three equal buttons — one obvious next step,
   with "snooze" beside it.
3. **"Until morning" is a real mechanism.** Half of what a duty operator meets
   is not "do this now" but "do not show me this until nine". A row that
   cannot be put down teaches the whole list to be ignored.
4. **Silence is a designed state, not an empty list.** An empty screen says
   when the last sweep ran and how long it has been quiet, otherwise it is
   indistinguishable from collection having broken.

---

## 2. The language

Surfaces are panels with hairlines, not cards with shadows: in a dense grid a
border on every block costs more attention than it separates.

Type: **Archivo** for sentences, **IBM Plex Mono** for anything the eye
compares — numbers, times, codes, addresses. Tabular figures, or a column
stops reading as a column.

Colour is spent only on anomaly. Anything working is neutral grey: if forty
services glow green, the forty-first glowing red is invisible. Hence four
signals and not one more:

| Signal | Meaning |
| --- | --- |
| red `#ff4d4d` | down, critical, overdue |
| amber `#e0a516` | degrading, running out, gone quiet |
| magenta `#ff2bd6` | a person and money (a whale, a large withdrawal) |
| cyan `#00e5d1` | action and interaction, and nothing else |

**"No data" is hatching, never a colour**: not knowing must not look like a
state of the service. It takes part in no arithmetic either — neither in the
numerator of uptime nor in its denominator.

Everything lives in `resources/css/console.css`, namespaced `mk-` under
`.mostik`, and is loaded with the console only.

---

## 3. What disappeared and why

| Before | Now |
| --- | --- |
| Five pages with the same anatomy (tiles + filters + table) | Five lenses on one stream |
| Filters (`type: whale` + `status: customer`, re-asked by hand every time) | Segments — the same question *saved*, with its rule visible, which is also a line in a report |
| A table of people (database columns) | A feed of change: withdrew 40% in three days, silent for 34 days, replied and is waiting on us |
| A 46-row service table | A grid of tiles; colour and the day strip read in a second, and what is broken is lifted into its own band |
| Ten analytics tiles and eight tables | Six questions, each with an answer, its evidence and one line of what follows |
| Two analytics pages (site and wallet) | One subject switch — the confusion between a session and an installation cost a quarter of arguing |

---

## 4. The lenses

| Route | Lens | Renders |
| --- | --- | --- |
| `/crm` | Сейчас | `crm/Now.vue` — the queue, the watch list, thirty days of background |
| `/crm/people` | Люди | `crm/People.vue` — segments and what happened to each person |
| `/crm/{contact}` | Досье | `crm/Person.vue` — one person, one timeline |
| `/crm/tasks` | Задачи | `crm/Tasks.vue` — late / now / later, plus the unowned band |
| `/crm/numbers` | Числа | `crm/Numbers.vue` — six questions, subject switch |
| `/crm/installs/{uuid}` | Досье установки | `crm/Install.vue` — one anonymous installation |
| `/crm/machines` | Машины | `crm/Machines.vue` — the registry as tiles, hosts, idle, incidents |
| `/crm/mockup` | Макет | `crm/Mockup.vue` — the design this console was built from, artboards and all |

`/crm/analytics`, `/crm/product`, `/crm/services` and `/crm/product/users/{id}`
redirect to the lens that answers the same question — they are in messages, in
bookmarks and in the ops channel.

---

## 5. The code

| File | Holds |
| --- | --- |
| `app/Services/Console/ConsoleFeed.php` | The queue: incidents, overdue tasks, fresh whales, the gas tank, retention, failed payouts; the watch list; silence; the background tiles |
| `app/Services/Console/FeedItem.php` | One row: key, severity, `since`, evidence, one action |
| `app/Services/Console/ConsoleHeader.php` | The top strip, shared with every lens through `HandleInertiaRequests` |
| `app/Services/Console/Snooze.php` | "Until morning", against `console_snoozes` |
| `app/Services/Console/PeopleLens.php` | Segments and the signal per person |
| `app/Services/Console/PersonDossier.php` | One person as one stream |
| `app/Services/Console/NumbersReport.php` | The six questions, per subject |
| `app/Services/Console/TaskLine.php` | `@who !when #whom` out of one typed line |
| `app/Services/Console/ServiceStrips.php` | A day per service, one cell an hour |
| `app/Services/Console/Mockup.php` | The canvas manifest: nine artboards and three annotations out of `resources/console-mockup/` |
| `resources/js/lib/consoleMessages.ts` | Every word, en/ru |
| `resources/js/lib/console.ts` | Durations, plurals, money, the four tones |
| `resources/js/layouts/ConsoleLayout.vue` | The shell: alarm strip, rail, phone bar |

---

## 5a. The design, kept inside the thing it describes

`/crm/mockup` serves the nine artboards the console was drawn as, straight out
of `resources/console-mockup/` (`Mockup.php`, `ConsoleMockupController`). They
are frozen source: nothing imports them, Vite never sees them, and where the
running console differs from the drawing the console is the newer answer.

The reason they live in the repository rather than behind a link is that a
canvas link rots and an exported picture drops the text — and the text is the
argument. `canvas.json` carries it: three annotations that say why the home is
a queue, why colour is spent only on anomaly, and what the five old pages lost.

A screen key never reaches the filesystem: the manifest maps a key it already
knows to a file, everything else is a 404. Each artboard is a full page of its
own CSS, so the lens frames it (`sandbox=""`, `default-src 'none'`) instead of
inlining it — inlined, the design would restyle the console around it.

---

## 5b. Who may open any of this

Two accounts, named twice in `config/crm.php`: `admin_wallets` (the key that
proves the session) and `admin_user_ids` (the person, which survives that key
being re-attached; env-only and empty by default, because an id means nothing
in a database it was not written for). Either name is enough, and anyone else —
including a signed-in user — gets a **404 rather than a 403**, so the console is
not discoverable by trying the address.

`User::scopeCrmOperators` asks both halves in the same order, so a task can
never be assigned to somebody who cannot open the page it is on.

---

## 6. Rules that must not be relaxed

- **The console never writes to a chain and never probes anything.** It renders
  the last sweep and the tables. A dashboard that runs its own checks
  disagrees with the alerts, is slow exactly when the network is, and hammers
  production once somebody leaves it open on a second monitor.
- **The banner, the rail badge and the queue are one answer.** They come from
  one cached build (`ConsoleFeed::cached()`, seconds, dropped on every
  snooze), because a badge that says five over a list of four gets refreshed
  instead of read.
- **A number that cannot be read is `null`**, rendered as an em dash. Never
  zero: zero is an answer.
- **A question the current subject cannot answer says so.** Borrowing the
  other subject's number under this label is how "user" came to mean two
  things.
- **`unknown` is not a failure.** A dead heartbeat says the reporter died and
  nothing about what it reported on: hatched, no incident, out of both halves
  of the uptime fraction.
- **Both languages, always.** The server sends keys and parameters, the
  browser holds the dictionary (`tests/Frontend/LocaleMessagesTest.mjs` pins
  the key sets and the `{placeholders}`).
