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

A **handle** may not be entered twice: an account is one person, and
`@fomo_person` typed onto a second record is the first record again — easy to
do in a handful, hard to notice later, and the two halves then age apart. An
**address** may. It is a place value sits, and more than one person can stand
behind one — an exchange deposit address, a shared or custodial wallet, a whale
whose leads are filed separately — so refusing the second record there refuses
a fact about the world and loses the entry with it. Saying "these are one
person" is the identity graph's job: it joins records through the address they
share and prints each on the other's dossier, with the evidence.

**Correcting.** Half of a dossier is what happened and is a log; the other half
is what somebody told us, and that half ages — a handle changes, a lead becomes
a customer. So exactly the told half opens in place inside "Кто это"
(`PUT /crm/{contact}`), with the same fields in the same order, and the
timeline underneath stays read-only.

**How old the list is.** The button that pulls new people in (`POST /crm/sync`
— platform accounts, bridge addresses, CYBER.sol holders, the whale gate) now
says what it did, and the date of the last run stands under it. That date could
not come from `crm_contacts.last_synced_at`: it is stamped per contact by the
half-hourly balance refresh, so its maximum says a balance was read, not that
the base was rebuilt. Every run writes a row in `crm_syncs` instead — when,
who asked, what it brought, and **whether it was complete**. The last part is
the reason the table exists: the holder scan is one `getProgramAccounts` call
against a public RPC that answers a rate-limit with an *empty result* rather
than an error, and "обновлено минуту назад" over a run that read nothing is
precisely the lie a freshness date is supposed to prevent. A partial run says
so, in amber. The import runs in the request rather than on the queue, because
this host is not guaranteed a worker and an import that silently never happens
is worse than one an operator waits for.

**Selling is a state, not a deletion.** The scan lists the token accounts that
exist; an emptied one is simply absent, so a contact used to keep the balance
and the whale tier it had on the day it was last seen — and the base filled up
with whales holding nothing. Now the run compares the holder set against
everybody we have *seen holding* and writes the difference down: type **лид**,
status **продал**, balance zeroed, record kept. Somebody who sold once is the
readiest audience there is, and the segment "Продали, но остались" is where
they wait. Two guards carry it: nothing happens on an **empty** scan (a
rate-limited RPC is not a market where everyone sold on the same afternoon),
and only a contact with a recorded balance above zero is eligible — a platform
user typed `holder` for owning a wallet never held anything. A record an
operator wrote off by hand keeps `lost`: that is a judgement about the person,
while `sold` is a fact about their balance, and the judgement is the one a
machine must not overwrite.

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

## 4c. Why nobody presses F5

Three operators read the same lenses at the same time — that is the entire
reason this thing exists — and until the console had a heartbeat only the room
refreshed itself. A task claimed on one desk stayed unclaimed on the other, a
person written down at one screen was invisible at the next, and the rail's
badge kept counting a number from four minutes ago. A status board that is only
true after a reload is a status board nobody trusts twice.

**One heartbeat, held by the shell.** `GET /crm/pulse` (`ConsolePulse`,
`useConsolePulse.ts`) is asked once every five seconds by `ConsoleLayout` — the
shell, which outlives every lens inside it, so five lenses never become five
pollers. What comes back is a **version per lens** plus the rail's counts.
Each version is an opaque string the browser only compares; when it differs,
that lens re-reads *its own props* through a partial Inertia reload, and
nothing else on the page is touched — a half-written sentence in a composer
survives, because a refresh that eats one is worse than a stale row.

**A poll and deliberately not a socket.** A push would need a process of its
own — Reverb, or an SSE loop holding a PHP-FPM worker per open tab — and this
is the host whose scheduler was dormant for months without anyone noticing. A
liveness that depends on a daemon nobody watches is a liveness that ends
silently. Three operators asking one cheap question every five seconds is a
load this server does not notice, and it fails the honest way: **the top bar
says "не обновляется"** after three failures in a row, because a console that
quietly froze looks exactly like a quiet night.

