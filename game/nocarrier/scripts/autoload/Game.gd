extends Node
## NO CARRIER — global game state: clock, needs, power/heat, inventory,
## economy, quota, mail, deliveries, save/load. Every time-based system is
## driven through _step(minutes), so sleeping fast-forwards the whole
## simulation (downloads keep downloading, fuel keeps burning).
##
## Power model: the generator (fuel) is the primary source; a UPS battery
## carries the node when the generator is silent and can be charged by hand
## at the dynamo — so a dead node is always recoverable. When everything is
## truly zero, the OPERATOR ships one fuel can on credit (debt, settled with
## the weekly quota).

signal minute_tick
signal hour_tick(hour: int)
signal day_tick(day: int)
signal state_changed
signal power_changed(on: bool)
signal mail_arrived
signal toasted(msg: String)
signal game_over(kind: String)

const TIME_SCALE := 1.0                  # in-game minutes per real second
const SAVE_PATH := "user://nocarrier_save.json"
const BIN_MAX := 6
const FUEL_PER_CAN := 25.0
const QUOTA_BASE := 500
const QUOTA_STEP := 250
const DELIVERY_MIN := 120.0              # order lead time, in-game minutes
const BAILOUT_DEBT := 60
const DISK_BAILOUT_DEBT := 80
const SCRAP_PRICE := 4
const CRANK_BATTERY := 8.0
const CRANK_ENERGY := 10.0

const ENERGY_DRAIN := 100.0 / (18.0 * 60.0)   # empty after ~18h awake
const HUNGER_DRAIN := 100.0 / (15.0 * 60.0)
const SLEEP_RESTORE := 100.0 / (7.0 * 60.0)
const FUEL_DRAIN := 100.0 / (32.0 * 60.0)     # full tank lasts ~32h
const BATTERY_DRAIN := 100.0 / (6.0 * 60.0)   # full UPS carries ~6h
const BATTERY_CHARGE := 100.0 / (4.0 * 60.0)  # generator refills UPS in ~4h

const SHOP := [
	{"id": "noodles", "price": 15},
	{"id": "coffee", "price": 10},
	{"id": "fuel", "price": 40},
	{"id": "coolant", "price": 30},
	{"id": "paper", "price": 10},
	{"id": "cdr", "price": 15},
	{"id": "alcohol", "price": 25},
	{"id": "tape", "price": 20},
	{"id": "disk", "price": 120},
	{"id": "modem", "price": 250},
	{"id": "filter", "price": 200},
	{"id": "tapslot", "price": 300},
]

# day -> [from_key, subj_key, body_key]
const SCRIPTED_MAIL := {
	1: ["from.op", "mail.d1.subj", "mail.d1.body"],
	2: ["from.op", "mail.d2.subj", "mail.d2.body"],
	3: ["from.maint", "mail.d3.subj", "mail.d3.body"],
	5: ["from.op", "mail.d5.subj", "mail.d5.body"],
	7: ["from.op", "mail.d7.subj", "mail.d7.body"],
	10: ["from.maint", "mail.d10.subj", "mail.d10.body"],
}

# anomaly threshold -> [from_key, subj_key, body_key]
const ANOMALY_MAIL := {
	25: ["from.unknown", "mail.a25.subj", "mail.a25.body"],
	50: ["from.unknown", "mail.a50.subj", "mail.a50.body"],
	75: ["from.unknown", "mail.a75.subj", "mail.a75.body"],
}

var day := 1
var time_min := 8.0 * 60.0               # minutes since midnight
var money := 60
var lifetime_earned := 0
var energy := 90.0                       # 0..100
var hunger := 80.0                       # 0..100 (100 = full)
var anomaly := 0.0                       # 0..100, the hidden horror dial
var strikes := 0
var sold_since_quota := 0
var quota_week := 1
var debt := 0

var inventory := {"noodles": 2, "coffee": 1, "fuel": 2, "coolant": 1, "scrap": 0,
	"paper": 12, "cdr": 0, "alcohol": 0, "docs": 0}

