class_name NcHud
extends CanvasLayer
## Diegetic-ish text HUD: status, needs bars, warnings, interact hint, toast
## feed, a tiny numbered modal, full-screen fades, the NO CARRIER takeover
## and the game-over screens. Everything is built in _ready.

const GREEN := Color(0.42, 1.0, 0.62)
const DIM := Color(0.30, 0.55, 0.40)
const AMBER := Color(1.0, 0.76, 0.32)
const RED := Color(1.0, 0.36, 0.32)

var modal_open := false
var over_shown := false

var _status: RichTextLabel
var _needs: RichTextLabel
var _warn: RichTextLabel
var _hint: Label
var _toasts: VBoxContainer
var _fade: ColorRect
var _nc: Control
var _nc_label: Label
var _over: RichTextLabel
var _modal: PanelContainer
var _modal_text: RichTextLabel
var _modal_cb: Callable
var _modal_count := 0
var _accum := 0.0


static func mono_font() -> SystemFont:
	var f := SystemFont.new()
	f.font_names = PackedStringArray(["DejaVu Sans Mono", "Liberation Mono", "Consolas", "Menlo", "monospace"])
	return f


func _ready() -> void:
	layer = 10
	var font := mono_font()

	_status = _rtl(font, 15)
	_status.position = Vector2(14, 10)
	_status.size = Vector2(620, 150)
	add_child(_status)

	_warn = _rtl(font, 15)
	_warn.anchor_left = 1.0
	_warn.anchor_right = 1.0
	_warn.offset_left = -430.0
	_warn.offset_right = -14.0
	_warn.offset_top = 10.0
	_warn.offset_bottom = 220.0
	add_child(_warn)

	_needs = _rtl(font, 15)
	_needs.anchor_top = 1.0
	_needs.anchor_bottom = 1.0
	_needs.offset_left = 14.0
	_needs.offset_right = 420.0
	_needs.offset_top = -86.0
	_needs.offset_bottom = -10.0
	add_child(_needs)

	_hint = Label.new()
	_hint.add_theme_font_override("font", font)
	_hint.add_theme_font_size_override("font_size", 17)
	_hint.add_theme_color_override("font_color", GREEN)
	_hint.horizontal_alignment = HORIZONTAL_ALIGNMENT_CENTER
	_hint.anchor_left = 0.5
	_hint.anchor_right = 0.5
	_hint.anchor_top = 1.0
	_hint.anchor_bottom = 1.0
	_hint.offset_left = -420.0
	_hint.offset_right = 420.0
	_hint.offset_top = -78.0
	_hint.offset_bottom = -52.0
	_hint.mouse_filter = Control.MOUSE_FILTER_IGNORE
	add_child(_hint)

	_toasts = VBoxContainer.new()
	_toasts.anchor_left = 0.5
	_toasts.anchor_right = 0.5
	_toasts.anchor_top = 1.0
	_toasts.anchor_bottom = 1.0
	_toasts.offset_left = -460.0
	_toasts.offset_right = 460.0
	_toasts.offset_top = -300.0
	_toasts.offset_bottom = -92.0
	_toasts.alignment = BoxContainer.ALIGNMENT_END
	_toasts.mouse_filter = Control.MOUSE_FILTER_IGNORE
	add_child(_toasts)

	_fade = ColorRect.new()
	_fade.color = Color(0, 0, 0, 0)
	_fade.set_anchors_preset(Control.PRESET_FULL_RECT)
	_fade.mouse_filter = Control.MOUSE_FILTER_IGNORE
	add_child(_fade)

	_nc = Control.new()
	_nc.set_anchors_preset(Control.PRESET_FULL_RECT)
	_nc.visible = false
	_nc.mouse_filter = Control.MOUSE_FILTER_IGNORE
	var nc_bg := ColorRect.new()
	nc_bg.color = Color(0.0, 0.005, 0.0, 1.0)
	nc_bg.set_anchors_preset(Control.PRESET_FULL_RECT)
	_nc.add_child(nc_bg)
	_nc_label = Label.new()
	_nc_label.text = "NO CARRIER"
	_nc_label.add_theme_font_override("font", font)
	_nc_label.add_theme_font_size_override("font_size", 72)
	_nc_label.add_theme_color_override("font_color", GREEN)
	_nc_label.set_anchors_preset(Control.PRESET_FULL_RECT)
	_nc_label.horizontal_alignment = HORIZONTAL_ALIGNMENT_CENTER
	_nc_label.vertical_alignment = VERTICAL_ALIGNMENT_CENTER
	_nc.add_child(_nc_label)
	add_child(_nc)

	_over = _rtl(font, 19)
	_over.set_anchors_preset(Control.PRESET_FULL_RECT)
	_over.visible = false
	var over_style := StyleBoxFlat.new()
	over_style.bg_color = Color(0.0, 0.005, 0.0, 1.0)
	over_style.content_margin_left = 200.0
	over_style.content_margin_top = 160.0
	over_style.content_margin_right = 200.0
	_over.add_theme_stylebox_override("normal", over_style)
	add_child(_over)

	_modal = PanelContainer.new()
	var style := StyleBoxFlat.new()
	style.bg_color = Color(0.02, 0.05, 0.03, 0.96)
	style.border_color = DIM
	style.set_border_width_all(1)
	style.set_content_margin_all(18.0)
	_modal.add_theme_stylebox_override("panel", style)
	_modal.anchor_left = 0.5
	_modal.anchor_right = 0.5
	_modal.anchor_top = 0.5
	_modal.anchor_bottom = 0.5
	_modal.visible = false
	_modal_text = _rtl(font, 17)
	_modal_text.fit_content = true
	_modal_text.custom_minimum_size = Vector2(440, 0)
	_modal.add_child(_modal_text)
	add_child(_modal)

	Game.toasted.connect(toast)
	Game.game_over.connect(_show_over)


