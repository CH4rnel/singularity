class_name NcTerminal
extends CanvasLayer
## The NODE-07 terminal: a keyboard-driven text-mode UI rendered into one
## RichTextLabel, with a scanline overlay. Apps: SCAN (triangulate carriers),
## TAPS (downloads), FILES (decode/purge/read), MARKET (sell), SHOP (order),
## MAIL, SYS. Opening/closing is signalled so Main can freeze the player.

signal opened
signal closed

const APPS := ["SCAN", "TAPS", "FILES", "MARKET", "SHOP", "MAIL", "SYS"]
const ARROWS := ["→", "↗", "↑", "↖", "←", "↙", "↓", "↘"]

const C_DIM := "4d8a63"
const C_HI := "6cffa0"
const C_VAL := "b8ffcf"
const C_WARN := "ffc252"
const C_RED := "ff5c52"
const C_CYAN := "52d8ff"
const C_MAG := "ff6cf8"

var app := "SCAN"
var cursor := Vector2i(8, 5)
var sel := 0
var mail_view := -1
var decode := {}          # {"f": file, "puzzle": {...}} while a decode runs
var last_ping := {}
var flash := ""           # one-shot status line shown on next render

var _rtl: RichTextLabel
var _wipe_armed := false
var _accum := 0.0


func _ready() -> void:
	layer = 5
	visible = false

	var bg := ColorRect.new()
	bg.color = Color(0.012, 0.028, 0.018, 1.0)
	bg.set_anchors_preset(Control.PRESET_FULL_RECT)
	add_child(bg)

	_rtl = RichTextLabel.new()
	_rtl.bbcode_enabled = true
	_rtl.scroll_active = false
	var font := NcHud.mono_font()
	_rtl.add_theme_font_override("normal_font", font)
	_rtl.add_theme_font_override("bold_font", font)
	_rtl.add_theme_font_size_override("normal_font_size", 17)
	_rtl.add_theme_font_size_override("bold_font_size", 17)
	_rtl.set_anchors_preset(Control.PRESET_FULL_RECT)
	_rtl.offset_left = 36.0
	_rtl.offset_top = 26.0
	_rtl.offset_right = -36.0
	_rtl.offset_bottom = -26.0
	add_child(_rtl)

	var scan := ColorRect.new()
	scan.set_anchors_preset(Control.PRESET_FULL_RECT)
	scan.mouse_filter = Control.MOUSE_FILTER_IGNORE
	var sh := Shader.new()
	sh.code = """
shader_type canvas_item;
void fragment() {
	float s = step(0.5, fract(FRAGCOORD.y / 3.0)) * 0.13;
	COLOR = vec4(0.0, 0.015, 0.005, s + 0.04);
}
"""
	var mat := ShaderMaterial.new()
	mat.shader = sh
	scan.material = mat
	add_child(scan)

	Net.net_changed.connect(_maybe_render)
	Game.state_changed.connect(_maybe_render)
	Game.mail_arrived.connect(_maybe_render)


func _process(delta: float) -> void:
	if not visible:
		return
	_accum += delta
	if _accum >= 0.5:
		_accum = 0.0
		render()


func _maybe_render() -> void:
	if visible:
		render()


func open() -> void:
	visible = true
	Sfx.play("beep", -14.0)
	render()
	opened.emit()


func close() -> void:
	visible = false
	decode = {}
	mail_view = -1
	_wipe_armed = false
	closed.emit()


## --- input --------------------------------------------------------------------

func _unhandled_key_input(event: InputEvent) -> void:
	if not visible or Game.over:
		return
	var key := event as InputEventKey
	if key == null or not key.pressed or key.echo:
		return
	get_viewport().set_input_as_handled()
	var code := key.physical_keycode
	Sfx.play("key", -20.0)

	if not decode.is_empty():
		_decode_input(code)
		render()
		return

	if code == KEY_ESCAPE:
		if mail_view >= 0:
			mail_view = -1
			render()
		else:
			close()
		return

	if code >= KEY_1 and code <= KEY_7 and mail_view < 0:
		var next: String = APPS[code - KEY_1]
		if next != app:
			app = next
			sel = 0
			_wipe_armed = false
		render()
		return

	match app:
		"SCAN": _scan_input(code)
		"TAPS": _taps_input(code)
		"FILES": _files_input(code)
		"MARKET": _market_input(code)
		"SHOP": _shop_input(code)
		"MAIL": _mail_input(code)
		"SYS": _sys_input(code)
	render()