var generator_on := true
var breaker_ok := true
var fuel := 80.0                         # 0..100
var battery := 50.0                      # 0..100 UPS charge
var coolant := 100.0                     # 0..100
var heat := 35.0                         # 0..100

var bin := 0
var carrying_trash := false

var deliveries: Array = []               # [{at: abs_min, items: {id: n}}]
var hatch := {}                          # arrived, waiting for pickup {id: n}
var mails: Array = []                    # [{day, fk, sk, sa, bk, ba, read}]
var minted: Array = []                   # sealed captures: [{name, title, tx}]
var stats := {"decoded": 0, "sold": 0, "anomalies": 0}

var asleep := false
var paused := false                      # menu open — simulation halted
var over := false
var over_kind := ""
var time_scale := TIME_SCALE             # NC_TIME_SCALE env overrides, for stress runs

var _was_power := true
var _was_gen := true
var _last_whole_min := -1
var _fuel_warned := 100
var _heat_warn_cd := 0.0
var _bailout_sent := false
var _disk_bailout_sent := false
var _anomaly_mail_sent: Array = []


func _ready() -> void:
	randomize()
	var env_ts := OS.get_environment("NC_TIME_SCALE")
	if env_ts.is_valid_float():
		time_scale = env_ts.to_float()
	var env_an := OS.get_environment("NC_ANOMALY")
	if env_an.is_valid_float():
		anomaly = clampf(env_an.to_float(), 0.0, 100.0)
	if FileAccess.file_exists(SAVE_PATH):
		_load()
	else:
		_first_day()


func _process(delta: float) -> void:
	if over or paused:
		return
	advance_minutes(delta * time_scale)
	if not asleep and energy <= 0.0:
		_collapse()


## --- clock -----------------------------------------------------------------

func advance_minutes(mins: float) -> void:
	while mins > 0.0 and not over:
		var step := minf(mins, 1.0)
		mins -= step
		_step(step)


func gen_running() -> bool:
	return generator_on and fuel > 0.0


func _step(m: float) -> void:
	# needs
	if asleep:
		energy = minf(energy + SLEEP_RESTORE * m, 100.0)
		hunger = maxf(hunger - HUNGER_DRAIN * 0.4 * m, 0.0)
	else:
		var drain := ENERGY_DRAIN * (1.35 if bin >= BIN_MAX else 1.0)
		if hunger <= 0.0:
			drain *= 2.2
		energy = maxf(energy - drain * m, 0.0)
		hunger = maxf(hunger - HUNGER_DRAIN * m, 0.0)

	# power sources: generator first, then the UPS battery
	if gen_running():
		fuel = maxf(fuel - FUEL_DRAIN * m, 0.0)
		battery = minf(battery + BATTERY_CHARGE * m, 100.0)
	elif breaker_ok and battery > 0.0:
		battery = maxf(battery - BATTERY_DRAIN * m, 0.0)

	# server thermals
	var load := Net.active_tap_count() if power_on() else 0
	var target := 28.0 + 16.0 * load
	if coolant <= 0.0 and load > 0:
		target += 25.0
	heat = move_toward(heat, target, (2.4 if target > heat else 1.2) * m)
	if load > 0:
		coolant = maxf(coolant - 0.07 * load * m, 0.0)
	if heat >= 90.0:
		Net.heat_corrupt(m)
		Media.heat_stress(m)

	anomaly = maxf(anomaly - 0.2 / 60.0 * m, 0.0)

	Net.advance(m)
	Media.advance(m)
	Events.advance(m)

	# clock
	var prev_hour := int(time_min / 60.0)
	time_min += m
	while time_min >= 1440.0:
		time_min -= 1440.0
		day += 1
		_on_new_day()
		prev_hour = -1
	if int(time_min) != _last_whole_min:
		_last_whole_min = int(time_min)
		_on_minute()
	var hour := int(time_min / 60.0)
	if hour != prev_hour:
		hour_tick.emit(hour)

	# power / generator edges
	var now_gen := gen_running()
	if now_gen != _was_gen:
		_was_gen = now_gen
		if not now_gen and breaker_ok and battery > 0.5:
			toast(Loc.t("t.on_battery"))
	var now_power := power_on()
	if now_power != _was_power:
		_was_power = now_power
		power_changed.emit(now_power)
		Sfx.play("power_up" if now_power else "power_down")

	# anomaly mail thresholds
	for t in ANOMALY_MAIL:
		if anomaly >= t and t not in _anomaly_mail_sent:
			_anomaly_mail_sent.append(t)
			var mm: Array = ANOMALY_MAIL[t]
			add_mail(mm[0], mm[1], [], mm[2], [])


