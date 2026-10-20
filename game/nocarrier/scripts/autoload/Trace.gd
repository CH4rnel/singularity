extends Node
## Reverse carrier: one spatial interference source at a time. Game advances
## its leak on the simulation clock; the field scene supplies real-time contact
## for grounding. No geometry, input or wall-clock timers live in save state.

const SPOTS := [
	Vector3(-4.0, 1.45, -1.78),
	Vector3(-10.0, 1.45, -8.72),
	Vector3(4.74, 1.45, -5.65),
	Vector3(11.4, 1.45, -8.72),
]
const ROTATIONS := [0.0, 0.0, -PI / 2.0, 0.0]
const HOLD_SECONDS := 3.5
const ENERGY_COST := 6.0
const NOISE_RELIEF := 8.0
const LEAK_PER_MIN := 0.6 / 60.0
const REACH := 2.8

var source := -1
var opened_day := 0
var closed_day := 0
var introduced := false
var resolved := 0
var lock_seconds := 0.0


func reset() -> void:
	source = -1
	opened_day = 0
	closed_day = 0
	introduced = false
	resolved = 0
	lock_seconds = 0.0


func ensure_source() -> void:
	if Game.over or source >= 0 or opened_day >= Game.day or closed_day >= Game.day:
		return
	# The first shift teaches the tool. Later sources require an unquiet line.
	if Game.day != 1 and Game.anomaly < 15.0:
		return
	source = (Game.day - 1) % SPOTS.size()
	opened_day = Game.day
	lock_seconds = 0.0
	Game.toast(Loc.t("trace.appeared"))


func advance(minutes: float) -> void:
	if Game.over:
		return
	ensure_source()
	if source >= 0:
		Game.anomaly = minf(100.0, Game.anomaly + LEAK_PER_MIN * maxf(minutes, 0.0))


func introduce() -> void:
	if introduced:
		return
	introduced = true
	Game.add_mail("from.maint", "trace.guide.subj", [], "trace.guide.body", [])
	Game.toast(Loc.t("trace.guide.short"))


func reception(origin: Vector3, forward: Vector3) -> Dictionary:
	if source < 0:
		return {"strength": 0.0, "distance": INF, "alignment": -1.0, "side": 0.0}
	var offset: Vector3 = SPOTS[source] - origin
	var distance := offset.length()
	var direction := offset.normalized()
	var alignment := forward.normalized().dot(direction)
	var proximity := 1.0 / (1.0 + distance * .16)
	var directionality := .06 + .94 * pow(maxf(alignment, 0.0), 3.0)
	var side := forward.normalized().cross(Vector3.UP).dot(direction)
	return {"strength": clampf(proximity * directionality, 0.0, 1.0),
		"distance": distance, "alignment": alignment, "side": side}


func update_lock(seconds: float, contact: bool, held: bool) -> bool:
	# Menu, sleep, source loss, looking away and releasing E all break the lock.
	if Game.paused or Game.asleep or Game.over or source < 0 \
		or not contact or not held or Game.energy < ENERGY_COST:
		lock_seconds = 0.0
		return false
	lock_seconds += clampf(seconds, 0.0, .1)
	if lock_seconds + .00001 < HOLD_SECONDS:
		return false
	var site := source
	source = -1
	closed_day = Game.day
	resolved += 1
	lock_seconds = 0.0
	Game.energy = maxf(0.0, Game.energy - ENERGY_COST)
	Game.anomaly = maxf(0.0, Game.anomaly - NOISE_RELIEF)
	# The maintenance log is always retained, even with no power/disk space.
	Game.add_mail("from.maint", "trace.log.subj", [resolved], "trace.log.body", [site + 1, Game.day])
	Game.toast(Loc.t("trace.closed", [int(NOISE_RELIEF), int(ENERGY_COST)]))
	Game.state_changed.emit()
	Sfx.play("trace_close", -12.0)
	return true


func get_state() -> Dictionary:
	return {"source": source, "opened_day": opened_day, "closed_day": closed_day,
		"introduced": introduced, "resolved": resolved}


func set_state(data: Dictionary) -> void:
	reset()
	# Old saves have no trace block. They get a source on the next game step.
	source = clampi(int(data.get("source", -1)), -1, SPOTS.size() - 1)
	opened_day = maxi(0, int(data.get("opened_day", 0)))
	closed_day = maxi(0, int(data.get("closed_day", 0)))
	introduced = bool(data.get("introduced", false))
	resolved = maxi(0, int(data.get("resolved", 0)))
