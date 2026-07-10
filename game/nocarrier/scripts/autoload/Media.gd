extends Node
## DEAD MEDIA — the physical storage layer of NO CARRIER. Every capture in
## Net.files lives on a concrete medium: a hard disk in the bay (fast, hot,
## mortal), a magnetic tape on the shelf (huge, slow, dormant), a burned
## CD-R (write-once, incorruptible) or a paper hardcopy out of the printer.
## Devices — streamer, CD burner, printer, scanner, degausser — move data
## between media. Timed transfers run as jobs through advance(m) from
## Game._step, so sleeping finishes tape writes and burns.
##
## Containment ladder: anomalies on a hard disk leak line noise and can
## corrupt neighbours; on tape they sleep; on a CD-R they are sealed for
## good; sealing on chain (UPLINK) removes them from the world entirely.

signal media_changed

const HDD_START := 512.0
const TAPE_CAP := 180.0
const TAPE_RATE := 3.0        # streamer, MB per in-game minute
const BURN_RATE := 6.0        # burner, MB per in-game minute
const DIRT_PER_MB := 0.06     # head dirt gained per MB through the streamer
const HEAT_DAMAGE := 0.25     # hdd health lost per minute at >= 90°
const LEAK_PER_ANOM := 0.5 / 60.0  # noise per minute per decoded anomaly on hdd
const PAPER_PER_PACK := 20
const DEGAUSS_MIN := 15.0
const PRINT_MIN := 3.0
const SCAN_MIN := 5.0

var items: Array = []         # media: {id, kind, label, ...per-kind fields}
var jobs: Array = []          # {dev, kind, fid, to, got, size}
var head_dirt := 0.0          # 0..100 streamer heads
var next_id := 1
var counters := {"hdd": 0, "tape": 0, "cd": 0, "paper": 0}


func _ready() -> void:
	# a fresh boot without a save still needs a working disk in the bay;
	# Game._load / reset_all replace this state when they run
	if items.is_empty():
		_defaults(HDD_START)


## --- items -------------------------------------------------------------------

func _make(kind: String) -> Dictionary:
	counters[kind] = int(counters[kind]) + 1
	var prefix: String = {"hdd": "HD", "tape": "T", "cd": "CD", "paper": "PG"}[kind]
	var it := {"id": next_id, "kind": kind, "label": "%s%d" % [prefix, int(counters[kind])]}
	next_id += 1
	items.append(it)
	return it


func add_hdd(cap := 256.0) -> Dictionary:
	var it := _make("hdd")
	it["cap"] = cap
	it["health"] = 100.0
	it["warn"] = 0
	media_changed.emit()
	return it


func add_tape(cap := TAPE_CAP) -> Dictionary:
	var it := _make("tape")
	it["cap"] = cap
	it["health"] = 100.0
	it["found"] = false
	media_changed.emit()
	return it


## A tape scavenged in the station: unknown content, tired oxide.
func add_found_tape() -> Dictionary:
	var it := add_tape(TAPE_CAP)
	it["found"] = true
	it["health"] = randf_range(20.0, 60.0)
	var n := 1 + (1 if randf() < 0.4 else 0)
	for i in n:
		var roll := randf()
		var cls := "data"
		if roll < 0.20 + Game.anomaly / 400.0:
			cls = "anom"
		elif roll < 0.55:
			cls = "echo"
		Net.files.append({
			"id": Net.next_id, "name": "tp_%03d" % Net.next_id, "ti": 0,
			"cls": cls, "size": snappedf(randf_range(20.0, 80.0), 1.0),
			"decoded": false, "quality": 0.0, "corrupt": randf() < 0.2,
			"read": false, "media": int(it["id"]),
		})
		Net.next_id += 1
	Net.net_changed.emit()
	return it


func by_id(id: int) -> Dictionary:
	for it in items:
		if int(it["id"]) == id:
			return it
	return {}


func kind_of(id: int) -> String:
	return str(by_id(id).get("kind", ""))


func label_of(id: int) -> String:
	return str(by_id(id).get("label", "??"))


func of_kind(kind: String) -> Array:
	var out: Array = []
	for it in items:
		if str(it["kind"]) == kind:
			out.append(it)
	return out