func _on_minute() -> void:
	minute_tick.emit()
	# deliveries
	var still: Array = []
	for d in deliveries:
		if abs_min() >= float(d["at"]):
			for id in d["items"]:
				hatch[id] = int(hatch.get(id, 0)) + int(d["items"][id])
			toast(Loc.t("t.delivery"))
			Sfx.play("thud")
		else:
			still.append(d)
	deliveries = still
	# fuel warnings
	for level in [20, 10, 5]:
		if fuel <= float(level) and _fuel_warned > level:
			_fuel_warned = level
			toast(Loc.t("t.fuel_at", [int(fuel)]))
			Sfx.play("alarm")
			break
	if fuel <= 0.0 and _fuel_warned > 0:
		_fuel_warned = 0
		toast(Loc.t("t.gen_dies"))
	# heat warning
	_heat_warn_cd = maxf(_heat_warn_cd - 1.0, 0.0)
	if heat >= 90.0 and _heat_warn_cd <= 0.0:
		_heat_warn_cd = 5.0
		toast(Loc.t("t.overheat"))
		Sfx.play("alarm")
	_check_bailout()
	_check_disk_bailout()


## The OPERATOR never lets the node die of poverty: total blackout with an
## empty ledger ships one fuel can on credit, once per settlement period.
func _check_bailout() -> void:
	if _bailout_sent or power_on():
		return
	if fuel > 0.0 or battery > 0.5 or int(inventory["fuel"]) > 0 or money >= 40:
		return
	_bailout_sent = true
	debt += BAILOUT_DEBT
	hatch["fuel"] = int(hatch.get("fuel", 0)) + 1
	add_mail("from.op", "mail.bail.subj", [], "mail.bail.body", [])
	toast(Loc.t("t.delivery"))
	Sfx.play("thud")
	state_changed.emit()


## Same no-dead-ends promise for storage: a node with zero working disks
## cannot capture anything, so the OPERATOR ships a refurb drive on credit.
func _check_disk_bailout() -> void:
	if _disk_bailout_sent or not Media.hdds().is_empty():
		return
	if int(hatch.get("disk", 0)) > 0 or money >= 120:
		return
	for d in deliveries:
		if int(d["items"].get("disk", 0)) > 0:
			return
	_disk_bailout_sent = true
	debt += DISK_BAILOUT_DEBT
	hatch["disk"] = int(hatch.get("disk", 0)) + 1
	add_mail("from.op", "mail.dbail.subj", [], "mail.dbail.body", [])
	toast(Loc.t("t.delivery"))
	Sfx.play("thud")
	state_changed.emit()


func _on_new_day() -> void:
	day_tick.emit(day)
	Net.new_day()
	Media.new_day()
	if day in SCRIPTED_MAIL:
		var m: Array = SCRIPTED_MAIL[day]
		add_mail(m[0], m[1], [], m[2], [])
	# weekly quota settles on days 8, 15, 22, ...
	if day > 7 and (day - 1) % 7 == 0:
		_settle_quota()
	save()


