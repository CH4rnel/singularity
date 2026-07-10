class_name NcMenu
extends CanvasLayer
## Title screen and pause menu, keyboard-driven in the same text-mode style
## as the terminal. While the menu is visible the simulation is paused
## (Game.paused). The language toggle lives here too, so the very first
## screen a player sees can switch to their language.

## Set before a scene reload to jump straight into the game (used by
## "new contract" so the player doesn't land on the title twice).
static var skip_title := false

var mode := "title"        # "title" | "pause" | "settings"
var sel := 0
var sel_set := 0
var _prev_mode := "title"

# settings rows: heads are labels, the rest are selectable
const SET_ROWS := [
	{"kind": "head", "label": "set.head_sound"},
	{"kind": "slider", "key": "master", "label": "set.master"},
	{"kind": "slider", "key": "sfx", "label": "set.sfx"},
	{"kind": "slider", "key": "amb", "label": "set.amb"},
	{"kind": "toggle", "key": "muted", "label": "set.mute"},
	{"kind": "head", "label": "set.head_display"},
	{"kind": "toggle", "key": "fullscreen", "label": "set.fullscreen"},
	{"kind": "toggle", "key": "vsync", "label": "set.vsync"},
	{"kind": "slider", "key": "brightness", "label": "set.brightness"},
	{"kind": "slider", "key": "mouse", "label": "set.mouse"},
	{"kind": "back", "label": "set.back"},
]

var _rtl: RichTextLabel
var _bg: ColorRect


func _ready() -> void:
	layer = 12
	visible = false

	_bg = ColorRect.new()
	_bg.color = Color(0.005, 0.014, 0.009, 1.0)
	_bg.set_anchors_preset(Control.PRESET_FULL_RECT)
	add_child(_bg)

	_rtl = RichTextLabel.new()
	_rtl.bbcode_enabled = true
	_rtl.scroll_active = false
	var font := NcHud.mono_font()
	_rtl.add_theme_font_override("normal_font", font)
	_rtl.add_theme_font_override("bold_font", font)
	_rtl.add_theme_font_size_override("normal_font_size", 19)
	_rtl.add_theme_font_size_override("bold_font_size", 19)
	_rtl.set_anchors_preset(Control.PRESET_FULL_RECT)
	_rtl.offset_left = 140.0
	_rtl.offset_top = 90.0
	_rtl.offset_right = -80.0
	add_child(_rtl)

	var scanlines := ColorRect.new()
	scanlines.set_anchors_preset(Control.PRESET_FULL_RECT)
	scanlines.mouse_filter = Control.MOUSE_FILTER_IGNORE
	var sh := Shader.new()
	sh.code = """
shader_type canvas_item;
void fragment() {
	float s = step(0.5, fract(FRAGCOORD.y / 3.0)) * 0.12;
	COLOR = vec4(0.0, 0.01, 0.004, s + 0.03);
}
"""
	var mat := ShaderMaterial.new()
	mat.shader = sh
	scanlines.material = mat
	add_child(scanlines)

	Loc.lang_changed.connect(_render)

	# headless harness runs: never sit on a menu
	if DisplayServer.get_name() == "headless":
		Game.paused = false
		return
	if skip_title:
		skip_title = false
		Game.paused = false
		return
	if not Game.over:
		open_title()


func open_title() -> void:
	mode = "title"
	_bg.color.a = 1.0
	_open()


func open_pause() -> void:
	mode = "pause"
	_bg.color.a = 0.86
	_open()


func _open() -> void:
	sel = 0
	visible = true
	Game.paused = true
	Input.mouse_mode = Input.MOUSE_MODE_VISIBLE
	Sfx.play("beep", -16.0)
	_render()


func close_menu() -> void:
	visible = false
	Game.paused = false
	Input.mouse_mode = Input.MOUSE_MODE_CAPTURED
	Sfx.play("blip", -14.0)


## --- options -----------------------------------------------------------------

func _options() -> Array:
	var has_save := FileAccess.file_exists(Game.SAVE_PATH)
	var out: Array = []
	if mode == "title":
		if has_save:
			out.append(["continue", Loc.t("menu.continue", [Game.day])])
			out.append(["new", Loc.t("menu.new")])
		else:
			out.append(["continue", Loc.t("menu.begin")])
		out.append(["settings", Loc.t("menu.settings")])
		out.append(["lang", Loc.t("menu.lang")])
		if not OS.has_feature("web"):
			out.append(["quit", Loc.t("menu.quit")])
	else:
		out.append(["resume", Loc.t("menu.resume")])
		out.append(["save", Loc.t("menu.save")])
		out.append(["settings", Loc.t("menu.settings")])
		out.append(["lang", Loc.t("menu.lang")])
		out.append(["new", Loc.t("menu.new")])
		if not OS.has_feature("web"):
			out.append(["quit", Loc.t("menu.quit")])
	return out


func _run(id: String) -> void:
	match id:
		"continue", "resume":
			close_menu()
		"save":
			Game.save()
			Game.toast(Loc.t("sys.saved"))
			close_menu()
		"lang":
			Loc.toggle()
			Sfx.play("blip", -14.0)
		"new":
			NcMenu.skip_title = true
			Game.reset_all()
		"settings":
			_prev_mode = mode
			mode = "settings"
			sel_set = 0
			_render()
		"quit":
			get_tree().quit()


