# NO CARRIER

A first-person **netstalking survival-horror sim** in **Godot 4**, in the
spirit of signal-station games like *Voices of the Void* — but the frontier
you probe is not the sky. You are the night sysop of **NODE-07**, a basement
relay in a defunct telephone exchange, tapping the wired for carriers that
should not exist.

Bilingual (**english / русский**, switchable in the menu and in `SYS`),
zero binary assets — the world, the UI and even the audio are synthesized
from GDScript at runtime — and **on-chain**: a built-in wallet **signs
Cyberia transactions natively inside Godot** (no MetaMask, no browser), so the
desktop "full" build is fully self-contained. The web build keeps working and
can additionally connect MetaMask.

## The loop

```
scan the address space        → terminal, SCAN: ping sectors, follow the
                                bearing arrows, resolve a carrier (◆)
lock a tap on it              → L on the ◆; the download runs in real time,
                                heats up the racks and lands on a hard disk
decode the capture            → FILES → D: spot the 3-glyph pattern that
                                repeats in every row of the dump
route the data (DEAD MEDIA)   → MEDIA: offload to tape (dormant), burn to
                                CD-R (sealed), print a hardcopy (quiet) —
                                or leave it on the disk and let it leak
sell to the OPERATOR          → MARKET: uploads are loud (+noise); discs
                                and hardcopies leave via the hatch, quietly
…or seal it on chain          → UPLINK: mint a decoded anomaly/echo as a
                                CyberiaNFT — signed by the in-game wallet
                                (Godot signs) or MetaMask on the web
meet the weekly quota         → 3 missed settlements = contract terminated
stay alive and solvent        → eat, sleep, coffee; feed the generator,
                                refill the coolant, take out the trash
```

The more nonstandard data you decode and sell, the higher the **line noise**
climbs — and the node starts answering back: flickering lights, calls on a
dead phone, knocks on the sealed stairwell door, a silhouette at the end of
the corridor, the printer running with nobody at the console, and worse. At
100% noise the node falls out of the mesh, with you inside. Selling anomalies
pays; purging them keeps you alive; tapes, CD-Rs and on-chain sealing are the
middle road.

### DEAD MEDIA

Data is physical. Every capture lives on a concrete medium, each with its
own character, and the station has the devices to move it around:

| Medium | Character |
|--------|-----------|
| **hard disk** (bay) | fast working storage; decode/upload only from here; cooks above 90° (SMART warns, then the disk dies with everything on it); decoded anomalies on a disk **leak noise** and attract corruption |
| **tape** (streamer) | huge and cheap; sequential — the streamer writes in real time; anomalies on tape are **dormant**; oxide ages, dirty heads chew recordings (clean with isopropyl) |
| **CD-R** (burner) | write-once: nothing corrupts a burned disc; the **collector** pays a premium for physical discs and the sale barely raises noise; a power dip mid-burn ruins the blank |
| **paper** (printer/scanner) | hardcopies sell for half, silently; the scanner reads documents found around the station (route slips reveal carriers); sometimes the printer prints things nobody sent |

The **degausser** in the utility room bulk-erases any magnetic medium —
anomalies included — and occasionally trips the breakers out of spite.
Unlabeled tapes and old paperwork spawn around the station: bring them to
the streamer and the scanner. A node with zero working disks gets a refurb
drive shipped on credit — no dead ends, only debt.

### No dead ends

Broke, dark and out of fuel is a situation, not a game over:

- the **hand dynamo** in the utility room charges the UPS battery — cranking
  costs energy but always brings the terminal back;
- **scrap** litters the station (respawns daily) — burn it in the generator
  (+4% fuel) or sell it at the MARKET;
- the **trash bag** burns too (+3% fuel);
- and if the node reports total blackout with an empty ledger, the OPERATOR
  ships one fuel can **on credit** — the debt is collected at the next
  weekly settlement.

## Controls

| Key | Action |
|-----|--------|
| `W A S D` / mouse | move / look |
| `Shift` · `Space` | sprint · jump |
| `E` | interact (terminal, bed, kettle, generator, dynamo, racks, hatch, phone, streamer, degausser, printer, scanner…) |
| `F` | flashlight |
| `C` | connect MetaMask (web build; desktop uses the in-game wallet) |
| `Esc` | menu (pause) / leave terminal |
| `1-9` | terminal apps: SCAN · TAPS · FILES · MARKET · SHOP · MAIL · SYS · UPLINK · MEDIA |
| arrows · `Enter` | navigate · ping/confirm |

Sleeping saves the game (`user://nocarrier_save.json`); `SYS → S` saves
manually, `SYS → L` switches language, `SYS → W W` wipes the node.

`Esc` opens the menu; **Settings** there covers **sound** (master / effects /
ambience / mute) and **display** (fullscreen, vsync, brightness, mouse
sensitivity). Sound routes through dedicated AudioServer buses; the choices —
and the language — persist across wipes in `user://nocarrier_cfg.json`.