func _settle_quota() -> void:
	var needed := quota_needed()
	if sold_since_quota >= needed:
		money += 100
		add_mail("from.op", "mail.quota_ok.subj", [quota_week],
			"mail.quota_ok.body", [sold_since_quota, needed])
	else:
		strikes += 1
		add_mail("from.op", "mail.quota_short.subj", [quota_week],
			"mail.quota_short.body", [sold_since_quota, needed, strikes])
		Sfx.play("deny")
	if debt > 0:
		var pay := mini(money, debt)
		money -= pay
		debt -= pay
		if pay > 0:
			toast(Loc.t("t.debt_paid", [pay]))
	quota_week += 1
	sold_since_quota = 0
	_bailout_sent = false
	_disk_bailout_sent = false
	state_changed.emit()
	if strikes >= 3:
		finish("terminated")


func quota_needed() -> int:
	return QUOTA_BASE + QUOTA_STEP * (quota_week - 1)


func quota_due_day() -> int:
	return quota_week * 7 + 1


func abs_min() -> float:
	return float(day - 1) * 1440.0 + time_min


func hour_now() -> int:
	return int(time_min / 60.0)


func is_night() -> bool:
	var h := hour_now()
	return h < 6 or h >= 23


func fmt_clock() -> String:
	return "%02d:%02d" % [hour_now(), int(time_min) % 60]


## --- power -----------------------------------------------------------------

func power_on() -> bool:
	return breaker_ok and (gen_running() or battery > 0.0)


func on_battery() -> bool:
	return power_on() and not gen_running()


func toggle_generator() -> void:
	generator_on = not generator_on
	toast(Loc.t("t.gen_on" if generator_on else "t.gen_off"))
	state_changed.emit()


func refuel_generator() -> bool:
	if int(inventory["fuel"]) <= 0:
		toast(Loc.t("t.no_fuel_cans"))
		return false
	if fuel >= 99.0:
		toast(Loc.t("t.tank_full"))
		return false
	inventory["fuel"] = int(inventory["fuel"]) - 1
	fuel = minf(fuel + FUEL_PER_CAN, 100.0)
	_fuel_warned = 100
	toast(Loc.t("t.fueled", [int(fuel)]))
	Sfx.play("blip")
	state_changed.emit()
	return true


func burn_scrap() -> void:
	if int(inventory["scrap"]) <= 0:
		toast(Loc.t("t.no_scrap"))
		return
	inventory["scrap"] = int(inventory["scrap"]) - 1
	fuel = minf(fuel + 4.0, 100.0)
	_fuel_warned = 100
	toast(Loc.t("t.burn_scrap"))
	Sfx.play("thud")
	state_changed.emit()


func burn_bag() -> void:
	if not carrying_trash:
		toast(Loc.t("t.nothing_dump"))
		return
	carrying_trash = false
	fuel = minf(fuel + 3.0, 100.0)
	_fuel_warned = 100
	toast(Loc.t("t.burn_bag"))
	Sfx.play("thud")
	state_changed.emit()


func crank_dynamo() -> void:
	if battery >= 99.5:
		toast(Loc.t("t.battery_full"))
		return
	if energy < CRANK_ENERGY + 2.0:
		toast(Loc.t("t.crank_tired"))
		Sfx.play("deny")
		return
	advance_minutes(10.0)
	energy = maxf(energy - CRANK_ENERGY, 1.0)
	battery = minf(battery + CRANK_BATTERY, 100.0)
	toast(Loc.t("t.crank", [int(battery)]))
	Sfx.play("blip")
	state_changed.emit()


func trip_breaker() -> void:
	if not breaker_ok:
		return
	breaker_ok = false
	state_changed.emit()


func reset_breaker() -> void:
	if breaker_ok:
		toast(Loc.t("t.breaker_hum"))
		return
	breaker_ok = true
	toast(Loc.t("t.breaker_reset"))
	Sfx.play("blip")
	state_changed.emit()


func top_up_coolant() -> bool:
	if int(inventory["coolant"]) <= 0:
		toast(Loc.t("t.no_coolant"))
		return false
	if coolant >= 99.0:
		toast(Loc.t("t.loop_full"))
		return false
	inventory["coolant"] = int(inventory["coolant"]) - 1
	coolant = 100.0
	toast(Loc.t("t.coolant_ok"))
	Sfx.play("blip")
	state_changed.emit()
	return true


