extends Node
## Player settings — sound (master / sfx / ambience / mute) and display
## (fullscreen / vsync / brightness / mouse sensitivity). Persisted in the
## shared user://nocarrier_cfg.json (alongside the language, read-merge-write so
## neither owner clobbers the other). Audio routes through dedicated AudioServer
## buses so the sliders are a single source of truth.

signal changed

const CFG := "user://nocarrier_cfg.json"

# key -> [min, max, step, suffix]
const RANGES := {
	"master": [0, 100, 10, "%"],
	"sfx": [0, 100, 10, "%"],
	"amb": [0, 100, 10, "%"],
	"brightness": [50, 150, 10, "%"],
	"mouse": [25, 300, 25, "%"],
}
const TOGGLES := ["muted", "fullscreen", "vsync"]

var master := 80
var sfx := 90
var amb := 70
var muted := false
var fullscreen := false
var vsync := true
var brightness := 100
var mouse := 100


func _ready() -> void:
	var d := _read()
	master = int(d.get("master", master))
	sfx = int(d.get("sfx", sfx))
	amb = int(d.get("amb", amb))
	muted = bool(d.get("muted", muted))
	fullscreen = bool(d.get("fullscreen", fullscreen))
	vsync = bool(d.get("vsync", vsync))
	brightness = int(d.get("brightness", brightness))
	mouse = int(d.get("mouse", mouse))
	apply_all()


## --- accessors used by the settings screen ----------------------------------

func value_of(key: String) -> int:
	match key:
		"master": return master
		"sfx": return sfx
		"amb": return amb
		"brightness": return brightness
		"mouse": return mouse
	return 0


func is_on(key: String) -> bool:
	match key:
		"muted": return muted
		"fullscreen": return fullscreen
		"vsync": return vsync
	return false


func adjust(key: String, dir: int) -> void:
	if not RANGES.has(key):
		return
	var r: Array = RANGES[key]
	var v: int = clampi(value_of(key) + dir * int(r[2]), int(r[0]), int(r[1]))
	match key:
		"master": master = v
		"sfx": sfx = v
		"amb": amb = v
		"brightness": brightness = v
		"mouse": mouse = v
	apply_all()
	_save()


func flip(key: String) -> void:
	match key:
		"muted": muted = not muted
		"fullscreen": fullscreen = not fullscreen
		"vsync": vsync = not vsync
	apply_all()
	_save()


func mouse_factor() -> float:
	return float(mouse) / 100.0


func brightness_factor() -> float:
	return float(brightness) / 100.0


## --- application -------------------------------------------------------------

func apply_all() -> void:
	_apply_audio()
	_apply_display()
	changed.emit()


func _apply_audio() -> void:
	if DisplayServer.get_name() == "headless":
		return
	_ensure_buses()
	AudioServer.set_bus_volume_db(0, _db(master))
	AudioServer.set_bus_mute(0, muted)
	var si := AudioServer.get_bus_index("Sfx")
	if si != -1:
		AudioServer.set_bus_volume_db(si, _db(sfx))
	var ai := AudioServer.get_bus_index("Amb")
	if ai != -1:
		AudioServer.set_bus_volume_db(ai, _db(amb))


func _apply_display() -> void:
	if DisplayServer.get_name() == "headless":
		return
	DisplayServer.window_set_mode(
		DisplayServer.WINDOW_MODE_FULLSCREEN if fullscreen else DisplayServer.WINDOW_MODE_WINDOWED)
	DisplayServer.window_set_vsync_mode(
		DisplayServer.VSYNC_ENABLED if vsync else DisplayServer.VSYNC_DISABLED)


func _ensure_buses() -> void:
	for name in ["Sfx", "Amb"]:
		if AudioServer.get_bus_index(name) == -1:
			AudioServer.add_bus()
			var i := AudioServer.bus_count - 1
			AudioServer.set_bus_name(i, name)
			AudioServer.set_bus_send(i, "Master")


func _db(v: int) -> float:
	return -80.0 if v <= 0 else linear_to_db(float(v) / 100.0)


## --- persistence (shared file, merge-preserving) -----------------------------

func _read() -> Dictionary:
	if not FileAccess.file_exists(CFG):
		return {}
	var f := FileAccess.open(CFG, FileAccess.READ)
	if f == null:
		return {}
	var d: Variant = JSON.parse_string(f.get_as_text())
	return d if typeof(d) == TYPE_DICTIONARY else {}


func _save() -> void:
	var d := _read()
	d["master"] = master
	d["sfx"] = sfx
	d["amb"] = amb
	d["muted"] = muted
	d["fullscreen"] = fullscreen
	d["vsync"] = vsync
	d["brightness"] = brightness
	d["mouse"] = mouse
	var f := FileAccess.open(CFG, FileAccess.WRITE)
	if f:
		f.store_string(JSON.stringify(d))
