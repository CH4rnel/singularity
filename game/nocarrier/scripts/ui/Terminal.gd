class_name NcTerminal
extends CanvasLayer
## The NODE-07 terminal: a keyboard-driven text-mode UI rendered into one
## RichTextLabel, with a scanline overlay. Apps: SCAN (triangulate carriers),
## TAPS (downloads), FILES (decode/purge/read — working disks), MARKET (sell
## uploads, discs, hardcopies), SHOP (order), MAIL, SYS (save/lang/wipe),
## UPLINK (wallet, Cyberia backbone, sealing captures on chain) and MEDIA
## (physical storage: tapes, CD burns, printouts). Opening/closing is
## signalled so Main can freeze the player. All strings resolve through Loc.

signal opened
signal closed

const APPS := ["scan", "taps", "files", "market", "shop", "mail", "sys", "uplink", "media"]
const ARROWS := ["→", "↗", "↑", "↖", "←", "↙", "↓", "↘"]

const C_DIM := "4d8a63"
const C_HI := "6cffa0"
const C_VAL := "b8ffcf"
const C_WARN := "ffc252"
const C_RED := "ff5c52"
const C_CYAN := "52d8ff"
const C_MAG := "ff6cf8"

var app := "scan"
var cursor := Vector2i(8, 5)
var sel := 0
var mail_view := -1
var decode := {}          # {"f": file, "puzzle": {...}} while a decode runs
var last_ping := {}
var flash := ""           # one-shot status line shown on next render

var _rtl: RichTextLabel
var _wipe_armed := false
var _accum := 0.0
var _pk_edit: LineEdit
var _pk_label: Label
var importing := false
var _gen_armed := false


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

	# private-key import overlay (added last -> drawn on top)
	_pk_label = Label.new()
	_pk_label.add_theme_font_override("font", NcHud.mono_font())
	_pk_label.add_theme_font_size_override("font_size", 16)
	_pk_label.add_theme_color_override("font_color", Color(0.42, 1.0, 0.62))
	_pk_label.horizontal_alignment = HORIZONTAL_ALIGNMENT_CENTER
	_pk_label.anchor_left = 0.5
	_pk_label.anchor_right = 0.5
	_pk_label.anchor_top = 0.5
	_pk_label.anchor_bottom = 0.5
	_pk_label.offset_left = -360.0
	_pk_label.offset_right = 360.0
	_pk_label.offset_top = -72.0
	_pk_label.offset_bottom = -20.0
	_pk_label.visible = false
	add_child(_pk_label)

	_pk_edit = LineEdit.new()
	_pk_edit.add_theme_font_override("font", NcHud.mono_font())
	_pk_edit.add_theme_font_size_override("font_size", 18)
	_pk_edit.add_theme_color_override("font_color", Color(0.72, 1.0, 0.82))
	_pk_edit.placeholder_text = "0x…"
	_pk_edit.alignment = HORIZONTAL_ALIGNMENT_CENTER
	var pk_style := StyleBoxFlat.new()
	pk_style.bg_color = Color(0.02, 0.06, 0.03, 1.0)
	pk_style.border_color = Color(0.30, 0.55, 0.40)
	pk_style.set_border_width_all(1)
	pk_style.set_content_margin_all(8.0)
	_pk_edit.add_theme_stylebox_override("normal", pk_style)
	_pk_edit.add_theme_stylebox_override("focus", pk_style)
	_pk_edit.anchor_left = 0.5
	_pk_edit.anchor_right = 0.5
	_pk_edit.anchor_top = 0.5
	_pk_edit.anchor_bottom = 0.5
	_pk_edit.offset_left = -320.0
	_pk_edit.offset_right = 320.0
	_pk_edit.offset_top = -18.0
	_pk_edit.offset_bottom = 18.0
	_pk_edit.visible = false
	_pk_edit.text_submitted.connect(_do_import)
	add_child(_pk_edit)

	Net.net_changed.connect(_maybe_render)
	Media.media_changed.connect(_maybe_render)
	Game.state_changed.connect(_maybe_render)
	Game.mail_arrived.connect(_maybe_render)
	Loc.lang_changed.connect(_maybe_render)
	Ledger.seal_result.connect(_on_seal_result)


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