func _nav(code: int, count: int) -> void:
	if count <= 0:
		sel = 0
		return
	if code == KEY_UP:
		sel = wrapi(sel - 1, 0, count)
	elif code == KEY_DOWN:
		sel = wrapi(sel + 1, 0, count)
	sel = clampi(sel, 0, count - 1)


func _scan_input(code: int) -> void:
	match code:
		KEY_LEFT: cursor.x = wrapi(cursor.x - 1, 0, Net.GRID_W)
		KEY_RIGHT: cursor.x = wrapi(cursor.x + 1, 0, Net.GRID_W)
		KEY_UP: cursor.y = wrapi(cursor.y - 1, 0, Net.GRID_H)
		KEY_DOWN: cursor.y = wrapi(cursor.y + 1, 0, Net.GRID_H)
		KEY_ENTER, KEY_KP_ENTER:
			last_ping = Net.ping(cursor)
			last_ping["cell"] = cursor
		KEY_L:
			var c := Net.found_at(cursor)
			if c.is_empty():
				flash = "no resolved carrier under the cursor"
				Sfx.play("deny")
			else:
				var err := Net.lock(c)
				if err == "":
					flash = "tap locked on carrier #%d" % int(c["id"])
				else:
					flash = err
					Sfx.play("deny")


func _taps_input(code: int) -> void:
	_nav(code, Net.taps.size())
	if code == KEY_K and sel < Net.taps.size() and Net.taps[sel] != null:
		Net.kill_tap(sel)
		flash = "tap %d released" % (sel + 1)


func _files_input(code: int) -> void:
	_nav(code, Net.files.size())
	if Net.files.is_empty():
		return
	sel = clampi(sel, 0, Net.files.size() - 1)
	var f: Dictionary = Net.files[sel]
	match code:
		KEY_D:
			if bool(f["decoded"]):
				flash = "already decoded"
			else:
				decode = {"f": f, "puzzle": Net.make_puzzle(f)}
		KEY_P:
			Net.purge(f)
			flash = "%s purged" % f["name"]
			sel = 0
		KEY_R:
			if f["cls"] == "echo" and bool(f["decoded"]):
				flash = "you listen: " + Net.read_echo(f).replace("\n", " ")
			else:
				flash = "nothing in there wants to be read"


func _market_input(code: int) -> void:
	var goods := _sellables()
	_nav(code, goods.size())
	if goods.is_empty():
		return
	sel = clampi(sel, 0, goods.size() - 1)
	var f: Dictionary = goods[sel]
	match code:
		KEY_ENTER, KEY_KP_ENTER:
			var v := Net.sell(f)
			flash = "delivered %s for %d crd" % [f["name"], v]
			Sfx.play("beep")
			sel = 0
		KEY_A:
			if Net.archive(f):
				flash = "%s archived to tape (+60 crd)" % f["name"]
				sel = 0
			else:
				flash = "archive needs a cold tape and a NONSTANDARD capture"
				Sfx.play("deny")


func _shop_input(code: int) -> void:
	_nav(code, Game.SHOP.size())
	if code == KEY_ENTER or code == KEY_KP_ENTER:
		Game.buy(str(Game.SHOP[sel]["id"]))


func _mail_input(code: int) -> void:
	if mail_view >= 0:
		return
	_nav(code, Game.mails.size())
	if (code == KEY_ENTER or code == KEY_KP_ENTER) and not Game.mails.is_empty():
		mail_view = sel
		Game.mails[sel]["read"] = true


func _sys_input(code: int) -> void:
	match code:
		KEY_S:
			Game.save()
			flash = "state written to disk"
			Sfx.play("blip")
		KEY_W:
			if _wipe_armed:
				Game.reset_all()
			else:
				_wipe_armed = true
				flash = "press W again to WIPE the node and restart"


func _decode_input(code: int) -> void:
	if code == KEY_ESCAPE:
		decode = {}
		flash = "decode aborted"
		return
	if code >= KEY_1 and code <= KEY_4:
		var pick := code - KEY_1
		var f: Dictionary = decode["f"]
		var ok: bool = pick == int(decode["puzzle"]["correct"])
		Net.finish_decode(f, ok)
		if ok:
			flash = "CLEAN DECODE: %s — %s" % [f["name"], f["title"]]
			Sfx.play("beep")
		else:
			flash = "framing error — partial decode of %s" % f["name"]
			Sfx.play("deny")
		decode = {}


## --- rendering ------------------------------------------------------------------