func hdds() -> Array:
	return of_kind("hdd")


func tapes() -> Array:
	return of_kind("tape")


func cds() -> Array:
	return of_kind("cd")


func papers() -> Array:
	return of_kind("paper")


## --- capacity ------------------------------------------------------------------

func files_on(id: int) -> Array:
	var out: Array = []
	for f in Net.files:
		if int(f.get("media", -1)) == id:
			out.append(f)
	return out


func used_on(id: int) -> float:
	var used := 0.0
	for f in files_on(id):
		used += float(f["size"])
	return used


func free_on(id: int) -> float:
	var it := by_id(id)
	return maxf(float(it.get("cap", 0.0)) - used_on(id), 0.0)


func hdd_total() -> float:
	var total := 0.0
	for it in hdds():
		total += float(it["cap"])
	return total


func hdd_free() -> float:
	return maxf(hdd_total() - Net.disk_used(), 0.0)


## Target disk for an incoming write: most per-disk free space wins.
func pick_hdd() -> int:
	var best := -1
	var best_free := -1.0
	for it in hdds():
		var fr := free_on(int(it["id"]))
		if fr > best_free:
			best_free = fr
			best = int(it["id"])
	return best


func pick_tape(size: float) -> int:
	for it in tapes():
		if free_on(int(it["id"])) >= size:
			return int(it["id"])
	return -1


func anoms_on_hdd() -> int:
	var n := 0
	for f in Net.files:
		if str(f["cls"]) == "anom" and bool(f["decoded"]) and kind_of(int(f.get("media", -1))) == "hdd":
			n += 1
	return n


## --- jobs: streamer & burner ------------------------------------------------------

func dev_busy(dev: String) -> bool:
	for j in jobs:
		if str(j["dev"]) == dev:
			return true
	return false


func job_of(dev: String) -> Dictionary:
	for j in jobs:
		if str(j["dev"]) == dev:
			return j
	return {}


func _file(fid: int) -> Dictionary:
	for f in Net.files:
		if int(f["id"]) == fid:
			return f
	return {}


## hdd -> tape. Returns "" or a Loc error key.
func start_tape_in(f: Dictionary) -> String:
	if kind_of(int(f.get("media", -1))) != "hdd":
		return "t.not_on_hdd"
	if dev_busy("streamer"):
		return "t.streamer_busy"
	var to := pick_tape(float(f["size"]))
	if to < 0:
		return "t.no_tape_space"
	jobs.append({"dev": "streamer", "kind": "tape_in", "fid": int(f["id"]),
		"to": to, "got": 0.0, "size": float(f["size"])})
	Game.toast(Loc.t("t.job_tape_in", [f["name"], label_of(to)]))
	Sfx.play("whir")
	media_changed.emit()
	return ""


## tape -> hdd. Returns "" or a Loc error key.
func start_tape_out(f: Dictionary) -> String:
	if kind_of(int(f.get("media", -1))) != "tape":
		return "t.not_on_tape"
	if dev_busy("streamer"):
		return "t.streamer_busy"
	if hdd_free() < float(f["size"]):
		return "t.no_hdd_space"
	jobs.append({"dev": "streamer", "kind": "tape_out", "fid": int(f["id"]),
		"to": -1, "got": 0.0, "size": float(f["size"])})
	Game.toast(Loc.t("t.job_tape_out", [f["name"]]))
	Sfx.play("whir")
	media_changed.emit()
	return ""


## hdd -> CD-R (move; the blank goes into the tray now). "" or error key.
func start_burn(f: Dictionary) -> String:
	if kind_of(int(f.get("media", -1))) != "hdd":
		return "t.not_on_hdd"
	if dev_busy("burner"):
		return "t.burner_busy"
	if int(Game.inventory["cdr"]) <= 0:
		return "t.no_blank_cd"
	Game.inventory["cdr"] = int(Game.inventory["cdr"]) - 1
	jobs.append({"dev": "burner", "kind": "burn", "fid": int(f["id"]),
		"to": -1, "got": 0.0, "size": float(f["size"])})
	Game.toast(Loc.t("t.burn_start", [f["name"]]))
	Sfx.play("whir")
	media_changed.emit()
	return ""