func _on_seal_result(ok: bool, info: String) -> void:
	if ok:
		flash = Loc.t("up.txsent", [info.left(14) + "…"])
	_maybe_render()


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
	_hide_import()
	closed.emit()


func _open_import() -> void:
	importing = true
	_pk_edit.text = ""
	_pk_edit.visible = true
	_pk_label.visible = true
	_pk_label.text = Loc.t("up.import_prompt") + "\n" + Loc.t("up.import_warn")
	_pk_edit.grab_focus()


func _hide_import() -> void:
	importing = false
	if _pk_edit:
		_pk_edit.visible = false
		_pk_edit.release_focus()
	if _pk_label:
		_pk_label.visible = false


func _do_import(text: String) -> void:
	_hide_import()
	if Signer.import_hex(text):
		flash = Loc.t("up.import_ok", [Signer.short_address()])
		Sfx.play("beep")
	else:
		flash = Loc.t("up.import_bad")
		Sfx.play("deny")
	render()


## --- input --------------------------------------------------------------------

func _unhandled_key_input(event: InputEvent) -> void:
	if not visible or Game.over:
		return
	var key := event as InputEventKey
	if key == null or not key.pressed or key.echo:
		return

	# while the key-import field is focused, let it handle typing; only steal
	# Escape to cancel so the rest of the terminal stays inert
	if importing:
		if key.physical_keycode == KEY_ESCAPE:
			_hide_import()
			get_viewport().set_input_as_handled()
			render()
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

	if code >= KEY_1 and code <= KEY_9 and mail_view < 0:
		var next: String = APPS[code - KEY_1]
		if next != app:
			app = next
			sel = 0
			_wipe_armed = false
		render()
		return

	match app:
		"scan": _scan_input(code)
		"taps": _taps_input(code)
		"files": _files_input(code)
		"market": _market_input(code)
		"shop": _shop_input(code)
		"mail": _mail_input(code)
		"sys": _sys_input(code)
		"uplink": _uplink_input(code)
		"media": _media_input(code)
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
				flash = Loc.t("scan.nolock")
				Sfx.play("deny")
			else:
				var err := Net.lock(c)
				if err == "":
					flash = Loc.t("scan.locked", [int(c["id"])])
				else:
					flash = Loc.t("t.no_slots")
					Sfx.play("deny")


func _taps_input(code: int) -> void:
	_nav(code, Net.taps.size())
	if code == KEY_K and sel < Net.taps.size() and Net.taps[sel] != null:
		Net.kill_tap(sel)
		flash = Loc.t("taps.released", [sel + 1])


func _files_input(code: int) -> void:
	var list := Net.hdd_files()
	_nav(code, list.size())
	if list.is_empty():
		return
	sel = clampi(sel, 0, list.size() - 1)
	var f: Dictionary = list[sel]
	match code:
		KEY_D:
			if bool(f["decoded"]):
				flash = Loc.t("files.already")
			else:
				decode = {"f": f, "puzzle": Net.make_puzzle(f)}
		KEY_P:
			Net.purge(f)
			flash = Loc.t("files.purged", [f["name"]])
			sel = 0
		KEY_R:
			if f["cls"] == "echo" and bool(f["decoded"]):
				flash = Loc.t("files.listen", [Net.read_echo(f).replace("\n", " ")])
			else:
				flash = Loc.t("files.noread")


## Sellable goods: decoded captures on a working disk (uploads), burned
## discs and hardcopies (physical, via the hatch) and loose scrap.
func _market_goods() -> Array:
	var out: Array = []
	for f in Net.hdd_files():
		if Net.sellable(f):
			out.append(f)
	for cd in Media.cds():
		out.append({"cd": cd})
	for p in Media.papers():
		if int(p.get("val", 0)) > 0:
			out.append({"paper": p})
	if int(Game.inventory["scrap"]) > 0:
		out.append({"scrap": true})
	return out