func _rtl(font: Font, fsize: int) -> RichTextLabel:
	var r := RichTextLabel.new()
	r.bbcode_enabled = true
	r.scroll_active = false
	r.mouse_filter = Control.MOUSE_FILTER_IGNORE
	r.add_theme_font_override("normal_font", font)
	r.add_theme_font_override("bold_font", font)
	r.add_theme_font_override("mono_font", font)
	r.add_theme_font_size_override("normal_font_size", fsize)
	r.add_theme_font_size_override("bold_font_size", fsize)
	r.add_theme_font_size_override("mono_font_size", fsize)
	return r


func _process(delta: float) -> void:
	_accum += delta
	if _accum < 0.15:
		return
	_accum = 0.0
	_refresh()


func _refresh() -> void:
	var noise_cells := int(ceil(Game.anomaly / 20.0))
	var noise := ""
	for i in 5:
		noise += "█" if i < noise_cells else "·"
	_status.text = "[color=#6cffa0]NODE-07[/color][color=#4d8a63]  day %d  %s\ncredits %d   mail %s\nline noise [%s][/color]" % [
		Game.day, Game.fmt_clock(), Game.money,
		("%d unread" % Game.unread_mail()) if Game.unread_mail() > 0 else "-",
		noise,
	]

	_needs.text = "[color=#4d8a63]energy [color=#%s]%s[/color]\nfood   [color=#%s]%s[/color][/color]" % [
		_bar_color(Game.energy), _bar(Game.energy),
		_bar_color(Game.hunger), _bar(Game.hunger),
	]

	var warns: Array = []
	if not Game.power_on():
		warns.append("!! NO POWER")
	elif Game.fuel <= 20.0:
		warns.append("! fuel %d%%" % int(Game.fuel))
	if Game.heat >= 85.0:
		warns.append("!! RACK OVERHEAT %d°" % int(Game.heat))
	elif Game.coolant <= 15.0:
		warns.append("! coolant %d%%" % int(Game.coolant))
	if Net.disk_total > 0.0 and Net.disk_used() / Net.disk_total > 0.9:
		warns.append("! disk %d/%d MB" % [int(Net.disk_used()), int(Net.disk_total)])
	if Game.bin >= Game.BIN_MAX:
		warns.append("! the bin overflows")
	if Game.carrying_trash:
		warns.append("• holding trash bag")
	if Game.day == Game.quota_due_day() - 1:
		warns.append("! quota settles tomorrow: %d/%d" % [Game.sold_since_quota, Game.quota_needed()])
	if Game.strikes > 0:
		warns.append("! strikes %d/3" % Game.strikes)
	_warn.text = "[right][color=#ff5c52]%s[/color][/right]" % "\n".join(warns)


