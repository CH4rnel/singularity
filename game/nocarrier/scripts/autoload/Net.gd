extends Node
## The wired side of NO CARRIER: carriers hidden in the local address space,
## tap downloads, captured files, decode puzzles, and the data market.
## Time-driven parts run through advance(m), called from Game._step.
## All display strings resolve through Loc; files carry a title *index* (ti)
## so saved captures re-render in whichever language is active.

signal net_changed
signal download_done(file: Dictionary)

const GRID_W := 16
const GRID_H := 10
const GLYPHS := "#$%&@=+*"

var carriers: Array = []       # {id, pos: Vector2, cls, size, sig, found, locked}
var taps: Array = [null, null] # per slot: null | {cid, got, corrupt}
var files: Array = []          # {id, name, ti, cls, size, decoded, quality, corrupt, read, media}
var tap_slots := 2
var modem_lvl := 1
var filter_lvl := 1
var next_id := 1
var lore_idx := 0
var scan_marks := {}           # Vector2i -> last ping strength (terminal view)
var _disk_warned := false


## --- carriers / scanning ----------------------------------------------------

func new_day() -> void:
	var kept: Array = []
	for c in carriers:
		if c["locked"]:
			kept.append(c)
	carriers = kept
	scan_marks.clear()
	var n := 4 + randi() % 4
	for i in n:
		carriers.append(_make_carrier())
	net_changed.emit()


func _make_carrier() -> Dictionary:
	var roll := randf()
	var anom_w := 0.10 + Game.anomaly / 250.0
	var cls: String
	if roll < 0.32:
		cls = "junk"
	elif roll < 0.32 + 0.45:
		cls = "data"
	elif roll < 0.32 + 0.45 + anom_w:
		cls = "anom"
	else:
		cls = "echo"
	var size := 0.0
	match cls:
		"junk": size = randf_range(10.0, 30.0)
		"data": size = randf_range(30.0, 120.0)
		"anom": size = randf_range(60.0, 200.0)
		"echo": size = randf_range(15.0, 40.0)
	var sig := ""
	for i in 6:
		sig += GLYPHS[randi() % GLYPHS.length()]
	var c := {
		"id": next_id, "cls": cls, "size": snappedf(size, 1.0), "sig": sig,
		"pos": Vector2(randf_range(0.5, GRID_W - 0.5), randf_range(0.5, GRID_H - 0.5)),
		"found": false, "locked": false,
	}
	next_id += 1
	return c


func ping(cell: Vector2i) -> Dictionary:
	Game.advance_minutes(4.0)
	if Game.is_night():
		Game.anomaly = minf(Game.anomaly + 0.25, 100.0)
	var center := Vector2(float(cell.x) + 0.5, float(cell.y) + 0.5)
	var best := {}
	var best_d := 1e9
	for c in carriers:
		if c["locked"]:
			continue
		var d: float = center.distance_to(c["pos"])
		if d < best_d:
			best_d = d
			best = c
	var out := {"strength": 0}
	if not best.is_empty():
		var noise_amp := maxf(14.0 - 4.0 * float(filter_lvl), 2.0)
		var strength := int(clampf(100.0 - best_d * 16.0 + randf_range(-noise_amp, noise_amp), 0.0, 100.0))
		out["strength"] = strength
		if best_d < 0.75:
			best["found"] = true
			out["found"] = best
			out["strength"] = maxi(strength, 92)
		elif strength >= 35:
			out["dir"] = (Vector2(best["pos"]) - center).normalized()
	scan_marks[cell] = int(out["strength"])
	Sfx.play("blip")
	net_changed.emit()
	return out


func found_at(cell: Vector2i) -> Dictionary:
	for c in carriers:
		if c["found"] and not c["locked"] and Vector2i(Vector2(c["pos"]).floor()) == cell:
			return c
	return {}


func carrier_by_id(cid: int) -> Dictionary:
	for c in carriers:
		if int(c["id"]) == cid:
			return c
	return {}


## --- taps / downloads ---------------------------------------------------------

func active_tap_count() -> int:
	var n := 0
	for t in taps:
		if t != null:
			n += 1
	return n


func lock(c: Dictionary) -> String:
	for i in taps.size():
		if taps[i] == null:
			taps[i] = {"cid": int(c["id"]), "got": 0.0, "corrupt": false}
			c["locked"] = true
			net_changed.emit()
			Sfx.play("beep")
			return ""
	return "no free tap slots"


func kill_tap(slot: int) -> void:
	if slot < 0 or slot >= taps.size() or taps[slot] == null:
		return
	var c := carrier_by_id(int(taps[slot]["cid"]))
	if not c.is_empty():
		c["locked"] = false
	taps[slot] = null
	net_changed.emit()