func clean_vents() -> void:
	advance_minutes(5.0)
	heat = maxf(heat - 12.0, 25.0)
	toast(Loc.t("t.dust"))
	state_changed.emit()


## --- needs / scrap -----------------------------------------------------------

func eat_noodles() -> void:
	if int(inventory["noodles"]) <= 0:
		toast(Loc.t("t.no_noodles"))
		return
	inventory["noodles"] = int(inventory["noodles"]) - 1
	advance_minutes(6.0)
	hunger = minf(hunger + 45.0, 100.0)
	bin = mini(bin + 1, BIN_MAX)
	toast(Loc.t("t.bin_full" if bin >= BIN_MAX else "t.noodles"))
	state_changed.emit()


func drink_coffee() -> void:
	if int(inventory["coffee"]) <= 0:
		toast(Loc.t("t.no_coffee"))
		return
	inventory["coffee"] = int(inventory["coffee"]) - 1
	energy = minf(energy + 18.0, 100.0)
	hunger = minf(hunger + 3.0, 100.0)
	toast(Loc.t("t.coffee"))
	Sfx.play("blip")
	state_changed.emit()


func take_trash() -> void:
	if carrying_trash:
		toast(Loc.t("t.have_bag"))
		return
	if bin <= 0:
		toast(Loc.t("t.bin_empty"))
		return
	bin = 0
	carrying_trash = true
	toast(Loc.t("t.take_bag"))
	state_changed.emit()


func dump_trash() -> void:
	if not carrying_trash:
		toast(Loc.t("t.nothing_dump"))
		return
	carrying_trash = false
	toast(Loc.t("t.bag_falls"))
	Sfx.play("thud")
	state_changed.emit()


func pick_scrap() -> void:
	inventory["scrap"] = int(inventory["scrap"]) + 1
	toast(Loc.t("t.scrap", [int(inventory["scrap"])]))
	Sfx.play("blip")
	state_changed.emit()


func pick_doc() -> void:
	inventory["docs"] = int(inventory["docs"]) + 1
	toast(Loc.t("t.doc_pick", [int(inventory["docs"])]))
	Sfx.play("blip")
	state_changed.emit()


func sell_scrap() -> int:
	var n := int(inventory["scrap"])
	if n <= 0:
		return 0
	inventory["scrap"] = 0
	var v := n * SCRAP_PRICE
	earn_market(v)
	toast(Loc.t("market.scrap_sold", [v]))
	Sfx.play("beep")
	return v


func sleep_hours(hours: float) -> void:
	asleep = true
	advance_minutes(hours * 60.0)
	asleep = false
	save()
	toast(Loc.t("t.saved", [day, fmt_clock()]))
	state_changed.emit()


func _collapse() -> void:
	toast(Loc.t("t.collapse"))
	Sfx.play("static")
	asleep = true
	advance_minutes(6.0 * 60.0)
	asleep = false
	energy = 45.0
	anomaly = minf(anomaly + 2.0, 100.0)
	toast(Loc.t("t.wake_floor"))
	save()
	state_changed.emit()


## --- economy ---------------------------------------------------------------

func earn(n: int) -> void:
	money += n
	lifetime_earned += n
	state_changed.emit()


func earn_market(n: int) -> void:
	sold_since_quota += n
	stats["sold"] = int(stats["sold"]) + 1
	earn(n)


func spend(n: int) -> bool:
	if money < n:
		Sfx.play("deny")
		toast(Loc.t("t.no_credits"))
		return false
	money -= n
	state_changed.emit()
	return true


