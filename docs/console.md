# Пульт — the operator console

`/crm` and everything under it. Behind the allow list in `config/crm.php`;
everyone else gets a 404, so it is not discoverable by an ordinary signed-in
user.

It was called "Мостик" for one release — the ship's bridge you steer from,
which is where the console's whole vocabulary comes from: смена, дежурный,
обход, тишина. The name was dropped because this site already ships a bridge
at `/bridge`, and two words of one root for two different things is a support
conversation waiting to happen. The artboards under `/crm/mockup` still carry
the old name and are deliberately left alone.

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
| `/crm/chat` | Чат | `crm/Chat.vue` — one room: operators, their files, and LainOS |
| `/crm/chat/files` | Чат · файлы | `crm/ChatFiles.vue` — the same stream read as the pile it collected |
| `/crm/numbers` | Числа | `crm/Numbers.vue` — six questions, subject switch |
| `/crm/installs/{uuid}` | Досье установки | `crm/Install.vue` — one anonymous installation |
| `/crm/machines` | Машины | `crm/Machines.vue` — the registry as tiles, hosts, idle, incidents |
| `/crm/api-keys` | API-ключи | `crm/AiKeys.vue` — LainOS grants, usage and one-time free-key issuance |
| `/crm/mockup` | Макет | `crm/Mockup.vue` — the design this console was built from, artboards and all |

`/crm/analytics`, `/crm/product`, `/crm/services` and `/crm/product/users/{id}`
redirect to the lens that answers the same question — they are in messages, in
bookmarks and in the ops channel.

---

## 4a. The room, and why the file dump is not a folder

The ask was a place to drop files plus a chat between the operators. They are
one thing here: **a file cannot exist without the message that brought it**
(`crm_chat_messages` → `crm_chat_files`), so it always carries who brought it
and what for. `/crm/chat/files` is that stream read a second way — segments
with their rule on screen, exactly like Люди — and the table's last column is
the sentence the file arrived with. A folder is a place where a file is put
silently; a month later it holds five files named `final2.log` and nobody can
account for any of them.

Four decisions the room stands on:

1. **One room, not a messenger.** There are three operators. Channels, threads
   and DMs are four decisions before the thought is written down, and the
   thought is the part that gets lost. What separates one conversation from
   another is the object a line is attached to — `#name` resolves a person
   through the same `TaskLine` grammar the task composer uses.
2. **The left column is time of day, not time in state.** In the queue the
   duration *is* the priority; a room is a log, and a log's spine is when.
3. **One action on a line: "В задачу".** A pinned message is one nobody does;
   a task with an owner and a date is one somebody does. The line keeps the
   task's number (`crm_task_id`), so the evening view can say what the day
   produced.
4. **LainOS is a participant with a visible boundary.** It answers when it is
   called by name (`@lainos` — never `@lain`, which is an operator) and stays
   quiet otherwise: a correspondent that replies to every line turns a working
   log into a chat with a bot.

**Two correspondents answer to that name and they are not interchangeable.**
The daemon (`services/lainos`, `POST /chat` on the host's loopback,
`LAINOS_HTTP_URL`) has tools, memory and a wallet — it can go and look. The
persona (`LainChatService`, straight to OpenRouter) can only reason about what
it was handed. Every answer is stamped with which one gave it and with what it
was allowed to see, and when neither can be reached the room prints a hatched
"LainOS не отвечает" with a retry rather than a sentence nobody stands behind.

What goes up is narrow and is printed under the answer: the last
`crm.chat.lainos.context_messages` lines, the names and sizes of their files,
and the text of a file **only when it is attached to the line that called**.
The call runs in a request of its own (`POST /crm/chat/{message}/answer`,
behind a cache lock) rather than on the queue: this host is not guaranteed a
worker, and an answer that silently never arrives is worse than one an
operator can press again.

Files are written to the **private** disk and handed back only through
`/crm/chat/files/{file}` — the console is a 404 for everyone else, and a file
served from `/storage` would be the one door left open. Executables are
refused by extension, size is capped (`crm.chat.files.max_mb`), and
`crm:chat-prune` drops a message and its bytes together after
`crm.chat.files.retention_days`, because a file whose reason has been deleted
is the orphan this whole design exists to avoid.

