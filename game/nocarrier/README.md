# NO CARRIER

A first-person **netstalking survival-horror sim** in **Godot 4**, in the
spirit of signal-station games like *Voices of the Void* — but the frontier
you probe is not the sky. You are the night sysop of **NODE-07**, a basement
relay in a defunct telephone exchange, tapping the wired for carriers that
should not exist.

Fully offline, zero chain dependencies, zero binary assets: the entire world,
UI and even the audio are synthesized from GDScript at runtime.

## The loop

```
scan the address space        → terminal, SCAN: ping sectors, follow the
                                bearing arrows, resolve a carrier (◆)
lock a tap on it              → L on the ◆; the download runs in real time
                                and heats up the racks
decode the capture            → FILES → D: spot the 3-glyph pattern that
                                repeats in every row of the dump
sell to the OPERATOR          → MARKET: junk, data — and NONSTANDARD
                                captures that pay triple but raise the noise
meet the weekly quota         → 3 missed settlements = contract terminated
stay alive and sane           → eat, sleep, coffee; feed the generator,
                                refill the coolant, take out the trash
```

The more nonstandard data you decode and sell, the higher the **line noise**
climbs — and the node starts answering back: flickering lights, calls on a
dead phone, knocks on the sealed stairwell door, a silhouette at the end of
the corridor, and worse. At 100% noise the node falls out of the mesh, with
you inside. Selling anomalies pays; purging them keeps you alive. Cold tapes
let you archive them for a safer middle road.

## Controls

| Key | Action |
|-----|--------|
| `W A S D` / mouse | move / look |
| `Shift` · `Space` | sprint · jump |
| `E` | interact (terminal, bed, kettle, generator, racks, hatch, phone…) |
| `F` | flashlight |
| `1-7` | terminal apps: SCAN · TAPS · FILES · MARKET · SHOP · MAIL · SYS |
| arrows · `Enter` | navigate · ping/confirm |
| `Esc` | leave terminal / free cursor |

Sleeping saves the game (`user://nocarrier_save.json`); `SYS → S` saves
manually, `SYS → W W` wipes the node and starts a new contract.

## Run

```bash
godot4 --path .        # or open project.godot in Godot 4.4+
```

## Test

A headless smoke test drives the whole simulation (scan → lock → download →
decode → sell → shop → delivery → power → sleep → save/load) without the 3D
scene:

```bash
godot4 --headless --import .              # first time only: build class cache
godot4 --headless --path . -s tests/smoke.gd
```

Exit code 0 means every check passed.

## Architecture

```
scenes/Main.tscn           trivial root → scripts/Main.gd builds everything
scripts/Main.gd            station geometry, props, lights, interactables,
                           world-side horror (silhouette, corridor entity, finale)
scripts/Player.gd          first-person controller + interaction raycast
scripts/Interactable.gd    invisible E-prompt volumes (Area3D, layer 2)
scripts/ui/Hud.gd          text-mode HUD: needs bars, warnings, toasts,
                           modal menus, NO CARRIER takeover, endings
scripts/ui/Terminal.gd     CRT terminal TUI: all 7 apps render into one
                           RichTextLabel behind a scanline shader
scripts/autoload/Game.gd   clock (1 s = 1 in-game min), needs, power/thermals,
                           economy, quota, mail, deliveries, save/load
scripts/autoload/Net.gd    carriers, pings, taps, captures, decode puzzles,
                           market values, echo lore
scripts/autoload/Events.gd anomaly-gated event director (flickers → phone →
                           knocks → blackouts → the thing in the corridor)
scripts/autoload/Sfx.gd    procedural synth: every sound is generated PCM
tests/smoke.gd             headless end-to-end simulation test
```

Design note: everything time-based goes through `Game._step(minutes)`, so
sleeping fast-forwards the whole world — downloads finish, fuel burns,
deliveries arrive — instead of pausing it.