func _market_input(code: int) -> void:
	var goods := _market_goods()
	_nav(code, goods.size())
	if goods.is_empty():
		return
	sel = clampi(sel, 0, goods.size() - 1)
	var f: Dictionary = goods[sel]
	if code == KEY_ENTER or code == KEY_KP_ENTER:
		if f.has("scrap"):
			Game.sell_scrap()
		elif f.has("cd"):
			Media.sell_cd(f["cd"])
		elif f.has("paper"):
			Media.sell_paper(f["paper"])
		else:
			var v := Net.sell(f)
			flash = Loc.t("market.sold", [f["name"], v])
			Sfx.play("beep")
		sel = 0


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
			flash = Loc.t("sys.saved")
			Sfx.play("blip")
		KEY_L:
			Loc.toggle()
			Sfx.play("blip")
		KEY_W:
			if _wipe_armed:
				Game.reset_all()
			else:
				_wipe_armed = true
				flash = Loc.t("sys.wipe_arm")


func _uplink_input(code: int) -> void:
	var goods := Net.sealables()
	_nav(code, goods.size())
	if code != KEY_G:
		_gen_armed = false
	match code:
		KEY_C:
			if Wallet.available():
				Wallet.connect_wallet()
			else:
				flash = Loc.t("t.wallet_only")
		KEY_X:
			var a := Ledger.active_address()
			if a != "":
				DisplayServer.clipboard_set(a)
				flash = Loc.t("up.copied")
				Sfx.play("blip")
		KEY_I:
			if not Ledger.using_metamask():
				_open_import()
		KEY_K:
			if not Ledger.using_metamask() and Signer.has_key():
				DisplayServer.clipboard_set(Signer.export_hex())
				flash = Loc.t("up.exported")
				Sfx.play("blip")
		KEY_G:
			if Ledger.using_metamask():
				pass
			elif _gen_armed:
				_gen_armed = false
				Signer.regenerate()
				flash = Loc.t("up.gen_done", [Signer.short_address()])
			else:
				_gen_armed = true
				flash = Loc.t("up.gen_arm")
		KEY_ENTER, KEY_KP_ENTER:
			if goods.is_empty():
				return
			sel = clampi(sel, 0, goods.size() - 1)
			Ledger.seal(goods[sel])
			flash = Loc.t("up.pending")


## --- media app -------------------------------------------------------------------

## Selectable rows: every capture grouped by medium, then loose paper.
func _media_rows() -> Array:
	var rows: Array = []
	for kind in ["hdd", "tape", "cd"]:
		for it in Media.of_kind(kind):
			for f in Media.files_on(int(it["id"])):
				rows.append({"f": f, "m": it})
	for p in Media.papers():
		rows.append({"p": p})
	return rows


func _media_input(code: int) -> void:
	var rows := _media_rows()
	_nav(code, rows.size())
	if rows.is_empty():
		return
	sel = clampi(sel, 0, rows.size() - 1)
	var row: Dictionary = rows[sel]
	var err := ""
	match code:
		KEY_T:
			if row.has("f"):
				err = Media.start_tape_in(row["f"])
		KEY_H:
			if row.has("f"):
				err = Media.start_tape_out(row["f"])
		KEY_B:
			if row.has("f"):
				err = Media.start_burn(row["f"])
		KEY_P:
			if row.has("f"):
				err = Media.print_file(row["f"])
		KEY_R:
			if row.has("p"):
				flash = Loc.t("media.readout", [Media.read_paper(row["p"]).replace("\n", " ")])
		KEY_X:
			if row.has("p"):
				Media.shred_paper(row["p"])
				sel = 0
	if err != "":
		flash = Loc.t(err)
		Sfx.play("deny")


func _decode_input(code: int) -> void:
	if code == KEY_ESCAPE:
		decode = {}
		flash = Loc.t("decode.abort")
		return
	if code >= KEY_1 and code <= KEY_4:
		var pick := code - KEY_1
		var f: Dictionary = decode["f"]
		var ok: bool = pick == int(decode["puzzle"]["correct"])
		Net.finish_decode(f, ok)
		if ok:
			flash = Loc.t("decode.clean", [f["name"], Net.title_of(f)])
			Sfx.play("beep")
		else:
			flash = Loc.t("decode.partial", [f["name"]])
			Sfx.play("deny")
		decode = {}


