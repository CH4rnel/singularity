class_name DuelRules
extends RefCounted
## Pure combat maths for the ICE duel, mirroring WiredForge.sol exactly so the
## local "training" fight plays by the same rules as the real on-chain run.
##
##   start: you HP 20, ICE HP 20 + tier*10, turns 8 + tier*2
##   ICE's move each turn is deterministic and previewable (a strategy duel).
##   STRIKE  -> 6 dmg (2 if ICE guards), you take the ICE's hit back
##   OVERLOAD-> 10 dmg (4 if ICE guards), you take the ICE's hit back
##   GUARD   -> 0 dmg, but nullifies all incoming damage this turn

const STRIKE := 0
const GUARD := 1
const OVERLOAD := 2
const PLAYER_HP := 20


static func ice_hp(tier: int) -> int:
	return 20 + tier * 10


static func max_turns(tier: int) -> int:
	return 8 + tier * 2


## Deterministic ICE move for a run seed + turn. Local analogue of the contract's
## keccak(seed,turn)%3 — knowable in advance so the player can plan.
static func ice_move(seed: int, turn: int) -> int:
	return abs(hash("%d|%d" % [seed, turn])) % 3


## Returns Vector2i(damage_to_ice, damage_to_player) for the chosen move vs the
## ICE's move — identical to WiredForge.act().
static func damage(move: int, ice: int) -> Vector2i:
	var to_ice := 0
	if move == STRIKE:
		to_ice = 2 if ice == GUARD else 6
	elif move == OVERLOAD:
		to_ice = 4 if ice == GUARD else 10

	var to_player := 0
	if move != GUARD:
		if ice == STRIKE:
			to_player = 5
		elif ice == OVERLOAD:
			to_player = 8

	return Vector2i(to_ice, to_player)


static func move_name(m: int) -> String:
	match m:
		STRIKE: return "STRIKE"
		GUARD: return "GUARD"
		OVERLOAD: return "OVERLOAD"
		_: return "…"


## Can this run be won with perfect play? Because the ICE's moves are knowable,
## a fair fight must be solvable; we use this to pick winnable seeds for training
## so a loss is always the player's mistake, never an impossible roll.
## (Small DP over turns × surviving HP → max damage dealt.)
static func is_winnable(tier: int, seed: int) -> bool:
	var target := ice_hp(tier)
	var turns := max_turns(tier)
	var best := {PLAYER_HP: 0}  # surviving player HP -> max damage dealt to ICE
	for turn in turns:
		var im := ice_move(seed, turn)
		var nb := {}
		for php in best:
			var dealt: int = best[php]
			for move in 3:
				var d := damage(move, im)
				var nhp: int = php - d.y
				if nhp <= 0:
					continue
				var nd: int = dealt + d.x
				if nd >= target:
					return true
				if not nb.has(nhp) or int(nb[nhp]) < nd:
					nb[nhp] = nd
		best = nb
		if best.is_empty():
			return false
	return false


## A run seed that is provably winnable for the tier (falls back after N tries).
static func winnable_seed(tier: int) -> int:
	var s := randi()
	for _i in 96:
		if is_winnable(tier, s):
			return s
		s = randi()
	return s


## A one-line read on the telegraphed ICE move, to teach the strategy.
static func ice_hint(ice: int) -> String:
	match ice:
		GUARD: return "it guards — your hit is halved, but it can't hurt you"
		STRIKE: return "it strikes for 5 — Guard nullifies it, or trade blows"
		OVERLOAD: return "it overloads for 8 — Guard this one"
		_: return ""