func buy(item_id: String) -> void:
	var item := {}
	for s in SHOP:
		if s["id"] == item_id:
			item = s
			break
	if item.is_empty():
		return
	if item_id == "tapslot" and Net.tap_slots >= 4:
		toast(Loc.t("t.no_slots"))
		Sfx.play("deny")
		return
	if item_id == "modem" and Net.modem_lvl >= 3:
		toast(Loc.t("t.amp_max"))
		Sfx.play("deny")
		return
	if item_id == "filter" and Net.filter_lvl >= 3:
		toast(Loc.t("t.filter_max"))
		Sfx.play("deny")
		return
	if not spend(int(item["price"])):
		return
	deliveries.append({"at": abs_min() + DELIVERY_MIN, "items": {item_id: 1}})
	toast(Loc.t("t.ordered"))
	Sfx.play("beep")


func collect_hatch() -> void:
	if hatch.is_empty():
		toast(Loc.t("t.hatch_empty"))
		return
	var parts: Array = []
	for id in hatch:
		var n := int(hatch[id])
		if id in ["disk", "modem", "filter", "tapslot"]:
			for i in n:
				Net.apply_upgrade(id)
			parts.append(Loc.t("shop.%s.n" % id))
		elif id == "tape":
			for i in n:
				Media.add_tape()
			parts.append("%s x%d" % [Loc.t("shop.%s.n" % id), n])
		elif id == "paper":
			inventory["paper"] = int(inventory["paper"]) + Media.PAPER_PER_PACK * n
			parts.append("%s x%d" % [Loc.t("shop.%s.n" % id), n])
		else:
			inventory[id] = int(inventory.get(id, 0)) + n
			parts.append("%s x%d" % [Loc.t("shop.%s.n" % id), n])
	hatch = {}
	toast(Loc.t("t.collected", [", ".join(parts)]))
	Sfx.play("blip")
	state_changed.emit()


## --- mail / endings ---------------------------------------------------------

func add_mail(fk: String, sk: String, sa: Array, bk: String, ba: Array) -> void:
	mails.push_front({"day": day, "fk": fk, "sk": sk, "sa": sa, "bk": bk, "ba": ba, "read": false})
	mail_arrived.emit()
	toast(Loc.t("t.new_mail", [Loc.t(sk, sa)]))
	Sfx.play("beep")


func unread_mail() -> int:
	var n := 0
	for m in mails:
		if not m["read"]:
			n += 1
	return n


## Render helpers tolerant of both mail shapes (v1 saves stored raw text).
func mail_from(m: Dictionary) -> String:
	return Loc.t(str(m["fk"])) if m.has("fk") else str(m.get("from", "?"))


func mail_subj(m: Dictionary) -> String:
	return Loc.t(str(m["sk"]), m.get("sa", [])) if m.has("sk") else str(m.get("subj", "?"))


func mail_body(m: Dictionary) -> String:
	return Loc.t(str(m["bk"]), m.get("ba", [])) if m.has("bk") else str(m.get("body", ""))


func toast(msg: String) -> void:
	toasted.emit(msg)


func finish(kind: String) -> void:
	if over:
		return
	over_kind = kind
	over = true
	save()
	game_over.emit(kind)


## --- persistence -------------------------------------------------------------

func _first_day() -> void:
	Net.new_day()
	var m: Array = SCRIPTED_MAIL[1]
	add_mail(m[0], m[1], [], m[2], [])


func save() -> void:
	var data := {
		"v": 3,
		"day": day, "time_min": time_min, "money": money,
		"lifetime_earned": lifetime_earned,
		"energy": energy, "hunger": hunger, "anomaly": anomaly,
		"strikes": strikes, "sold_since_quota": sold_since_quota,
		"quota_week": quota_week, "debt": debt, "inventory": inventory,
		"generator_on": generator_on, "breaker_ok": breaker_ok,
		"fuel": fuel, "battery": battery, "coolant": coolant, "heat": heat,
		"bin": bin, "carrying_trash": carrying_trash,
		"deliveries": deliveries, "hatch": hatch, "mails": mails,
		"minted": minted, "stats": stats,
		"over": over, "over_kind": over_kind,
		"bailout_sent": _bailout_sent, "disk_bailout_sent": _disk_bailout_sent,
		"anomaly_mail_sent": _anomaly_mail_sent,
		"net": Net.get_state(),
		"media": Media.get_state(),
	}
	var f := FileAccess.open(SAVE_PATH, FileAccess.WRITE)
	if f:
		f.store_string(JSON.stringify(data))