func download_rate() -> float:
	return 0.8 + 0.5 * float(modem_lvl - 1)  # MB per in-game minute


func advance(m: float) -> void:
	if not Game.power_on():
		return
	var rate := download_rate()
	for i in taps.size():
		var t: Variant = taps[i]
		if t == null:
			continue
		var c := carrier_by_id(int(t["cid"]))
		if c.is_empty():
			taps[i] = null
			continue
		if Media.hdd_free() <= 0.0:
			if not _disk_warned:
				_disk_warned = true
				Game.toast(Loc.t("t.disk_full"))
				Sfx.play("deny")
			continue
		_disk_warned = false
		t["got"] = float(t["got"]) + rate * m
		if float(t["got"]) >= float(c["size"]):
			_complete(i, c)


func _complete(slot: int, c: Dictionary) -> void:
	var f := {
		"id": int(c["id"]),
		"name": "cap_%03d" % int(c["id"]),
		"ti": 0,
		"cls": c["cls"],
		"size": float(c["size"]),
		"decoded": false,
		"quality": 0.0,
		"corrupt": bool(taps[slot]["corrupt"]),
		"read": false,
		"media": Media.pick_hdd(),
	}
	files.append(f)
	taps[slot] = null
	carriers.erase(c)
	Game.toast(Loc.t("t.dl_done", [f["name"], int(f["size"])]))
	Sfx.play("beep")
	download_done.emit(f)
	net_changed.emit()


func heat_corrupt(m: float) -> void:
	for t in taps:
		if t != null and not bool(t["corrupt"]) and randf() < 0.02 * m:
			t["corrupt"] = true
			Game.toast(Loc.t("t.thermal"))


## Only spinning storage is exposed: tapes are dormant, CD-Rs are sealed.
func corrupt_random_file() -> void:
	var clean: Array = []
	for f in files:
		if not bool(f["corrupt"]) and Media.kind_of(int(f.get("media", -1))) == "hdd":
			clean.append(f)
	if clean.is_empty():
		return
	var f: Dictionary = clean[randi() % clean.size()]
	f["corrupt"] = true
	net_changed.emit()


## Working-set usage: captures on hard disks plus running taps.
func disk_used() -> float:
	var used := 0.0
	for f in files:
		if Media.kind_of(int(f.get("media", -1))) == "hdd":
			used += float(f["size"])
	for t in taps:
		if t != null:
			used += float(t["got"])
	return used


func hdd_files() -> Array:
	var out: Array = []
	for f in files:
		if Media.kind_of(int(f.get("media", -1))) == "hdd":
			out.append(f)
	return out


## --- files / decode / market --------------------------------------------------

func make_puzzle(f: Dictionary) -> Dictionary:
	var rng := RandomNumberGenerator.new()
	rng.seed = int(f["id"]) * 7919 + Game.day
	var pattern := ""
	for i in 3:
		pattern += GLYPHS[rng.randi() % GLYPHS.length()]
	var col := rng.randi() % 6
	var rows: Array = []
	for r in 6:
		var row := ""
		for x in 9:
			row += GLYPHS[rng.randi() % GLYPHS.length()]
		row = row.substr(0, col) + pattern + row.substr(col + 3)
		rows.append(row)
	var options: Array = [pattern]
	while options.size() < 4:
		var alt := ""
		for i in 3:
			alt += GLYPHS[rng.randi() % GLYPHS.length()]
		if alt not in options:
			options.append(alt)
	# deterministic shuffle
	for i in range(options.size() - 1, 0, -1):
		var j := rng.randi() % (i + 1)
		var tmp: Variant = options[i]
		options[i] = options[j]
		options[j] = tmp
	return {"rows": rows, "options": options, "correct": options.find(pattern)}


func finish_decode(f: Dictionary, ok: bool) -> void:
	Game.advance_minutes(10.0)
	f["decoded"] = true
	f["quality"] = 1.0 if ok else 0.45
	f["ti"] = int(f["id"]) % Loc.count("title." + str(f["cls"]))
	Game.stats["decoded"] = int(Game.stats["decoded"]) + 1
	if f["cls"] == "anom":
		Game.anomaly = minf(Game.anomaly + 4.0, 100.0)
		Game.stats["anomalies"] = int(Game.stats["anomalies"]) + 1
		Sfx.play("static")
	net_changed.emit()


func title_of(f: Dictionary) -> String:
	return Loc.t("title." + str(f["cls"]), [int(f.get("ti", 0))])