## Run

```bash
godot4 --path .        # or open project.godot in Godot 4.4+
```

Desktop runs fully offline: chain reads (Cyberia block height) still load,
but the wallet is browser-only and reports itself unavailable.

The in-game wallet is auto-generated on first launch (a local hot wallet at
`user://nocarrier_wallet.json`). Its address and live CYBER balance are shown
in **UPLINK** — send it a little CYBER for gas, then `enter` seals the
selected capture: the game builds a legacy EIP-155 `mint(string)` transaction,
signs it with its own secp256k1 key, and broadcasts it via
`eth_sendRawTransaction`. No external wallet, no browser required.

Prefer your own key? In UPLINK press **`I`** and paste a 64-hex private key —
the game derives the address, shows its balance, and signs with it from then
on (stored the same way, in `user://`). `X` copies the active address to the
clipboard so you can fund it.

## Native signing

The whole EVM signing path is pure GDScript, no native library and no browser
bridge (`scripts/crypto/`): Keccak-256, secp256k1 ECDSA (Jacobian point math),
RLP, and RFC 6979 deterministic nonces (via Godot's HMAC-SHA256). It is
validated in `tests/crypto_test.gd` against the published EIP-155 example
transaction (which exercises keccak + RLP + ECDSA + low-s + recovery id at
once) and known Keccak/address vectors. A signature takes ~0.4 s — fine for
the occasional mint.

Contract: `mint(string)` on the shared CyberiaNFT collection
(`0x546462FAbf30734E63b64f32B30EC8ADD9B6EBa7`, chain id `49406`). The private
key lives only in `user://` and is never logged or committed.

## Run in the browser (MetaMask optional)

```bash
godot4 --headless --export-release "Web" build/web/index.html
python3 serve_web.py            # http://localhost:8061
```

The web build signs with the same native wallet by default. You can also press
`C` (or use UPLINK) to connect MetaMask / any EIP-1193 wallet instead; it
auto-adds the Cyberia network. As the user warned, a large game hitches in the
browser — the desktop build is the intended "full" experience, and native
signing is exactly what makes it self-sufficient there.

## Test

A headless smoke test drives the whole simulation (scan → lock → download →
decode → sell → shop → delivery → power/battery/dynamo → scrap → bailout →
media: tape/burn/print/degauss/disk-death → sleep → save/load → i18n →
mint calldata) without the 3D scene:

```bash
godot4 --headless --import .              # first time only: build class cache
godot4 --headless --path . -s tests/smoke.gd
```

Exit code 0 means every check passed. Debug knobs: `NC_TIME_SCALE` (game
minutes per real second) and `NC_ANOMALY` (starting noise) as env vars.

## Architecture

```
scenes/Main.tscn           trivial root → scripts/Main.gd builds everything
scripts/Main.gd            station geometry, props, lights, interactables,
                           scrap spawns, world-side horror, menu wiring
scripts/Player.gd          first-person controller + interaction raycast
scripts/Interactable.gd    invisible E-prompt volumes (Area3D, layer 2)
scripts/ui/Menu.gd         title screen + pause menu (pauses the sim)
scripts/ui/Hud.gd          text-mode HUD: needs bars, warnings, toasts,
                           modal menus, NO CARRIER takeover, endings
scripts/ui/Terminal.gd     CRT terminal TUI: all 8 apps render into one
                           RichTextLabel behind a scanline shader
scripts/autoload/Loc.gd    en/ru string catalog; arrays index by saved id so
                           old mail re-renders in the active language
scripts/autoload/Game.gd   clock (1 s = 1 in-game min), needs, power/battery,
                           thermals, economy, quota/debt, mail, save/load
scripts/autoload/Net.gd    carriers, pings, taps, captures, decode puzzles,
                           market values, echo lore, on-chain sealing hooks
scripts/autoload/Media.gd  DEAD MEDIA: hard disks, tapes, CD-Rs, paper;
                           streamer/burner jobs, printer/scanner, degausser,
                           heat damage, tape rot, noise leaks
scripts/autoload/Events.gd anomaly-gated event director (flickers → phone →
                           knocks → blackouts → the thing in the corridor)
scripts/autoload/Sfx.gd    procedural synth: every sound is generated PCM
scripts/autoload/Chain.gd  Cyberia JSON-RPC reads (block, CYBER balance)
scripts/autoload/Wallet.gd browser wallet via JavaScriptBridge (EIP-1193)
scripts/autoload/Ledger.gd CyberiaNFT reads + mint(string) calldata (sealing)
tests/smoke.gd             headless end-to-end simulation test
serve_web.py               static server with COOP/COEP headers
```

Design notes: everything time-based goes through `Game._step(minutes)`, so
sleeping fast-forwards the whole world — downloads finish, fuel burns,
deliveries arrive — instead of pausing it. The menu is the only thing that
truly pauses the sim (`Game.paused`). No private keys in the game: reads are
anonymous, mints go through the player's own browser wallet.