func advance(m: float) -> void:
	# decoded anomalies on spinning storage leak line noise
	var leak := anoms_on_hdd()
	if leak > 0:
		Game.anomaly = minf(Game.anomaly + LEAK_PER_ANOM * leak * m, 100.0)
	if jobs.is_empty():
		return
	for j in jobs.duplicate():
		if not Game.power_on():
			# the streamer just stalls; a burn without power is a coaster
			if str(j["dev"]) == "burner":
				jobs.erase(j)
				Game.toast(Loc.t("t.burn_ruined"))
				Sfx.play("deny")
				media_changed.emit()
			continue
		var rate := BURN_RATE if str(j["dev"]) == "burner" else TAPE_RATE
		j["got"] = float(j["got"]) + rate * m
		if float(j["got"]) >= float(j["size"]):
			_finish_job(j)


func _finish_job(j: Dictionary) -> void:
	jobs.erase(j)
	var f := _file(int(j["fid"]))
	if f.is_empty():
		media_changed.emit()
		return
	match str(j["kind"]):
		"tape_in":
			f["media"] = int(j["to"])
			_dirty_heads(f)
			Game.toast(Loc.t("t.job_done_tape", [f["name"], label_of(int(j["to"]))]))
		"tape_out":
			var to := pick_hdd()
			if to < 0 or hdd_free() < float(f["size"]):
				Game.toast(Loc.t("t.no_hdd_space"))
			else:
				f["media"] = to
				_dirty_heads(f)
				Game.toast(Loc.t("t.job_done_hdd", [f["name"], label_of(to)]))
		"burn":
			var cd := _make("cd")
			f["media"] = int(cd["id"])
			Game.toast(Loc.t("t.burn_done", [f["name"], str(cd["label"])]))
	Sfx.play("beep")
	media_changed.emit()
	Net.net_changed.emit()


func _dirty_heads(f: Dictionary) -> void:
	head_dirt = minf(head_dirt + float(f["size"]) * DIRT_PER_MB, 100.0)
	if head_dirt >= 70.0 and not bool(f["corrupt"]) and randf() < 0.5:
		f["corrupt"] = true
		Game.toast(Loc.t("t.heads_dirty"))
		Sfx.play("deny")


func clean_heads() -> void:
	if int(Game.inventory["alcohol"]) <= 0:
		Game.toast(Loc.t("t.no_alcohol"))
		Sfx.play("deny")
		return
	if head_dirt < 5.0:
		Game.toast(Loc.t("t.heads_clean"))
		return
	Game.inventory["alcohol"] = int(Game.inventory["alcohol"]) - 1
	Game.advance_minutes(10.0)
	head_dirt = 0.0
	Game.toast(Loc.t("t.heads_cleaned"))
	Sfx.play("blip")
	media_changed.emit()


## --- printer / scanner (paper) ------------------------------------------------------

## Print a decoded capture to paper. A hardcopy is a lossy COPY: it sells
## for half value but the network never sees the transmission (no noise).
func print_file(f: Dictionary) -> String:
	if not bool(f["decoded"]):
		return "t.print_raw"
	if kind_of(int(f.get("media", -1))) != "hdd":
		return "t.not_on_hdd"
	if int(Game.inventory["paper"]) <= 0:
		return "t.no_paper"
	Game.inventory["paper"] = int(Game.inventory["paper"]) - 1
	Game.advance_minutes(PRINT_MIN)
	var val := 0
	match str(f["cls"]):
		"junk": val = 4
		"data", "anom": val = int(float(Net.value(f)) * 0.5)
		"echo": val = 30
	var it := _make("paper")
	it["cls"] = str(f["cls"])
	it["ti"] = int(f.get("ti", 0))
	it["val"] = val
	it["pk"] = ""
	it["pi"] = 0
	Sfx.play("print")
	if Game.is_night():
		Game.anomaly = minf(Game.anomaly + 1.5, 100.0)
		Game.toast(Loc.t("t.print_night", [str(it["label"])]))
	else:
		Game.toast(Loc.t("t.print_done", [str(it["label"])]))
	media_changed.emit()
	return ""


