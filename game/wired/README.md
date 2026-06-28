# Wired

A **3D NFT game** in the Cyberia ecosystem, built in **Godot 4** and playable
**in the browser** with a real wallet. NPCs think with [LainOS](../../services/lainos/).

It is a real NFT game, wired to real contracts:

- **Connect your wallet** (MetaMask / any EIP-1193 provider) in the browser
  build. It auto-adds the Cyberia network (chain id `49406`).
- **Forge NFTs.** Gather *fragments of the Wired* and bring them to the **Forge**
  to **mint a CyberiaNFT** — a real on-chain transaction signed by your wallet
  (`mint(string)` on the shared collection at
  `0x546462FAbf30734E63b64f32B30EC8ADD9B6EBa7`).
- **NFT-gated world.** The gate stays shut until you own at least one CyberiaNFT
  (`balanceOf`, read live from `https://rpc.cyberia.church`). Forge to pass.
- **Your gallery.** The NFTs you own are read from chain (`nextId` + `ownerOf`
  scan) and stand as labelled pedestals beyond the gate.
- **Lain** is the LainOS agent — ask her a balance and she reads the chain
  through her own `cyberia` plugin and answers in character.

## The loop

```
explore  →  collect fragments (glowing orbs)
         →  press C to connect your wallet
         →  at the Forge (orange ring): press M to mint a CyberiaNFT  (costs 3 fragments + gas)
         →  owning ≥1 NFT opens the gate  →  walk through into your gallery
         →  near Lain: E to talk · F to "sign the Wired" (free signature)
```

Reads are anonymous; the mint and the signature go through your own wallet. The
fragment cost is a game rule; the mint itself is a normal on-chain tx (needs a
little CYBER for gas).

## Controls

| Key | Action |
|-----|--------|
| `W A S D` / mouse | move / look |
| `Shift` · `Space` | sprint · jump |
| `C` | connect wallet (browser build) |
| `M` | forge / mint an NFT (at the Forge) |
| `E` | talk to Lain (when close) |
| `F` | sign the Wired (wallet connected, near Lain) |
| `Enter` · `Esc` | send message · leave dialogue / free cursor |

## Run in the editor (desktop)

```bash
godot4 --path .        # or open project.godot in Godot 4.4+
```

Desktop runs read-only: the chain HUD and your NFT count/gallery still load, but
the wallet (connect / mint / sign) is browser-only and reports itself
unavailable there.

## Run in the browser

```bash
# 1. (optional) start Lain's mind so the NPC can think
cd ../../services/lainos && npm run serve        # http://127.0.0.1:7777

# 2. export the web build
godot4 --headless --export-release "Web" build/web/index.html
#    (or: Godot editor > Project > Export > Web > Export Project)
#    If templates are missing: Editor > Manage Export Templates > Download.

# 3. serve it (adds the cross-origin headers a Godot web build wants)
python3 serve_web.py            # http://localhost:8060
```

Open `http://localhost:8060`, press **C** to connect, approve the Cyberia
network, gather fragments, and forge at the orange ring.

### Browser caveats

- LainOS at `http://127.0.0.1:7777` is reachable from a page on plain
  `http://localhost` (its bridge sends `Access-Control-Allow-Origin: *`).
- The Cyberia RPC must allow cross-origin reads for the NFT/balance HUD to load
  in-browser. If reads work on desktop but not in-browser, that's the RPC's CORS
  policy, not the game.

## Give Lain a real model

The NPC uses a stylised **placeholder** (hooded figure) — deliberately generic.
Drop a real model at **`assets/lain.glb`** and `NpcLain.gd` loads it
automatically, no code changes. (`assets/*.glb` is gitignored so big binaries
stay out of the repo. `.vrm` needs a VRM importer addon; `.glb`/`.gltf` work out
of the box.)

## Architecture

```
scenes/Main.tscn          trivial root → scripts/Main.gd builds everything
scripts/Main.gd           world, HUD, dialogue, fragments, forge, gallery, gate
scripts/Player.gd         WiredPlayer — first-person CharacterBody3D
scripts/NpcLain.gd        LainNpc — Lain's body (mind is LainOS); .glb drop-in
scripts/autoload/Chain.gd       Cyberia JSON-RPC client (block + CYBER balance)
scripts/autoload/Wallet.gd      browser wallet via JavaScriptBridge (connect/sign/sendTx)
scripts/autoload/Nft.gd         CyberiaNFT reads (balanceOf/nextId/ownerOf) + mint
scripts/autoload/LainAgent.gd   HTTP bridge to LainOS /chat
export_presets.cfg              Web export preset (thread support off)
serve_web.py                    static server with COOP/COEP headers
```

ABI calldata for `mint(string)` and the `eth_call` reads is hand-encoded in
`Nft.gd` (no web3 lib) using verified function selectors.

## Notes

- No private keys in the game. Reads are anonymous; mints and signatures go
  through the player's own browser wallet. LainOS keeps its optional signer
  (`CYBERIA_AGENT_PK`) entirely separate.
- Built and reviewed without a local Godot binary. The wallet bridge accesses
  `JavaScriptBridge` dynamically, fully guarded behind `OS.has_feature("web")`,
  so desktop runs never touch web-only APIs.
```