func _bar(v: float) -> String:
	var cells := int(round(clampf(v, 0.0, 100.0) / 10.0))
	var s := ""
	for i in 10:
		s += "█" if i < cells else "░"
	return s + " %3d" % int(v)


func _bar_color(v: float) -> String:
	if v <= 20.0:
		return "ff5c52"
	if v <= 45.0:
		return "ffc252"
	return "6cffa0"


func set_hint(text: String) -> void:
	_hint.text = text


func toast(msg: String) -> void:
	var l := Label.new()
	l.text = "> " + msg
	l.add_theme_font_override("font", mono_font())
	l.add_theme_font_size_override("font_size", 16)
	l.add_theme_color_override("font_color", Color(0.75, 1.0, 0.82))
	l.horizontal_alignment = HORIZONTAL_ALIGNMENT_CENTER
	_toasts.add_child(l)
	while _toasts.get_child_count() > 6:
		_toasts.get_child(0).free()
	var tw := l.create_tween()
	tw.tween_interval(4.2)
	tw.tween_property(l, "modulate:a", 0.0, 0.8)
	tw.tween_callback(l.queue_free)


## --- modal -------------------------------------------------------------------

func modal(title: String, options: Array, cb: Callable) -> void:
	_modal_cb = cb
	_modal_count = options.size()
	var lines := "[color=#6cffa0]%s[/color]\n\n" % title
	for i in options.size():
		lines += "[color=#4d8a63][%d][/color] [color=#b8ffcf]%s[/color]\n" % [i + 1, options[i]]
	lines += "\n[color=#4d8a63][esc] never mind[/color]"
	_modal_text.text = lines
	_modal.visible = true
	modal_open = true


func _close_modal() -> void:
	_modal.visible = false
	modal_open = false


func _unhandled_key_input(event: InputEvent) -> void:
	var key := event as InputEventKey
	if key == null or not key.pressed or key.echo:
		return
	if over_shown:
		if key.physical_keycode == KEY_R:
			Game.reset_all()
		get_viewport().set_input_as_handled()
		return
	if not modal_open:
		return
	if key.physical_keycode == KEY_ESCAPE:
		_close_modal()
	elif key.physical_keycode >= KEY_1 and key.physical_keycode < KEY_1 + _modal_count:
		var idx := key.physical_keycode - KEY_1
		_close_modal()
		if _modal_cb.is_valid():
			_modal_cb.call(idx)
	get_viewport().set_input_as_handled()


## --- overlays -----------------------------------------------------------------

func fade_to(alpha: float, dur: float) -> void:
	var tw := create_tween()
	tw.tween_property(_fade, "color:a", alpha, dur)
	await tw.finished


func flash_nocarrier(dur := 0.35, text := "NO CARRIER") -> void:
	_nc_label.text = text
	_nc.visible = true
	Sfx.play("static", -12.0)
	await get_tree().create_timer(dur).timeout
	if not over_shown:
		_nc.visible = false


func _show_over(kind: String) -> void:
	over_shown = true
	_nc.visible = false
	_modal.visible = false
	modal_open = false
	Input.mouse_mode = Input.MOUSE_MODE_VISIBLE
	var title: String
	var body: String
	if kind == "terminated":
		title = "CONTRACT TERMINATED"
		body = "three missed settlements. the OPERATOR thanks you for your\nservice and reminds you that the door was never locked for you."
	else:
		title = "NO CARRIER"
		body = "NODE-07 fell out of the mesh at %s, day %d.\nnobody logged the disconnect. nobody was left to." % [Game.fmt_clock(), Game.day]
	_over.text = ("[color=#6cffa0][font_size=44]%s[/font_size][/color]\n\n" +
		"[color=#b8ffcf]%s[/color]\n\n" +
		"[color=#4d8a63]days on shift     %d\ncredits earned    %d\ncaptures decoded  %d\nnonstandard held  %d\n\n[R] wipe the node, sign a new contract[/color]") % [
		title, body, Game.day, Game.lifetime_earned,
		int(Game.stats["decoded"]), int(Game.stats["anomalies"]),
	]
	_over.visible = true
	Sfx.set_hum(false)
	Sfx.play("static", -6.0)