func render() -> void:
	var out := _header()
	if not decode.is_empty():
		out += _render_decode()
	else:
		match app:
			"SCAN": out += _render_scan()
			"TAPS": out += _render_taps()
			"FILES": out += _render_files()
			"MARKET": out += _render_market()
			"SHOP": out += _render_shop()
			"MAIL": out += _render_mail()
			"SYS": out += _render_sys()
	if flash != "":
		out += "\n[color=#%s]» %s[/color]" % [C_WARN, flash]
		flash = ""
	_rtl.text = out


func _header() -> String:
	var tabs := ""
	for i in APPS.size():
		var name: String = APPS[i]
		if name == "MAIL" and Game.unread_mail() > 0:
			name += "(%d)" % Game.unread_mail()
		if APPS[i] == app:
			tabs += "[color=#%s][%d]%s[/color]  " % [C_HI, i + 1, name]
		else:
			tabs += "[color=#%s][%d]%s[/color]  " % [C_DIM, i + 1, name]
	return ("[color=#%s]NODE-07 // %s // day %d %s // %d crd[/color]\n%s\n" +
		"[color=#%s]%s[/color]\n") % [
		C_HI, "TERMINAL", Game.day, Game.fmt_clock(), Game.money, tabs,
		C_DIM, "─".repeat(72)]


func _render_scan() -> String:
	var locked_cells := {}
	for t in Net.taps:
		if t != null:
			var c := Net.carrier_by_id(int(t["cid"]))
			if not c.is_empty():
				locked_cells[Vector2i(Vector2(c["pos"]).floor())] = true
	var found_cells := {}
	for c in Net.carriers:
		if c["found"] and not c["locked"]:
			found_cells[Vector2i(Vector2(c["pos"]).floor())] = c

	var s := ""
	for y in Net.GRID_H:
		var row := "  "
		for x in Net.GRID_W:
			var cell := Vector2i(x, y)
			var glyph: String
			var col := C_DIM
			if locked_cells.has(cell):
				glyph = "▣"
				col = C_CYAN
			elif found_cells.has(cell):
				glyph = "◆"
				col = C_MAG
			elif Net.scan_marks.has(cell):
				var st := int(Net.scan_marks[cell])
				glyph = str(clampi(st / 10, 0, 9))
				col = C_DIM if st < 35 else (C_WARN if st < 70 else C_HI)
			else:
				glyph = "·"
			var chunk := "[color=#%s]%s[/color] " % [col, glyph]
			if cell == cursor:
				chunk = "[bgcolor=#1e4630]%s[/bgcolor]" % chunk
			row += chunk
		s += row + "\n"

	s += "\n[color=#%s]arrows: move   enter: ping (4 min)   L: lock tap on ◆[/color]\n" % C_DIM
	if not last_ping.is_empty():
		var line := "ping (%d,%d): strength %d" % [last_ping["cell"].x, last_ping["cell"].y, int(last_ping["strength"])]
		if last_ping.has("found"):
			line += "  — CARRIER RESOLVED #%d" % int(last_ping["found"]["id"])
		elif last_ping.has("dir"):
			var d: Vector2 = last_ping["dir"]
			var idx := wrapi(int(round(atan2(-d.y, d.x) / (PI / 4.0))), 0, 8)
			line += "  bearing %s" % ARROWS[idx]
		s += "[color=#%s]%s[/color]\n" % [C_VAL, line]
	var found_any := false
	for c in Net.carriers:
		if c["found"] and not c["locked"]:
			if not found_any:
				s += "\n[color=#%s]resolved carriers:[/color]\n" % C_DIM
				found_any = true
			s += "[color=#%s] ◆ #%d  sig %s  ~%d MB  @(%d,%d)[/color]\n" % [
				C_MAG, int(c["id"]), c["sig"], int(snappedf(float(c["size"]), 10.0)),
				int(Vector2(c["pos"]).floor().x), int(Vector2(c["pos"]).floor().y)]
	if Game.is_night():
		s += "\n[color=#%s]▲ 00:00–06:00: the noise floor is listening back[/color]" % C_RED
	return s