**What each version is made of.** A count and the newest `updated_at`, per
table: either alone misses half of what happens — an edit leaves the count
where it was, a delete leaves the high-water mark where it was. "Сейчас" is a
cached derivative of six sources, so it stamps the *material* underneath it —
open incidents, the newest sweep row, what is asleep, the tasks — rather than
rebuilding the queue to find out whether the queue changed. The sweep alone
lands every five minutes, which is the floor on how stale an open queue can
get, and matches the rate the material is collected at anyway. A dossier
watches `messages` alongside `people`, `notes` and `tasks`, because a dossier
open on two desks is usually open because somebody is writing down what was
just said on it.

**The room does not use a version at all.** These columns keep whole seconds,
so two writes inside one second can leave a version where it was; on a board
that is one beat of lateness, and in a conversation it is an answer nobody
ever sees. So the room asks its own question on every beat
(`GET /crm/chat/since`) and gets an answer that is right to the row: lines
**said** (new), lines **changed** under the reader (an answer landing on the
call that asked for it, a line becoming a task) and lines **taken back** —
answered against the window the reader actually holds, so the cost is bounded
by what is on screen rather than by how long the room has existed. The id
roster that repairs a deletion is sent only when the counts disagree. The
window is compared with `>=` and not `>`, so a line changed in the very second
of the last read comes back one extra time instead of being lost for good.

**Costs nothing while nobody is looking.** The heartbeat is paused while the
tab is hidden and beaten once the moment it comes back, which is the reload
this replaces. `attention` is read out of the queue's cache and never rebuilt
by a poll — a cold cache answers `null`, and the rail keeps its previous
number rather than drawing an unknown count as zero. Presence in the room
("seen just now") is stamped by the heartbeat only for whoever has `/crm/chat`
on screen: it means "this person's browser asked *the room* for news", and
somebody reading the numbers is not in the room. That stamp is sent with its
UTC offset — a bare `Y-m-d H:i:s` is read by a browser as its own local time,
which drew a person typing at that moment as last seen three hours ago.

---

## 4d. Finding one person, which is not the same as reading a segment

Segments answer the questions worth re-asking. They are the wrong shape for
the other task — **somebody you know exists and cannot find** — so a narrow
strip sits above the table: type, status, the search box, and an order. All of
it lives in the address, so a question worth asking twice can be bookmarked or
pasted to the other desk, and the back button undoes a filter.

The order is the load-bearing part. Every row on this lens is stamped by the
half-hourly balance refresh, so "newest first" by `updated_at` really means
"in sync order", and a lead entered by hand yesterday sank under a screenful
of whales whose balances were re-read this morning — not merely far down the
list, but never read at all, because the lens reads two screenfuls of
candidates before it ranks them. Two answers: the default order now pulls in
the recently *written down* explicitly, and `sort=added` asks the question
outright ("по дате внесения"), printing the date it sorts on so the order is
not arbitrary against a column of signals.

The search box reads a handle the way it is pasted. Handles are stored bare,
and what an operator types is what they are looking at — `@name`, or the whole
profile URL out of the clipboard — so both spellings are searched
(`Handles::searchable`), along with tags, which are the operator's own filing
and therefore the word they will look for later. A box that answers "not
found" for somebody who is on the books is how a person gets entered twice.

---

## 4e. The dossier: what was said, and the three readings of one stream

A dossier answered "what happened to this person" and could not answer the
question an operator actually arrives with, which is **"where does this
conversation stand"**. That answer lived in somebody's Telegram.

**The correspondence is a table** (`crm_messages`, `CrmMessageController`,
`POST /crm/{contact}/messages`, `DELETE /crm/messages/{message}`), not notes
with a convention, and the reason is one column: `direction`. Only a direction
makes "we wrote four days ago and they have not answered" a fact this console
can state rather than a thing an operator remembers. `sent_at` is the second
load-bearing column — when it was *said*, not when it was typed in, because
these lines are entered after the fact and will later be imported from
Telegram and Discord, where the timestamp is the whole point of the import.
The browser sends it as a full ISO string with the desk's own offset: a bare
`Y-m-d H:i` out of a datetime-local input is read here in the app's timezone,
which is three hours from the desk that typed it. `external_id` is the
importer's guard, unique per channel, so replaying an export writes each line
once; it is null for everything typed by hand, and every engine here allows
repeated nulls in a unique index.