## --- rendering ------------------------------------------------------------------

func render() -> void:
	var out := _header()
	if not decode.is_empty():
		out += _render_decode()
	else:
		match app:
			"scan": out += _render_scan()
			"taps": out += _render_taps()
			"files": out += _render_files()
			"market": out += _render_market()
			"shop": out += _render_shop()
			"mail": out += _render_mail()
			"sys": out += _render_sys()
			"uplink": out += _render_uplink()
			"media": out += _render_media()
	if flash != "":
		out += "\n[color=#%s]» %s[/color]" % [C_WARN, flash]
		flash = ""
	_rtl.text = out


func _header() -> String:
	var tabs := ""
	for i in APPS.size():
		var name := Loc.t("app." + APPS[i])
		if APPS[i] == "mail" and Game.unread_mail() > 0:
			name += "(%d)" % Game.unread_mail()
		var col := C_HI if APPS[i] == app else C_DIM
		tabs += "[color=#%s][%d]%s[/color]  " % [col, i + 1, name]
	return ("[color=#%s]NODE-07 // %s // %s %s // %s[/color]\n%s\n" +
		"[color=#%s]%s[/color]\n") % [
		C_HI, Loc.t("term.title"), Loc.t("ui.day", [Game.day]), Game.fmt_clock(),
		Loc.t("term.crd", [Game.money]), tabs,
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

	s += "\n[color=#%s]%s[/color]\n" % [C_DIM, Loc.t("scan.legend")]
	if not last_ping.is_empty():
		var line := Loc.t("scan.ping", [last_ping["cell"].x, last_ping["cell"].y, int(last_ping["strength"])])
		if last_ping.has("found"):
			line += Loc.t("scan.resolved", [int(last_ping["found"]["id"])])
		elif last_ping.has("dir"):
			var d: Vector2 = last_ping["dir"]
			var idx := wrapi(int(round(atan2(-d.y, d.x) / (PI / 4.0))), 0, 8)
			line += Loc.t("scan.bearing", [ARROWS[idx]])
		s += "[color=#%s]%s[/color]\n" % [C_VAL, line]
	var found_any := false
	for c in Net.carriers:
		if c["found"] and not c["locked"]:
			if not found_any:
				s += "\n[color=#%s]%s[/color]\n" % [C_DIM, Loc.t("scan.found_hdr")]
				found_any = true
			s += "[color=#%s]%s[/color]\n" % [C_MAG, Loc.t("scan.found_row", [
				int(c["id"]), c["sig"], int(snappedf(float(c["size"]), 10.0)),
				int(Vector2(c["pos"]).floor().x), int(Vector2(c["pos"]).floor().y)])]
	if Game.is_night():
		s += "\n[color=#%s]%s[/color]" % [C_RED, Loc.t("scan.night")]
	return s


func _render_taps() -> String:
	var s := "[color=#%s]%s%s[/color]\n\n" % [
		C_DIM, Loc.t("taps.hdr", [Net.tap_slots, Net.download_rate()]),
		"" if Game.power_on() else "  [color=#" + C_RED + "]" + Loc.t("taps.nopower") + "[/color]"]
	for i in Net.taps.size():
		var mark := ">" if i == sel else " "
		var t: Variant = Net.taps[i]
		if t == null:
			s += "[color=#%s]%s %s[/color]\n" % [C_DIM, mark, Loc.t("taps.idle", [i + 1])]
			continue
		var c := Net.carrier_by_id(int(t["cid"]))
		if c.is_empty():
			continue
		var got := float(t["got"])
		var total := float(c["size"])
		var cells := int(round(got / total * 12.0))
		var bar := "▓".repeat(cells) + "░".repeat(12 - cells)
		var eta := int(ceil((total - got) / Net.download_rate()))
		var warn := "  [color=#" + C_RED + "]" + Loc.t("taps.degrading") + "[/color]" if bool(t["corrupt"]) else ""
		s += "[color=#%s]%s %s%s[/color]\n" % [
			C_VAL, mark, Loc.t("taps.row", [i + 1, int(c["id"]), c["sig"], bar, int(got), int(total), eta]), warn]
	s += "\n[color=#%s]%s[/color]\n" % [C_DIM, Loc.t("taps.disk", [int(Net.disk_used()), int(Media.hdd_total())])]
	s += "[color=#%s]%s[/color]" % [
		C_WARN if Game.heat >= 80.0 else C_DIM, Loc.t("taps.heat", [int(Game.heat), int(Game.coolant)])]
	return s


func _cls_label(f: Dictionary) -> String:
	if not bool(f["decoded"]):
		return "[color=#%s]%s[/color]" % [C_DIM, Loc.t("files.raw")]
	var col := C_VAL
	match f["cls"]:
		"junk": col = C_DIM
		"anom": col = C_MAG
		"echo": col = C_CYAN
	return "[color=#%s]%s[/color]" % [col, Net.title_of(f)]


func _render_files() -> String:
	var list := Net.hdd_files()
	var s := "[color=#%s]%s[/color]\n\n" % [
		C_DIM, Loc.t("files.hdr", [int(Net.disk_used()), int(Media.hdd_total())])]
	if list.is_empty():
		return s + "[color=#%s]%s[/color]" % [C_DIM, Loc.t("files.empty")]
	sel = clampi(sel, 0, list.size() - 1)
	for i in list.size():
		var f: Dictionary = list[i]
		var mark := ">" if i == sel else " "
		var corrupt := "  [color=#" + C_RED + "]" + Loc.t("files.corrupt") + "[/color]" if bool(f["corrupt"]) else ""
		s += "[color=#%s]%s %s  %s  [/color]%s%s\n" % [
			C_VAL, mark, f["name"], Loc.t("files.row", [int(f["size"])]), _cls_label(f), corrupt]
	s += "\n[color=#%s]%s[/color]" % [C_DIM, Loc.t("files.legend")]
	return s


func _render_market() -> String:
	var quota_col := C_WARN if Game.sold_since_quota < Game.quota_needed() else C_HI
	var s := "[color=#%s]%s[/color]\n\n" % [
		quota_col, Loc.t("market.hdr", [Game.sold_since_quota, Game.quota_needed(), Game.quota_due_day()])]
	var goods := _market_goods()
	if goods.is_empty():
		return s + "[color=#%s]%s[/color]" % [C_DIM, Loc.t("market.empty")]
	sel = clampi(sel, 0, goods.size() - 1)
	for i in goods.size():
		var f: Dictionary = goods[i]
		var mark := ">" if i == sel else " "
		if f.has("scrap"):
			s += "[color=#%s]%s %s[/color]\n" % [C_VAL, mark, Loc.t("market.scrap", [int(Game.inventory["scrap"])])]
			continue
		if f.has("cd"):
			var cd: Dictionary = f["cd"]
			s += "[color=#%s]%s %s[/color]\n" % [C_VAL, mark, Loc.t("market.cd",
				[str(cd["label"]), Media.files_on(int(cd["id"])).size(), Media.cd_value(cd)])]
			continue
		if f.has("paper"):
			var p: Dictionary = f["paper"]
			s += "[color=#%s]%s %s[/color]\n" % [C_VAL, mark, Loc.t("market.paper",
				[str(p["label"]), Loc.t("title." + str(p["cls"]), [int(p.get("ti", 0))]), int(p["val"])])]
			continue
		var note := ""
		if f["cls"] == "anom":
			note = "  [color=#%s]%s[/color]" % [C_RED, Loc.t("market.hot")]
		s += "[color=#%s]%s %s  %4d  [/color]%s%s\n" % [
			C_VAL, mark, f["name"], Net.value(f), _cls_label(f), note]
	s += "\n[color=#%s]%s[/color]" % [C_DIM, Loc.t("market.legend")]
	return s


func _render_shop() -> String:
	var s := "[color=#%s]%s[/color]\n\n" % [C_DIM, Loc.t("shop.hdr")]
	for i in Game.SHOP.size():
		var it: Dictionary = Game.SHOP[i]
		var mark := ">" if i == sel else " "
		var id := str(it["id"])
		var own := ""
		if id == "tape":
			own = Loc.t("shop.tapes", [Media.tapes().size()])
		elif Game.inventory.has(id):
			own = Loc.t("shop.have", [int(Game.inventory[id])])
		elif id == "modem":
			own = Loc.t("shop.lv", [Net.modem_lvl])
		elif id == "filter":
			own = Loc.t("shop.lv", [Net.filter_lvl])
		elif id == "tapslot":
			own = Loc.t("shop.slots", [Net.tap_slots])
		elif id == "disk":
			own = Loc.t("shop.mb", [int(Media.hdd_total())])
		s += "[color=#%s]%s %-24s %4d  [color=#%s]%s[/color]%s[/color]\n" % [
			C_VAL, mark, Loc.t("shop.%s.n" % id), int(it["price"]), C_DIM, Loc.t("shop.%s.d" % id), own]
	if not Game.deliveries.is_empty():
		s += "\n[color=#%s]%s[/color]\n" % [C_DIM, Loc.t("shop.transit")]
		for d in Game.deliveries:
			var eta := maxi(int(ceil(float(d["at"]) - Game.abs_min())), 0)
			var names: Array = []
			for id in d["items"]:
				names.append(Loc.t("shop.%s.n" % id))
			s += "[color=#%s]%s[/color]\n" % [C_DIM, Loc.t("shop.eta", [", ".join(names), eta])]
	s += "\n[color=#%s]%s[/color]" % [C_DIM, Loc.t("shop.legend")]
	return s


func _render_mail() -> String:
	if mail_view >= 0 and mail_view < Game.mails.size():
		var m: Dictionary = Game.mails[mail_view]
		return ("[color=#%s]%s[/color]\n[color=#%s]%s[/color]\n\n" +
			"[color=#%s]%s[/color]\n\n[color=#%s]%s[/color]") % [
			C_HI, Loc.t("mailbox.head", [Game.mail_from(m), Game.mail_subj(m), int(m["day"])]),
			C_DIM, "─".repeat(50),
			C_VAL, Game.mail_body(m),
			C_DIM, Loc.t("mailbox.back")]
	var s := "[color=#%s]%s[/color]\n\n" % [C_DIM, Loc.t("mailbox.hdr")]
	if Game.mails.is_empty():
		return s + "[color=#%s]%s[/color]" % [C_DIM, Loc.t("mailbox.empty")]
	sel = clampi(sel, 0, Game.mails.size() - 1)
	for i in Game.mails.size():
		var m: Dictionary = Game.mails[i]
		var mark := ">" if i == sel else " "
		var dot := "●" if not m["read"] else " "
		var col := C_HI if not m["read"] else C_DIM
		s += "[color=#%s]%s %s d%02d  %-12s %s[/color]\n" % [
			col, mark, dot, int(m["day"]), Game.mail_from(m), Game.mail_subj(m)]
	s += "\n[color=#%s]%s[/color]" % [C_DIM, Loc.t("mailbox.read")]
	return s


func _render_sys() -> String:
	var pwr := "[color=#%s]%s[/color]" % ([C_HI, Loc.t("sys.on")] if Game.power_on() else [C_RED, Loc.t("sys.down")])
	var s := "[color=#%s]" % C_VAL
	s += Loc.t("sys.node") + "\n"
	s += Loc.t("sys.uptime", [Game.day]) + "\n"
	s += Loc.t("sys.power", [pwr, Loc.t("sys.gen_on" if Game.generator_on else "sys.gen_off"),
		Loc.t("sys.ok" if Game.breaker_ok else "sys.tripped"), int(Game.fuel), int(Game.battery)]) + "\n"
	s += Loc.t("sys.thermals", [int(Game.heat), int(Game.coolant)]) + "\n"
	s += Loc.t("sys.storage", [int(Net.disk_used()), int(Media.hdd_total()),
		Media.tapes().size(), Media.cds().size()]) + "\n"
	s += Loc.t("sys.line", [Net.modem_lvl, Net.filter_lvl, Net.tap_slots]) + "\n"
	s += Loc.t("sys.noise", [int(Game.anomaly), Loc.t("sys.rising" if Game.anomaly > 30.0 else "sys.tolerable")]) + "\n"
	s += Loc.t("sys.ledger", [Game.money, Game.sold_since_quota, Game.quota_needed(), Game.strikes, Game.debt]) + "\n"
	s += "[/color]\n[color=#%s]%s[/color]\n" % [C_DIM, Loc.t("sys.legend")]
	s += "\n[color=#%s]%s[/color]" % [C_DIM, Loc.t("sys.ver")]
	return s


func _render_uplink() -> String:
	var s := "[color=#%s]%s[/color]\n" % [
		C_CYAN, Loc.t("up.block", [Chain.latest_block]) if Chain.latest_block > 0 else Loc.t("up.noblock")]
	var meta := Ledger.using_metamask()
	s += "[color=#%s]%s[/color]\n" % [C_DIM, Loc.t("up.mode_meta" if meta else "up.mode_native")]
	var addr := Ledger.active_address()
	if addr != "":
		s += "[color=#%s]%s\n%s\n%s[/color]\n" % [
			C_VAL, Loc.t("up.addr", [addr]),
			Loc.t("up.balance", [Chain.player_cyber]),
			Loc.t("up.artifacts", [Ledger.balance])]
		if not meta:
			s += "[color=#%s]%s[/color]\n" % [C_DIM, Loc.t("up.fund")]
	s += "\n[color=#%s]%s[/color]\n" % [C_DIM, Loc.t("up.eligible")]
	var goods := Net.sealables()
	if goods.is_empty():
		s += "[color=#%s]%s[/color]\n" % [C_DIM, Loc.t("up.none")]
	else:
		sel = clampi(sel, 0, goods.size() - 1)
		for i in goods.size():
			var f: Dictionary = goods[i]
			var mark := ">" if i == sel else " "
			s += "[color=#%s]%s %s  [/color]%s\n" % [C_VAL, mark, f["name"], _cls_label(f)]
	if Ledger.pending_file_id >= 0:
		s += "\n[color=#%s]%s[/color]\n" % [C_WARN, Loc.t("up.pending")]
	if not Game.minted.is_empty():
		s += "\n[color=#%s]%s[/color]\n" % [C_DIM, Loc.t("up.minted_hdr")]
		for m in Game.minted.slice(maxi(Game.minted.size() - 4, 0)):
			s += "[color=#%s]  %s — %s  %s[/color]\n" % [
				C_CYAN, m["name"], m["title"], str(m["tx"]).left(12) + "…"]
	var extra := ""
	if not meta:
		extra += Loc.t("up.import_hint") + Loc.t("up.export_hint") + Loc.t("up.gen_hint")
	if Wallet.available() and not meta:
		extra += Loc.t("up.meta_hint")
	s += "\n[color=#%s]%s[/color]" % [C_DIM, Loc.t("up.legend", [extra])]
	return s


func _job_desc(j: Dictionary) -> String:
	var name := "?"
	for f in Net.files:
		if int(f["id"]) == int(j["fid"]):
			name = str(f["name"])
	return "%s %d%%" % [name, int(float(j["got"]) / maxf(float(j["size"]), 1.0) * 100.0)]


func _render_media() -> String:
	# devices
	var sj := Media.job_of("streamer")
	var bj := Media.job_of("burner")
	var dirt_col := C_WARN if Media.head_dirt >= 70.0 else C_DIM
	var s := "[color=#%s]%s[/color]\n" % [dirt_col,
		(Loc.t("media.streamer_busy", [_job_desc(sj), int(Media.head_dirt)]) if not sj.is_empty()
			else Loc.t("media.streamer_idle", [int(Media.head_dirt)]))]
	s += "[color=#%s]%s[/color]\n" % [C_DIM,
		(Loc.t("media.burner_busy", [_job_desc(bj)]) if not bj.is_empty()
			else Loc.t("media.burner_idle", [int(Game.inventory["cdr"])]))]
	s += "[color=#%s]%s[/color]\n" % [C_DIM, Loc.t("media.supplies",
		[int(Game.inventory["paper"]), int(Game.inventory["docs"]), int(Game.inventory["alcohol"])])]

	# shelf: the media themselves
	s += "\n"
	for it in Media.hdds():
		var h := int(float(it["health"]))
		var hcol := C_RED if h <= 20 else (C_WARN if h <= 40 else C_DIM)
		s += "[color=#%s]%s[/color]\n" % [hcol, Loc.t("media.hdd_row",
			[str(it["label"]), int(Media.used_on(int(it["id"]))), int(float(it["cap"])), h])]
	for it in Media.tapes():
		var h := int(float(it["health"]))
		var hcol := C_WARN if h < 25 else C_DIM
		var found := "  " + Loc.t("media.unlabeled") if bool(it.get("found", false)) else ""
		s += "[color=#%s]%s%s[/color]\n" % [hcol, Loc.t("media.tape_row",
			[str(it["label"]), int(Media.used_on(int(it["id"]))), int(float(it["cap"])), h]), found]
	for it in Media.cds():
		s += "[color=#%s]%s[/color]\n" % [C_CYAN, Loc.t("media.cd_row",
			[str(it["label"]), Media.files_on(int(it["id"])).size(), Media.cd_value(it)])]

	# contents
	var rows := _media_rows()
	s += "\n"
	if rows.is_empty():
		s += "[color=#%s]%s[/color]\n" % [C_DIM, Loc.t("media.empty")]
	else:
		sel = clampi(sel, 0, rows.size() - 1)
		for i in rows.size():
			var row: Dictionary = rows[i]
			var mark := ">" if i == sel else " "
			if row.has("p"):
				var p: Dictionary = row["p"]
				var desc := Loc.t("media.paper_page") if str(p.get("pk", "")) != "" \
					else Loc.t("media.paper_copy",
						[Loc.t("title." + str(p["cls"]), [int(p.get("ti", 0))]), int(p["val"])])
				s += "[color=#%s]%s %-4s  [/color][color=#%s]%s[/color]\n" % [
					C_VAL, mark, str(p["label"]), C_DIM, desc]
				continue
			var f: Dictionary = row["f"]
			var m: Dictionary = row["m"]
			var status := ""
			match str(m["kind"]):
				"tape": status = "  [color=#%s]%s[/color]" % [C_CYAN, Loc.t("media.dormant")]
				"cd": status = "  [color=#%s]%s[/color]" % [C_CYAN, Loc.t("media.sealed")]
			if bool(f["corrupt"]):
				status += "  [color=#%s]%s[/color]" % [C_RED, Loc.t("files.corrupt")]
			s += "[color=#%s]%s %-4s  %s  %s  [/color]%s%s\n" % [
				C_VAL, mark, str(m["label"]), f["name"],
				Loc.t("files.row", [int(f["size"])]), _cls_label(f), status]
	s += "\n[color=#%s]%s[/color]" % [C_DIM, Loc.t("media.legend")]
	return s


func _render_decode() -> String:
	var f: Dictionary = decode["f"]
	var p: Dictionary = decode["puzzle"]
	var s := "[color=#%s]%s[/color]\n\n" % [C_DIM, Loc.t("decode.hdr", [f["name"]])]
	for row in p["rows"]:
		s += "      [color=#%s]%s[/color]\n" % [C_HI, row]
	s += "\n"
	for i in 4:
		s += "[color=#%s][%d][/color] [color=#%s]%s[/color]    " % [C_DIM, i + 1, C_VAL, p["options"][i]]
	s += "\n\n[color=#%s]%s[/color]" % [C_DIM, Loc.t("decode.legend")]
	return s