func value(f: Dictionary) -> int:
	var v := 0.0
	match f["cls"]:
		"junk": v = 8.0
		"data": v = (55.0 + float(f["size"]) * 0.7) * float(f["quality"])
		"anom": v = (170.0 + float(f["size"]) * 1.1) * float(f["quality"])
		"echo": v = 0.0
	if bool(f["corrupt"]):
		v *= 0.3
	return int(v)


func sellable(f: Dictionary) -> bool:
	if f["cls"] == "echo":
		return false
	if f["cls"] == "junk":
		return true
	return bool(f["decoded"])


## Decoded anomalies and echoes can be sealed on chain instead of sold.
func sealable(f: Dictionary) -> bool:
	return bool(f["decoded"]) and str(f["cls"]) in ["anom", "echo"]


func sealables() -> Array:
	var out: Array = []
	for f in files:
		if sealable(f):
			out.append(f)
	return out


func seal_file(fid: int, tx: String) -> void:
	for f in files:
		if int(f["id"]) == fid:
			Game.minted.append({"name": f["name"], "title": title_of(f), "tx": tx})
			Game.anomaly = maxf(Game.anomaly - 3.0, 0.0)
			Media.on_file_sealed(f)
			files.erase(f)
			Game.toast(Loc.t("up.sealed"))
			Sfx.play("beep")
			net_changed.emit()
			return


func sell(f: Dictionary) -> int:
	var v := value(f)
	Game.earn_market(v)
	if f["cls"] == "anom":
		Game.anomaly = minf(Game.anomaly + 10.0, 100.0)
	files.erase(f)
	net_changed.emit()
	return v


func purge(f: Dictionary) -> void:
	if f["cls"] == "anom":
		Game.anomaly = maxf(Game.anomaly - 2.0, 0.0)
	files.erase(f)
	Sfx.play("blip")
	net_changed.emit()


func read_echo(f: Dictionary) -> String:
	f["read"] = true
	Game.anomaly = minf(Game.anomaly + 3.0, 100.0)
	var text := Loc.t("echo.lore", [lore_idx])
	lore_idx += 1
	Sfx.play("static")
	return text


## --- upgrades / persistence ----------------------------------------------------

func apply_upgrade(id: String) -> void:
	match id:
		"disk":
			var it := Media.add_hdd(256.0)
			Game.toast(Loc.t("t.up_disk", [str(it["label"]), int(Media.hdd_total())]))
		"modem":
			modem_lvl = mini(modem_lvl + 1, 3)
			Game.toast(Loc.t("t.up_modem", [modem_lvl]))
		"filter":
			filter_lvl = mini(filter_lvl + 1, 3)
			Game.toast(Loc.t("t.up_filter", [filter_lvl]))
		"tapslot":
			if tap_slots < 4:
				tap_slots += 1
				taps.append(null)
				Game.toast(Loc.t("t.up_tap", [tap_slots]))
	net_changed.emit()


func get_state() -> Dictionary:
	var cs: Array = []
	for c in carriers:
		var d: Dictionary = c.duplicate()
		d["pos"] = [c["pos"].x, c["pos"].y]
		cs.append(d)
	return {
		"carriers": cs, "taps": taps, "files": files,
		"tap_slots": tap_slots,
		"modem_lvl": modem_lvl, "filter_lvl": filter_lvl,
		"next_id": next_id, "lore_idx": lore_idx,
	}


func set_state(data: Dictionary) -> void:
	if data.is_empty():
		new_day()
		return
	carriers = []
	for c in data.get("carriers", []):
		var d: Dictionary = c
		var p: Array = d["pos"]
		d["pos"] = Vector2(float(p[0]), float(p[1]))
		carriers.append(d)
	taps = data.get("taps", [null, null])
	files = data.get("files", [])
	# v2 saves predate physical media: stray captures land on the first disk
	for f in files:
		if not f.has("media") or Media.by_id(int(f["media"])).is_empty():
			f["media"] = Media.pick_hdd()
	tap_slots = int(data.get("tap_slots", 2))
	while taps.size() < tap_slots:
		taps.append(null)
	modem_lvl = int(data.get("modem_lvl", 1))
	filter_lvl = int(data.get("filter_lvl", 1))
	next_id = int(data.get("next_id", 1))
	lore_idx = int(data.get("lore_idx", 0))
	net_changed.emit()


func reset() -> void:
	carriers = []
	taps = [null, null]
	files = []
	tap_slots = 2
	modem_lvl = 1
	filter_lvl = 1
	next_id = 1
	lore_idx = 0
	scan_marks = {}
	_disk_warned = false
