class_name NcReceiver
extends Control
## Scope, directional meter and grounding reticle. Lives below the normal HUD
## and never receives mouse input; the simulation owns all displayed numbers.

var strength := 0.0
var alignment := -1.0
var side := 0.0
var progress := 0.0
var phase := 0.0
var contact := false
var active_source := false
var tired := false
var _font: Font


func _ready() -> void:
	_font = NcHud.mono_font()
	mouse_filter = Control.MOUSE_FILTER_IGNORE
	set_anchors_and_offsets_preset(Control.PRESET_FULL_RECT)


func _text(pos: Vector2, text: String, color: Color, size := 12) -> void:
	draw_string(_font, pos, text, HORIZONTAL_ALIGNMENT_LEFT, -1, size, color)


func _draw() -> void:
	if _font == null:
		return
	var green := Color(.42, 1.0, .68)
	var amber := Color(1.0, .72, .33)
	var dim := Color(.24, .44, .36)
	var signal_color := amber if active_source else dim
	if contact:
		signal_color = green
	var center := size * .5
	draw_arc(center, 18.0, 0.0, TAU, 48, Color(signal_color, .45), 1.0, true)
	for dir in [Vector2.UP, Vector2.DOWN, Vector2.LEFT, Vector2.RIGHT]:
		draw_line(center + dir * 22.0, center + dir * 30.0, signal_color, 1.0, true)
	if progress > 0.0:
		draw_arc(center, 21.0, -PI / 2.0, -PI / 2.0 + TAU * progress, 64, green, 3.0, true)

	var origin := Vector2(maxf(14.0, size.x - 356.0), size.y - 310.0)
	var panel := Rect2(origin, Vector2(330, 200))
	draw_rect(panel, Color(.008, .022, .018, .96))
	draw_rect(panel, Color(dim, .8), false, 1.0)
	_text(origin + Vector2(15, 24), "R-07 / " + Loc.t("trace.receiver"), green)
	_text(origin + Vector2(15, 45), Loc.t("trace.signal", [int(strength * 100.0)]), signal_color, 11)
	var bearing := "trace.clear"
	if active_source:
		bearing = "trace.behind" if alignment < 0.0 else ("trace.right" if side > .12 else ("trace.left" if side < -.12 else "trace.ahead"))
	_text(origin + Vector2(210, 45), Loc.t(bearing), signal_color, 10)
	var scope := Rect2(origin + Vector2(15, 60), Vector2(300, 58))
	for i in 5:
		var y := scope.position.y + i * scope.size.y / 4.0
		draw_line(Vector2(scope.position.x, y), Vector2(scope.end.x, y), Color(dim, .3))
	var points := PackedVector2Array()
	for i in 151:
		var x := float(i) / 150.0
		var wave := sin(x * 45.0 + phase * 7.0) * sin(x * 11.0 - phase * 2.0)
		wave += sin(x * 117.0 + phase * 5.0) * .16
		var y := wave * (2.0 + strength * 20.0)
		points.append(scope.position + Vector2(x * scope.size.x, scope.size.y * .5 + y))
	draw_polyline(points, signal_color, 1.2, true)
	for i in 30:
		var color := signal_color if float(i) / 30.0 < strength else Color(dim, .35)
		draw_rect(Rect2(origin + Vector2(15 + i * 10, 130), Vector2(7, 5)), color)
	var hint := Loc.t("trace.search") if active_source else Loc.t("trace.clear")
	if contact:
		hint = Loc.t("trace.tired") if tired else Loc.t("trace.hold", [int(progress * 100.0)])
	_text(origin + Vector2(15, 159), hint, green if contact else dim, 11)
	_text(origin + Vector2(15, 184), Loc.t("trace.lower"), dim, 10)