func _render_taps() -> String:
	var s := "[color=#%s]tap slots (%d) — line rate %.1f MB/min%s[/color]\n\n" % [
		C_DIM, Net.tap_slots, Net.download_rate(),
		"" if Game.power_on() else "  [color=#" + C_RED + "]— NO POWER, stalled[/color]"]
	for i in Net.taps.size():
		var mark := ">" if i == sel else " "
		var t: Variant = Net.taps[i]
		if t == null:
			s += "[color=#%s]%s slot %d: idle[/color]\n" % [C_DIM, mark, i + 1]
			continue
		var c := Net.carrier_by_id(int(t["cid"]))
		if c.is_empty():
			continue
		var got := float(t["got"])
		var total := float(c["size"])
		var cells := int(round(got / total * 12.0))
		var bar := "▓".repeat(cells) + "░".repeat(12 - cells)
		var eta := int(ceil((total - got) / Net.download_rate()))
		var warn := "  [color=#" + C_RED + "]DEGRADING[/color]" if bool(t["corrupt"]) else ""
		s += "[color=#%s]%s slot %d: #%d %s [%s] %d/%d MB  eta %dmin%s[/color]\n" % [
			C_VAL, mark, i + 1, int(c["id"]), c["sig"], bar, int(got), int(total), eta, warn]
	s += "\n[color=#%s]disk %d / %d MB   K: release selected tap[/color]\n" % [
		C_DIM, int(Net.disk_used()), int(Net.disk_total)]
	s += "[color=#%s]heat %d°  coolant %d%%  — heavy taps cook the racks[/color]" % [
		C_WARN if Game.heat >= 80.0 else C_DIM, int(Game.heat), int(Game.coolant)]
	return s


func _cls_label(f: Dictionary) -> String:
	if not bool(f["decoded"]):
		return "[color=#%s]raw[/color]" % C_DIM
	match f["cls"]:
		"junk": return "[color=#%s]%s[/color]" % [C_DIM, f["title"]]
		"data": return "[color=#%s]%s[/color]" % [C_VAL, f["title"]]
		"anom": return "[color=#%s]%s[/color]" % [C_MAG, f["title"]]
		"echo": return "[color=#%s]%s[/color]" % [C_CYAN, f["title"]]
	return ""


func _render_files() -> String:
	var s := "[color=#%s]captures on disk (%d / %d MB)[/color]\n\n" % [
		C_DIM, int(Net.disk_used()), int(Net.disk_total)]
	if Net.files.is_empty():
		return s + "[color=#%s]  nothing. the disk hums to itself.[/color]" % C_DIM
	sel = clampi(sel, 0, Net.files.size() - 1)
	for i in Net.files.size():
		var f: Dictionary = Net.files[i]
		var mark := ">" if i == sel else " "
		var corrupt := "  [color=#" + C_RED + "]CORRUPT[/color]" if bool(f["corrupt"]) else ""
		s += "[color=#%s]%s %s  %3d MB  [/color]%s%s\n" % [
			C_VAL, mark, f["name"], int(f["size"]), _cls_label(f), corrupt]
	s += "\n[color=#%s]D: decode (10 min)   P: purge   R: read (decoded echo)[/color]" % C_DIM
	return s


func _sellables() -> Array:
	var out: Array = []
	for f in Net.files:
		if Net.sellable(f):
			out.append(f)
	return out


func _render_market() -> String:
	var s := "[color=#%s]uplink to the OPERATOR — quota %d / %d crd, settles day %d[/color]\n\n" % [
		C_WARN if Game.sold_since_quota < Game.quota_needed() else C_HI,
		Game.sold_since_quota, Game.quota_needed(), Game.quota_due_day()]
	var goods := _sellables()
	if goods.is_empty():
		return s + "[color=#%s]  nothing sellable. decode some captures first.[/color]" % C_DIM
	sel = clampi(sel, 0, goods.size() - 1)
	for i in goods.size():
		var f: Dictionary = goods[i]
		var mark := ">" if i == sel else " "
		var note := ""
		if f["cls"] == "anom":
			note = "  [color=#%s]hot data — pays well; something will notice[/color]" % C_RED
		s += "[color=#%s]%s %s  %4d crd  [/color]%s%s\n" % [
			C_VAL, mark, f["name"], Net.value(f), _cls_label(f), note]
	s += "\n[color=#%s]enter: sell   A: archive NONSTANDARD to cold tape (safer, 60 crd)[/color]" % C_DIM
	return s


