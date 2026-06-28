class_name TrainingDuel
extends RefCounted
## Deep turn-based tactical fight for the Wired training Nodes — NOT rock-paper-
## scissors. The ICE telegraphs an INTENT (slash / charge a nuke / harden / corrupt
## / repair) and you answer with an energy economy and status effects:
##   build energy, burst with Overload, Pierce through armour, stack a Virus DoT,
##   Guard the telegraphed nuke, Patch to sustain corruption.
## Fully deterministic from the seed so a fight is fair and replayable.

# Player abilities (also the [1]..[6] hotkeys).
const STRIKE := 0
const GUARD := 1
const OVERLOAD := 2
const PIERCE := 3
const VIRUS := 4
const PATCH := 5

const MAX_HP := 40
const E_MAX := 6
const E_START := 3
const E_REGEN := 2

const COSTS := [1, 0, 4, 2, 2, 3]
const ANAMES := ["Strike", "Guard", "Overload", "Pierce", "Virus", "Patch"]

var tier := 1
var arch := "aggr"
var arch_name := "ICE"

# Player state
var hp := MAX_HP
var energy := E_START
var block := 0
var corrupt := 0       # turns of corruption left
var corrupt_dmg := 0

# ICE state
var ice_hp := 30
var ice_max := 30
var armor := 0
var bleed := 0         # Virus damage per ICE turn
var charging := false  # winding up a nuke (ICE is vulnerable to burst)
var nuke := false      # the nuke lands on the coming ICE turn

var intent_type := "slash"
var intent_val := 0

var turn := 0
var max_turns := 12
var over := false
var won := false
var logs: Array[String] = []

var _rng := RandomNumberGenerator.new()


func _init(tier_: int, seed_: int) -> void:
	tier = tier_
	_rng.seed = seed_
	match tier:
		1:
			arch = "aggr"; arch_name = "AGGRESSOR"; ice_max = 30; max_turns = 12
		2:
			arch = "trick"; arch_name = "TRICKSTER"; ice_max = 46; max_turns = 14
		3:
			arch = "warden"; arch_name = "WARDEN"; ice_max = 72; max_turns = 16
		_:
			arch = "core"; arch_name = "THE CORE"; ice_max = 78; max_turns = 18
	ice_hp = ice_max
	_next_intent()
	_player_turn_start()  # turn-0 energy regen, same as every turn


func cost(ab: int) -> int:
	return int(COSTS[ab])


func can_afford(ab: int) -> bool:
	return energy >= int(COSTS[ab])


func _next_intent() -> void:
	var r := _rng.randf()
	match arch:
		"aggr":
			if r < 0.30: intent_type = "charge"; intent_val = 0
			elif r < 0.65: intent_type = "slash"; intent_val = 8
			else: intent_type = "slash"; intent_val = 6
		"trick":
			if r < 0.28: intent_type = "harden"; intent_val = 5
			elif r < 0.54: intent_type = "corrupt"; intent_val = 5
			elif r < 0.72: intent_type = "slash"; intent_val = 7
			else: intent_type = "charge"; intent_val = 0
		"warden":
			if r < 0.32: intent_type = "harden"; intent_val = 6
			elif r < 0.54: intent_type = "regen"; intent_val = 7
			elif r < 0.74: intent_type = "charge"; intent_val = 0
			else: intent_type = "slash"; intent_val = 9
		_:
			# THE CORE (tier 4) — every mechanic at once.
			if r < 0.22: intent_type = "harden"; intent_val = 5
			elif r < 0.40: intent_type = "regen"; intent_val = 6
			elif r < 0.56: intent_type = "corrupt"; intent_val = 4
			elif r < 0.76: intent_type = "charge"; intent_val = 0
			else: intent_type = "slash"; intent_val = 8


## One-line read on this ICE archetype and how to beat it — always on screen.
func counter_brief() -> String:
	match arch:
		"aggr": return "AGGRESSOR — fast strikes & NUKES.   Guard the nuke, or Overload it mid-charge."
		"trick": return "TRICKSTER — armor & corruption.   Pierce the armor · Patch the corruption."
		"warden": return "WARDEN — armor + self-repair + nukes.   Virus bleeds through · Pierce armor · Guard nukes."
		_: return "THE CORE — armor + repair + corruption + nukes.   Everything: stack Virus, Pierce, Guard nukes, Patch."