func _load() -> void:
	var f := FileAccess.open(SAVE_PATH, FileAccess.READ)
	if f == null:
		_first_day()
		return
	var data: Variant = JSON.parse_string(f.get_as_text())
	if typeof(data) != TYPE_DICTIONARY:
		_first_day()
		return
	day = int(data.get("day", 1))
	time_min = float(data.get("time_min", 480.0))
	money = int(data.get("money", 60))
	lifetime_earned = int(data.get("lifetime_earned", 0))
	energy = float(data.get("energy", 90.0))
	hunger = float(data.get("hunger", 80.0))
	anomaly = float(data.get("anomaly", 0.0))
	strikes = int(data.get("strikes", 0))
	sold_since_quota = int(data.get("sold_since_quota", 0))
	quota_week = int(data.get("quota_week", 1))
	debt = int(data.get("debt", 0))
	var inv: Dictionary = data.get("inventory", {})
	for k in inventory:
		inventory[k] = int(inv.get(k, inventory[k]))
	generator_on = bool(data.get("generator_on", true))
	breaker_ok = bool(data.get("breaker_ok", true))
	fuel = float(data.get("fuel", 80.0))
	battery = float(data.get("battery", 50.0))
	coolant = float(data.get("coolant", 100.0))
	heat = float(data.get("heat", 35.0))
	bin = int(data.get("bin", 0))
	carrying_trash = bool(data.get("carrying_trash", false))
	deliveries = data.get("deliveries", [])
	hatch = data.get("hatch", {})
	mails = data.get("mails", [])
	minted = data.get("minted", [])
	var st: Dictionary = data.get("stats", {})
	for k in stats:
		stats[k] = int(st.get(k, 0))
	over = bool(data.get("over", false))
	over_kind = str(data.get("over_kind", ""))
	_bailout_sent = bool(data.get("bailout_sent", false))
	_disk_bailout_sent = bool(data.get("disk_bailout_sent", false))
	_anomaly_mail_sent = data.get("anomaly_mail_sent", [])
	# media first: Net's migration assigns strays to an existing disk
	var net_data: Dictionary = data.get("net", {})
	Media.set_state(data.get("media", {}), float(net_data.get("disk_total", 512.0)))
	Net.set_state(net_data)
	_was_power = power_on()
	_was_gen = gen_running()
	_fuel_warned = int(fuel)


func reset_all() -> void:
	var dir := DirAccess.open("user://")
	if dir and dir.file_exists("nocarrier_save.json"):
		dir.remove("nocarrier_save.json")
	day = 1
	time_min = 8.0 * 60.0
	money = 60
	lifetime_earned = 0
	energy = 90.0
	hunger = 80.0
	anomaly = 0.0
	strikes = 0
	sold_since_quota = 0
	quota_week = 1
	debt = 0
	inventory = {"noodles": 2, "coffee": 1, "fuel": 2, "coolant": 1, "scrap": 0,
		"paper": 12, "cdr": 0, "alcohol": 0, "docs": 0}
	generator_on = true
	breaker_ok = true
	fuel = 80.0
	battery = 50.0
	coolant = 100.0
	heat = 35.0
	bin = 0
	carrying_trash = false
	deliveries = []
	hatch = {}
	mails = []
	minted = []
	stats = {"decoded": 0, "sold": 0, "anomalies": 0}
	asleep = false
	over = false
	over_kind = ""
	_was_power = true
	_was_gen = true
	_last_whole_min = -1
	_fuel_warned = 100
	_bailout_sent = false
	_disk_bailout_sent = false
	_anomaly_mail_sent = []
	Media.reset()
	Net.reset()
	Events.reset()
	_first_day()
	if is_inside_tree() and get_tree().current_scene != null:
		get_tree().call_deferred("reload_current_scene")