func _render_shop() -> String:
	var s := "[color=#%s]procurement — deliveries reach the hatch in ~2h[/color]\n\n" % C_DIM
	for i in Game.SHOP.size():
		var it: Dictionary = Game.SHOP[i]
		var mark := ">" if i == sel else " "
		var own := ""
		var id := str(it["id"])
		if Game.inventory.has(id):
			own = "  (have %d)" % int(Game.inventory[id])
		elif id == "modem":
			own = "  (lv%d)" % Net.modem_lvl
		elif id == "filter":
			own = "  (lv%d)" % Net.filter_lvl
		elif id == "tapslot":
			own = "  (%d slots)" % Net.tap_slots
		elif id == "disk":
			own = "  (%d MB)" % int(Net.disk_total)
		s += "[color=#%s]%s %-22s %4d crd  [color=#%s]%s[/color]%s[/color]\n" % [
			C_VAL, mark, it["name"], int(it["price"]), C_DIM, it["desc"], own]
	if not Game.deliveries.is_empty():
		s += "\n[color=#%s]in transit:[/color]\n" % C_DIM
		for d in Game.deliveries:
			var eta := int(ceil((float(d["at"]) - Game.abs_min())))
			var names: Array = []
			for id in d["items"]:
				names.append(str(id))
			s += "[color=#%s]  %s — %d min[/color]\n" % [C_DIM, ", ".join(names), maxi(eta, 0)]
	s += "\n[color=#%s]enter: order[/color]" % C_DIM
	return s


func _render_mail() -> String:
	if mail_view >= 0 and mail_view < Game.mails.size():
		var m: Dictionary = Game.mails[mail_view]
		return ("[color=#%s]from: %s\nsubj: %s   (day %d)[/color]\n" +
			"[color=#%s]%s[/color]\n\n[color=#%s]%s[/color]\n\n[color=#%s]esc: back[/color]") % [
			C_HI, m["from"], m["subj"], int(m["day"]),
			C_DIM, "─".repeat(50), C_VAL, m["body"], C_DIM]
	var s := "[color=#%s]mailbox[/color]\n\n" % C_DIM
	if Game.mails.is_empty():
		return s + "[color=#%s]  empty. even the spam avoids this address.[/color]" % C_DIM
	sel = clampi(sel, 0, Game.mails.size() - 1)
	for i in Game.mails.size():
		var m: Dictionary = Game.mails[i]
		var mark := ">" if i == sel else " "
		var dot := "●" if not m["read"] else " "
		var col := C_HI if not m["read"] else C_DIM
		s += "[color=#%s]%s %s d%02d  %-12s %s[/color]\n" % [col, mark, dot, int(m["day"]), m["from"], m["subj"]]
	s += "\n[color=#%s]enter: read[/color]" % C_DIM
	return s


func _render_sys() -> String:
	var pwr := "[color=#%s]ON[/color]" % C_HI if Game.power_on() else "[color=#%s]DOWN[/color]" % C_RED
	var s := "[color=#%s]" % C_VAL
	s += "node        NODE-07 basement relay, ex-exchange 4F\n"
	s += "uptime      day %d, shift ongoing\n" % Game.day
	s += "power       %s  (generator %s, breakers %s, fuel %d%%)\n" % [
		pwr, "on" if Game.generator_on else "off",
		"ok" if Game.breaker_ok else "TRIPPED", int(Game.fuel)]
	s += "thermals    racks %d°C, coolant %d%%\n" % [int(Game.heat), int(Game.coolant)]
	s += "storage     %d / %d MB\n" % [int(Net.disk_used()), int(Net.disk_total)]
	s += "line        amp lv%d, filter lv%d, %d tap slots\n" % [Net.modem_lvl, Net.filter_lvl, Net.tap_slots]
	s += "noise       %d%% and %s\n" % [int(Game.anomaly), "rising" if Game.anomaly > 30.0 else "tolerable"]
	s += "ledger      %d crd, %d/%d quota, strikes %d/3\n" % [
		Game.money, Game.sold_since_quota, Game.quota_needed(), Game.strikes]
	s += "[/color]\n[color=#%s]S: save    W W: wipe node and restart contract[/color]\n" % C_DIM
	s += "\n[color=#%s]nc-term 0.7 (1997-09) — property of the OPERATOR. do not unplug.[/color]" % C_DIM
	return s


func _render_decode() -> String:
	var f: Dictionary = decode["f"]
	var p: Dictionary = decode["puzzle"]
	var s := "[color=#%s]decoding %s — find the 3-glyph pattern that repeats at the\nsame position in EVERY row:[/color]\n\n" % [C_DIM, f["name"]]
	for row in p["rows"]:
		s += "      [color=#%s]%s[/color]\n" % [C_HI, row]
	s += "\n"
	for i in 4:
		s += "[color=#%s][%d][/color] [color=#%s]%s[/color]    " % [C_DIM, i + 1, C_VAL, p["options"][i]]
	s += "\n\n[color=#%s]1-4: commit   esc: abort (capture stays raw)[/color]" % C_DIM
	return s