## The printer wakes up on its own (Events). Nobody sent anything.
func ghost_print() -> void:
	Sfx.play("print", -10.0)
	if int(Game.inventory["paper"]) <= 0:
		Game.toast(Loc.t("t.ghost_print_dry"))
		return
	Game.inventory["paper"] = int(Game.inventory["paper"]) - 1
	var it := _make("paper")
	it["cls"] = ""
	it["ti"] = 0
	it["val"] = 0
	it["pk"] = "page.lines"
	it["pi"] = Loc.rand_i("page.lines")
	Game.toast(Loc.t("t.ghost_print"))
	media_changed.emit()


func read_paper(p: Dictionary) -> String:
	if str(p.get("pk", "")) != "":
		Game.anomaly = minf(Game.anomaly + 1.0, 100.0)
		return Loc.t(str(p["pk"]), [int(p["pi"])])
	return Loc.t("title." + str(p["cls"]), [int(p.get("ti", 0))])


func shred_paper(p: Dictionary) -> void:
	items.erase(p)
	Game.toast(Loc.t("t.paper_shred"))
	Sfx.play("blip")
	media_changed.emit()


func sell_paper(p: Dictionary) -> int:
	var v := int(p.get("val", 0))
	items.erase(p)
	Game.earn_market(v)
	Game.toast(Loc.t("market.paper_sold", [str(p["label"]), v]))
	Sfx.play("beep")
	media_changed.emit()
	return v


## Feed a document found in the station to the scanner.
func scan_doc() -> void:
	if int(Game.inventory["docs"]) <= 0:
		Game.toast(Loc.t("t.no_docs"))
		Sfx.play("deny")
		return
	Game.inventory["docs"] = int(Game.inventory["docs"]) - 1
	Game.advance_minutes(SCAN_MIN)
	var roll := randf()
	if roll < 0.55:
		var hidden: Array = []
		for c in Net.carriers:
			if not bool(c["found"]) and not bool(c["locked"]):
				hidden.append(c)
		if not hidden.is_empty():
			var c: Dictionary = hidden[randi() % hidden.size()]
			c["found"] = true
			Game.toast(Loc.t("t.scan_carrier", [int(c["id"]),
				int(Vector2(c["pos"]).floor().x), int(Vector2(c["pos"]).floor().y)]))
			Sfx.play("beep")
			Net.net_changed.emit()
			return
		roll = 0.6  # nothing left to reveal — fall through to lore
	if roll < 0.85:
		Game.toast(Loc.t("t.scan_lore", [Loc.t("doc.lore", [Loc.rand_i("doc.lore")])]))
		Sfx.play("blip")
	else:
		Game.anomaly = minf(Game.anomaly + 2.0, 100.0)
		Game.toast(Loc.t("t.scan_burn"))
		Sfx.play("static")
	media_changed.emit()


## --- market: physical goods -----------------------------------------------------------

## What the collector pays for a burned disc. Decoded content only is worth
## real money; anomalies sealed on WORM media fetch a premium.
func cd_value(cd: Dictionary) -> int:
	var v := 0.0
	for f in files_on(int(cd["id"])):
		match str(f["cls"]):
			"junk": v += float(Net.value(f)) * 1.2
			"data": v += float(Net.value(f)) * 1.5
			"anom": v += float(Net.value(f)) * 2.0
			"echo": v += 40.0 if bool(f["decoded"]) else 10.0
	return int(v)


## Selling a disc through the hatch: the network never carries the data,
## so an anomaly costs +3 noise instead of the +10 an upload does.
func sell_cd(cd: Dictionary) -> int:
	var v := cd_value(cd)
	for f in files_on(int(cd["id"])):
		if str(f["cls"]) == "anom":
			Game.anomaly = minf(Game.anomaly + 3.0, 100.0)
		Net.files.erase(f)
	items.erase(cd)
	Game.earn_market(v)
	Game.toast(Loc.t("market.cd_sold", [str(cd["label"]), v]))
	Sfx.play("beep")
	media_changed.emit()
	Net.net_changed.emit()
	return v