---

## 4b. The two places the console writes a person down

Everything else on Люди is derived: the sync writes what the chain, the site
and the bot already know, and the lens reads it. Two people-shaped facts have
no such source, so both are typed by hand and both live on the screen that
shows them.

**Adding.** Fifteen accounts found in one afternoon on X exist nowhere in our
data until somebody enters them, so the composer sits on the lens itself
(`POST /crm/people`) and behaves like a lens and not like a wizard: it stays
open after each save, keeps the type and status of the previous person, clears
its fields and takes the focus back. The redirect goes **back to the list**
rather than into the new dossier — a contact created a second ago has the
freshest signal there is, so it is already the top row. The one thing the form
insists on is that the record names somebody: every column is nullable, and a
row with no name, no handle and no address can never be searched, written to
or recognised again.

**Correcting.** Half of a dossier is what happened and is a log; the other half
is what somebody told us, and that half ages — a handle changes, a lead becomes
a customer. So exactly the told half opens in place inside "Кто это"
(`PUT /crm/{contact}`), with the same fields in the same order, and the
timeline underneath stays read-only.

**Where a person is reachable.** `x_handle` is a column beside `telegram`,
because an address is not somebody you can write to and most people found by
looking are found on X. Both are stored **bare** — `lain`, never `@lain` and
never the URL — and `App\Support\Handles` collapses the three spellings on the
way in, since nobody transcribes a handle out of a profile they are looking at.
The same class decides, on the way out, whether a stored value can become a
link at all: the whale sync files numeric Telegram *ids* in that column (all
the bot knows about somebody who never set a username), and `t.me/819…` opens
nothing. A row whose one action would be a dead page offers "В досье" instead,
and the dossier links only the handles the server could actually build an
address for.

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
| `app/Support/Handles.php` | A pasted profile link in, a bare handle out — and whether a stored one is an address |
| `app/Services/Console/NumbersReport.php` | The six questions, per subject |
| `app/Services/Console/TaskLine.php` | `@who !when #whom` out of one typed line |
| `app/Services/Console/ChatRoom.php` | The room, its people and its files — both lenses on one stream |
| `app/Services/Console/LainOsRoom.php` | One call to LainOS: which backend answered, and what it was allowed to see |
| `app/Services/Console/ServiceStrips.php` | A day per service, one cell an hour |
| `app/Services/Console/Mockup.php` | The canvas manifest: fourteen artboards and four annotations out of `resources/console-mockup/` |
| `resources/js/lib/consoleMessages.ts` | Every word, en/ru |
| `resources/js/lib/console.ts` | Durations, plurals, money, the four tones |
| `resources/js/layouts/ConsoleLayout.vue` | The shell: alarm strip, rail, phone bar |

---

## 5a. The design, kept inside the thing it describes

`/crm/mockup` serves the fourteen artboards the console was drawn as, straight
out of `resources/console-mockup/` (`Mockup.php`, `ConsoleMockupController`). They
are frozen source: nothing imports them, Vite never sees them, and where the
running console differs from the drawing the console is the newer answer.

The reason they live in the repository rather than behind a link is that a
canvas link rots and an exported picture drops the text — and the text is the
argument. `canvas.json` carries it: four annotations that say why the home is a
queue, why colour is spent only on anomaly, what the five old pages lost, and
why the file dump and the chat are the same room. The first nine artboards were
frozen before the room existed, so the sixth lens was drawn on a **second**
canvas rather than by editing a record — each artboard carries the canvas it
came from, and the lens links to that one.

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
- **The room never invents an answer.** An unreachable LainOS is a hatched
  stripe with a retry; a swapped backend is named under the answer. "LainOS
  said so" has to keep meaning something.
- **Both languages, always.** The server sends keys and parameters, the
  browser holds the dictionary (`tests/Frontend/LocaleMessagesTest.mjs` pins
  the key sets and the `{placeholders}`).