## Human-readable telegraph of what the ICE will do on its next turn.
func intent_label() -> String:
	if nuke:
		return "NUKE lands now — 24 dmg unless you Guard or kill it"
	match intent_type:
		"charge": return "charging a NUKE — Guard next turn, or burst it while exposed"
		"slash": return "will strike for %d  (Guard negates, or trade blows)" % intent_val
		"harden": return "will harden (+%d armor) — Pierce ignores armor" % intent_val
		"corrupt": return "will corrupt you (-%d HP/turn ×3) — Patch cleanses it" % intent_val
		"regen": return "will repair +%d HP — out-damage it, a Virus bleeds through" % intent_val
		_: return "…"


func act(ab: int) -> void:
	if over or not can_afford(ab):
		return
	logs.clear()
	energy -= int(COSTS[ab])
	var dmg := 0
	match ab:
		STRIKE:
			dmg = max(1, 6 - armor)
			ice_hp -= dmg
			logs.append("you Strike → %d" % dmg)
			if armor > 0:
				logs.append("▷ armor absorbed %d — Pierce ignores armor" % (6 - dmg))
		GUARD:
			block += 12
			energy = min(E_MAX, energy + 2)
			logs.append("you Guard (+12 block, +2 energy)")
		OVERLOAD:
			var base: int = max(1, 16 - armor)
			dmg = int(base * 1.5) if charging else base
			ice_hp -= dmg
			block = 0
			logs.append("you Overload → %d" % dmg)
			if armor > 0:
				logs.append("▷ armor absorbed %d — Pierce ignores armor" % (16 - base))
		PIERCE:
			dmg = 8
			if charging: dmg = int(dmg * 1.5)
			ice_hp -= dmg  # ignores armor
			logs.append("you Pierce → %d (ignores armor)" % dmg)
		VIRUS:
			bleed += 3
			logs.append("Virus injected — ICE bleeds %d/turn (ignores armor & repair)" % bleed)
		PATCH:
			var healed: int = min(MAX_HP, hp + 12) - hp
			hp += healed
			corrupt = 0
			logs.append("you Patch (+%d HP, corruption cleansed)" % healed)

	if ice_hp <= 0:
		_finish(true)
		return

	_ice_turn()

	if ice_hp <= 0:
		_finish(true)
		return
	if hp <= 0:
		_finish(false)
		return

	turn += 1
	if turn >= max_turns:
		_finish(false)
		return
	_player_turn_start()
	if hp <= 0:
		_finish(false)


func _ice_turn() -> void:
	if bleed > 0:
		ice_hp -= bleed
		logs.append("ICE bleeds -%d" % bleed)
		if ice_hp <= 0:
			return
	if armor > 0:
		armor = max(0, armor - 1)

	if nuke:
		var taken: int = max(0, 24 - block)
		hp -= taken
		logs.append("the NUKE hits for %d" % taken)
		if block == 0:
			logs.append("▷ took the full nuke — Guard the charge, or kill it while it winds up")
		nuke = false
		charging = false
		_next_intent()
		return

	match intent_type:
		"charge":
			charging = true
			nuke = true
			intent_type = "nuke"
			logs.append("ICE charges its core…")
			logs.append("▷ it's exposed while charging — Overload/Pierce hit +50%, or Guard the blast")
		"slash":
			var taken: int = max(0, intent_val - block)
			hp -= taken
			logs.append("ICE strikes for %d" % taken)
			_next_intent()
		"harden":
			armor = min(8, armor + intent_val)
			logs.append("ICE hardens (+%d armor)" % intent_val)
			_next_intent()
		"corrupt":
			corrupt = 3
			corrupt_dmg = intent_val
			logs.append("ICE corrupts your stack")
			logs.append("▷ corruption drains HP each turn — Patch cleanses it")
			_next_intent()
		"regen":
			ice_hp = min(ice_max, ice_hp + intent_val)
			logs.append("ICE repairs +%d" % intent_val)
			if bleed < intent_val:
				logs.append("▷ it out-heals your damage — stack Virus to bleed through")
			_next_intent()
		_:
			_next_intent()


func _player_turn_start() -> void:
	energy = min(E_MAX, energy + E_REGEN)
	block = 0
	if corrupt > 0:
		hp -= corrupt_dmg
		corrupt -= 1
		logs.append("corruption saps -%d HP" % corrupt_dmg)


func _finish(win: bool) -> void:
	over = true
	won = win