## --- settings sub-screen -----------------------------------------------------

func _sel_rows() -> Array:
	var out: Array = []
	for r in SET_ROWS:
		if r["kind"] != "head":
			out.append(r)
	return out


func _settings_input(key: InputEventKey) -> void:
	var rows := _sel_rows()
	sel_set = clampi(sel_set, 0, rows.size() - 1)
	var row: Dictionary = rows[sel_set]
	match key.physical_keycode:
		KEY_UP:
			sel_set = wrapi(sel_set - 1, 0, rows.size())
			Sfx.play("key", -20.0)
		KEY_DOWN:
			sel_set = wrapi(sel_set + 1, 0, rows.size())
			Sfx.play("key", -20.0)
		KEY_LEFT:
			_settings_change(row, -1)
		KEY_RIGHT:
			_settings_change(row, 1)
		KEY_ENTER, KEY_KP_ENTER:
			if row["kind"] == "back":
				mode = _prev_mode
			elif row["kind"] == "toggle":
				Settings.flip(row["key"])
			Sfx.play("blip", -16.0)
		KEY_ESCAPE:
			mode = _prev_mode
	_render()


func _settings_change(row: Dictionary, dir: int) -> void:
	if row["kind"] == "slider":
		Settings.adjust(row["key"], dir)
		Sfx.play("key", -20.0)
	elif row["kind"] == "toggle":
		Settings.flip(row["key"])
		Sfx.play("blip", -16.0)


## --- input / render ------------------------------------------------------------

func _unhandled_key_input(event: InputEvent) -> void:
	if not visible:
		return
	var key := event as InputEventKey
	if key == null or not key.pressed or key.echo:
		return
	get_viewport().set_input_as_handled()
	if mode == "settings":
		_settings_input(key)
		return
	var opts := _options()
	match key.physical_keycode:
		KEY_UP:
			sel = wrapi(sel - 1, 0, opts.size())
			Sfx.play("key", -20.0)
			_render()
		KEY_DOWN:
			sel = wrapi(sel + 1, 0, opts.size())
			Sfx.play("key", -20.0)
			_render()
		KEY_ENTER, KEY_KP_ENTER:
			_run(opts[clampi(sel, 0, opts.size() - 1)][0])
			if visible:
				_render()
		KEY_ESCAPE:
			if mode == "pause":
				close_menu()
		_:
			if key.physical_keycode >= KEY_1 and key.physical_keycode < KEY_1 + opts.size():
				_run(opts[key.physical_keycode - KEY_1][0])
				if visible:
					_render()


func _render() -> void:
	if not visible:
		return
	if mode == "settings":
		_render_settings()
		return
	var s := "[color=#2c5a3d]NODE-07 // 49406 // 0xC0FE[/color]\n\n"
	s += "[color=#6cffa0][font_size=64]NO CARRIER[/font_size][/color]\n"
	s += "[color=#4d8a63]%s[/color]\n\n" % Loc.t("menu.subtitle")
	if mode == "pause":
		s += "[color=#ffc252]%s[/color]\n" % Loc.t("menu.paused")
	s += "\n"
	var opts := _options()
	sel = clampi(sel, 0, opts.size() - 1)
	for i in opts.size():
		if i == sel:
			s += "[color=#6cffa0]  > %s[/color]\n" % opts[i][1]
		else:
			s += "[color=#4d8a63]    %s[/color]\n" % opts[i][1]
	s += "\n[color=#2c5a3d]%s[/color]\n" % Loc.t("menu.hint")
	s += "[color=#2c5a3d]%s[/color]\n" % Loc.t("menu.controls")
	_rtl.text = s


func _render_settings() -> void:
	sel_set = clampi(sel_set, 0, _sel_rows().size() - 1)
	var s := "[color=#6cffa0][font_size=44]%s[/font_size][/color]\n\n" % Loc.t("set.title")
	var si := -1
	for row in SET_ROWS:
		if row["kind"] == "head":
			s += "\n[color=#2c5a3d]%s[/color]\n" % Loc.t(row["label"])
			continue
		si += 1
		var selected := si == sel_set
		var name := Loc.t(row["label"])
		var val := ""
		match row["kind"]:
			"slider":
				val = _slider_str(row["key"])
			"toggle":
				val = Loc.t("set.on") if Settings.is_on(row["key"]) else Loc.t("set.off")
		if row["kind"] == "back":
			var line := "%s" % name
			s += ("[color=#6cffa0]  > %s[/color]\n" if selected else "[color=#4d8a63]    %s[/color]\n") % line
		else:
			var line := "%-22s %s" % [name, val]
			s += ("[color=#6cffa0]  > %s[/color]\n" if selected else "[color=#4d8a63]    %s[/color]\n") % line
	s += "\n[color=#2c5a3d]%s[/color]\n" % Loc.t("set.hint")
	_rtl.text = s


func _slider_str(key: String) -> String:
	var r: Array = Settings.RANGES[key]
	var v := Settings.value_of(key)
	var frac := float(v - int(r[0])) / float(int(r[1]) - int(r[0]))
	var cells := int(round(frac * 10.0))
	var bar := "█".repeat(cells) + "░".repeat(10 - cells)
	return "‹ %s %d%s ›" % [bar, v, r[3]]