## Called by Net.seal_file: an on-chain seal consumes the physical husk too.
func on_file_sealed(f: Dictionary) -> void:
	var it := by_id(int(f.get("media", -1)))
	if not it.is_empty() and str(it["kind"]) == "cd":
		items.erase(it)
		media_changed.emit()


## --- damage / decay ----------------------------------------------------------------------

## Called from Game._step while racks are >= 90°: hard disks cook.
func heat_stress(m: float) -> void:
	for it in hdds().duplicate():
		it["health"] = float(it["health"]) - HEAT_DAMAGE * m
		var h := float(it["health"])
		if h <= 0.0:
			_hdd_dies(it)
		elif h <= 20.0 and int(it.get("warn", 0)) < 2:
			it["warn"] = 2
			Game.toast(Loc.t("t.smart", [str(it["label"]), int(h)]))
			Sfx.play("alarm")
		elif h <= 40.0 and int(it.get("warn", 0)) < 1:
			it["warn"] = 1
			Game.toast(Loc.t("t.smart", [str(it["label"]), int(h)]))
			Sfx.play("alarm")


func _hdd_dies(it: Dictionary) -> void:
	var lost := files_on(int(it["id"]))
	for f in lost:
		for j in jobs.duplicate():
			if int(j["fid"]) == int(f["id"]):
				jobs.erase(j)
		Net.files.erase(f)
	items.erase(it)
	Game.toast(Loc.t("t.hdd_dead", [str(it["label"]), lost.size()]))
	Sfx.play("static")
	media_changed.emit()
	Net.net_changed.emit()


## Tapes age on the shelf; tired oxide drops bits.
func new_day() -> void:
	for it in tapes():
		it["health"] = maxf(float(it["health"]) - 1.0, 0.0)
		if float(it["health"]) < 25.0 and randf() < 0.2:
			for f in files_on(int(it["id"])):
				if not bool(f["corrupt"]):
					f["corrupt"] = true
					Game.toast(Loc.t("t.tape_rot", [str(it["label"])]))
					Net.net_changed.emit()
					break


## --- degausser -------------------------------------------------------------------------

## Bulk-erase a magnetic medium: everything on it is gone, anomalies with it.
func degauss(id: int) -> void:
	var it := by_id(id)
	if it.is_empty() or str(it["kind"]) not in ["hdd", "tape"]:
		return
	var wiped := files_on(id)
	for f in wiped:
		for j in jobs.duplicate():
			if int(j["fid"]) == int(f["id"]):
				jobs.erase(j)
		if str(f["cls"]) == "anom":
			Game.anomaly = maxf(Game.anomaly - 2.0, 0.0)
		Net.files.erase(f)
	if str(it["kind"]) == "tape":
		it["found"] = false
	Game.advance_minutes(DEGAUSS_MIN)
	Sfx.play("degauss")
	Game.toast(Loc.t("t.degauss", [str(it["label"]), wiped.size()]))
	if randf() < 0.25:
		Game.trip_breaker()
		Game.toast(Loc.t("t.degauss_trip"))
		Sfx.play("thud")
	media_changed.emit()
	Net.net_changed.emit()


## --- persistence ---------------------------------------------------------------------------

func get_state() -> Dictionary:
	return {"items": items, "jobs": jobs, "head_dirt": head_dirt,
		"next_id": next_id, "counters": counters}


## Old saves have no media block: give them one disk the size of the old
## flat disk_total, so nothing the player owned is lost.
func set_state(data: Dictionary, legacy_cap := HDD_START) -> void:
	if data.is_empty():
		_defaults(legacy_cap)
	else:
		items = data.get("items", [])
		jobs = data.get("jobs", [])
		head_dirt = float(data.get("head_dirt", 0.0))
		next_id = int(data.get("next_id", 1))
		var cnt: Dictionary = data.get("counters", {})
		for k in counters:
			counters[k] = int(cnt.get(k, 0))
		if hdds().is_empty() and items.is_empty():
			_defaults(legacy_cap)
	media_changed.emit()


func reset() -> void:
	_defaults(HDD_START)
	media_changed.emit()


func _defaults(cap: float) -> void:
	items = []
	jobs = []
	head_dirt = 0.0
	next_id = 1
	for k in counters:
		counters[k] = 0
	add_hdd(cap)