**It records and it does not send.** Nothing on this host holds an operator's
Telegram session, and a CRM that appears to deliver a message it never sent is
worse than one that only writes down. "Написать" stays what it always was — a
link out to the place the conversation actually happens.

**Two derived numbers, and what they say when they cannot say anything.**
«Последний контакт» is the last line and whose it was. «Отвечает» is the
**median** gap between our line and their answer — not the mean, because one
message answered three days later against sixteen answered inside the hour
describes a person who answers inside the hour, and the mean would say a day
and a half. The gap is measured from the *first* unanswered line we sent, not
the last: when three messages go out and one reply comes back, what was waited
on started with the first of them. A conversation nobody has answered reads
"ещё ни разу не ответил" and never a zero, and four days of our own silence
outranks a whale's balance in the one sentence at the top of the page.

**The stream reads three ways** (`?events=all|touch|money`, in the address like
every other filter here): `touch` is what people did — our lines, their
replies, notes, promises — and `money` is what the chain did. The filter is
applied on the server and not in the browser, because a page that hides rows
out of the newest sixty is a page whose "only money" really means "the money
inside the last sixty events", which is a different claim. Every count under
the table is a **count of the record**, from `count()` queries, never of the
slice on screen — a footer that counts what it already holds always says
nothing more is there.

**Copying an address.** Every value on this screen is shortened, and shortened
is exactly what cannot be pasted into an explorer or a message; until now the
only way to get all forty characters was to open the edit form and select the
field by hand. `CopyValue.vue` draws one string and copies another, and
confirms **in place** — a confirmation elsewhere on the page is a confirmation
nobody sees while looking at the value they just copied.

**A promise, made from the page it is about.** "+ Задача" existed in the design
and in the dictionary and led nowhere; a task about this person had to be typed
on the board and pointed back with `#name`. It now takes the board's own
one-line grammar (`@who !when`) minus the part that names the person — that is
the page you are standing on — and «Что дальше» lists only what is still owed,
with the one action that empties it. A closed promise is not "what next"; it is
what happened, and the stream carries it.

---

## 5. The code

| File | Holds |
| --- | --- |
| `app/Services/Console/ConsoleFeed.php` | The queue: incidents, overdue tasks, fresh whales, the gas tank, retention, failed payouts; the watch list; silence; the background tiles |
| `app/Services/Console/FeedItem.php` | One row: key, severity, `since`, evidence, one action |
| `app/Services/Console/ConsoleHeader.php` | The top strip, shared with every lens through `HandleInertiaRequests` |
| `app/Services/Console/Snooze.php` | "Until morning", against `console_snoozes` |
| `app/Services/Console/PeopleLens.php` | Segments and the signal per person |
| `app/Services/Console/PersonDossier.php` | One person as one stream: the three readings, the correspondence, and how long they take to answer |
| `app/Http/Controllers/CrmMessageController.php` | The correspondence: written down here, imported from Telegram and Discord later |
| `resources/js/components/console/CopyValue.vue` | One string drawn, another copied — confirmed in place |
| `app/Support/Handles.php` | A pasted profile link in, a bare handle out — and whether a stored one is an address |
| `app/Services/CrmSyncService.php` | The importers, the run record (`crm_syncs`) and who stopped holding |
| `app/Services/Console/NumbersReport.php` | The six questions, per subject |
| `app/Services/Console/TaskLine.php` | `@who !when #whom` out of one typed line |
| `app/Services/Console/ChatRoom.php` | The room, its people and its files — both lenses on one stream, and what changed in it since a reader last looked |
| `app/Services/Console/ConsolePulse.php` | The heartbeat: one version per lens, and the rail's counts |
| `resources/js/composables/useConsolePulse.ts` | The browser half: one timer for the console, `useConsoleLive` per lens, `useConsoleBeat` for the room |
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
- **A lens that stopped updating says so.** Liveness is a poll, it is paused
  while nobody is looking, and after three failed beats the top bar admits it
  — silence that is indistinguishable from a quiet night is the one failure
  this design cannot afford.
- **Both languages, always.** The server sends keys and parameters, the
  browser holds the dictionary (`tests/Frontend/LocaleMessagesTest.mjs` pins
  the key sets and the `{placeholders}`).
