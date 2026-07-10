extends Node3D
## Builds all of NODE-07 procedurally — rooms, props, lights, interactables,
## HUD, terminal and menu — and hosts the world side of the horror events
## (flickers, blackouts, the silhouette, the corridor entity, the finale).
## All user-facing strings resolve through Loc; wall signs re-label on
## language switch.

const ROOMS := ["bunk", "control", "kitchen", "utility", "server", "storage", "corridor"]

# candidate scrap spawn spots around the station
const SCRAP_SPOTS := [
	Vector3(-8.0, 0.18, 3.2), Vector3(-13.0, 0.18, 4.6), Vector3(3.8, 0.18, 3.0),
	Vector3(8.2, 0.18, 8.2), Vector3(12.5, 0.18, 3.4), Vector3(-7.5, 0.18, -3.4),
	Vector3(-13.5, 0.18, -3.0), Vector3(4.2, 0.18, -7.8), Vector3(6.8, 0.18, -3.2),
	Vector3(11.5, 0.18, -8.0), Vector3(-3.0, 0.18, 1.2), Vector3(9.0, 0.18, 0.8),
]

# loose paperwork the previous sysop left behind (scanner food)
const DOC_SPOTS := [
	Vector3(-11.8, 0.14, 8.3), Vector3(2.8, 0.94, 8.0), Vector3(8.9, 0.94, 6.4),
	Vector3(-6.8, 0.14, -8.2), Vector3(12.8, 0.14, -3.0), Vector3(-2.2, 0.14, -0.8),
]

# where an unlabeled tape can surface (streamer food)
const TAPE_SPOTS := [
	Vector3(13.5, 2.14, -4.2), Vector3(7.9, 1.72, -7.8), Vector3(-14.4, 0.14, 4.4),
]

var hud: NcHud
var terminal: NcTerminal
var menu: NcMenu
var player: SysopPlayer

var _rooms := {}            # name -> {"lights": Array, "fixtures": Array}
var _signs: Array = []      # [[Label3D, loc_key], ...]
var _leds: Array = []       # rack LED materials
var _led_accum := 0.0
var _emergency: OmniLight3D
var _scraps := {}           # spot index -> Node3D
var _docs := {}             # spot index -> [node, area]
var _ftapes := {}           # spot index -> [node, area]
var _sil: Node3D = null
var _sil_look := 0.0
var _sil_ttl := 0.0
var _entity: Node3D = null
var _entity_step := 0.0
var _final := false

var _env: Environment
var _wall_mat: StandardMaterial3D
var _floor_mat: StandardMaterial3D
var _ceil_mat: StandardMaterial3D
var _metal_mat: StandardMaterial3D
var _dark_mat: StandardMaterial3D


func _ready() -> void:
	for r in ROOMS:
		_rooms[r] = {"lights": [], "fixtures": []}
	_build_env()
	_build_shell()
	_build_lights()
	_build_control()
	_build_bunk()
	_build_kitchen()
	_build_utility()
	_build_server()
	_build_storage()
	_build_corridor()

	player = SysopPlayer.new()
	player.position = Vector3(-10.0, 1.0, 5.5)
	add_child(player)
	player.main = self

	hud = NcHud.new()
	add_child(hud)
	terminal = NcTerminal.new()
	add_child(terminal)
	menu = NcMenu.new()
	add_child(menu)

	_spawn_scrap(3)
	_spawn_docs(2)
	Game.day_tick.connect(_daily_spawns)
	Loc.lang_changed.connect(_relabel_signs)
	Events.register_main(self)
	Game.power_changed.connect(_apply_power)
	_apply_power(Game.power_on())


func _process(delta: float) -> void:
	if player != null:
		player.frozen = terminal.visible or hud.modal_open or menu.visible \
			or hud.over_shown or Game.over
	_blink_leds(delta)
	_update_silhouette(delta)
	_update_entity(delta)


func request_pause() -> void:
	if Game.over or hud.over_shown or terminal.visible or hud.modal_open or menu.visible:
		return
	menu.open_pause()


## --- construction helpers -------------------------------------------------------

func _mat(c: Color, rough := 0.92, metal := 0.0) -> StandardMaterial3D:
	var m := StandardMaterial3D.new()
	m.albedo_color = c
	m.roughness = rough
	m.metallic = metal
	return m


func _glow_mat(c: Color, energy := 1.6) -> StandardMaterial3D:
	var m := StandardMaterial3D.new()
	m.albedo_color = c * 0.4
	m.emission_enabled = true
	m.emission = c
	m.emission_energy_multiplier = energy
	return m


func _box(pos: Vector3, size: Vector3, mat: Material, collide := true, parent: Node = self) -> MeshInstance3D:
	var mi := MeshInstance3D.new()
	var mesh := BoxMesh.new()
	mesh.size = size
	mi.mesh = mesh
	mi.material_override = mat
	mi.position = pos
	if collide:
		var body := StaticBody3D.new()
		var shape := CollisionShape3D.new()
		var box := BoxShape3D.new()
		box.size = size
		shape.shape = box
		body.add_child(shape)
		mi.add_child(body)
	parent.add_child(mi)
	return mi


func _wall(cx: float, cz: float, sx: float, sz: float) -> void:
	_box(Vector3(cx, 1.5, cz), Vector3(sx, 3.0, sz), _wall_mat)


func _interact(prompt: String, action: Callable, pos: Vector3, size: Vector3, prompt_fn := Callable()) -> Interactable:
	var a := Interactable.make(prompt, action, pos, size)
	a.prompt_fn = prompt_fn
	add_child(a)
	return a


func _sign(key: String, pos: Vector3, rot_y: float, color := Color(0.5, 0.9, 0.65), fsize := 48) -> void:
	var l := Label3D.new()
	l.text = Loc.t(key)
	l.font = NcHud.mono_font()
	l.font_size = fsize
	l.modulate = color
	l.position = pos
	l.rotation.y = rot_y
	add_child(l)
	_signs.append([l, key])


func _relabel_signs() -> void:
	for s in _signs:
		s[0].text = Loc.t(s[1])


## --- world -----------------------------------------------------------------------

func _build_env() -> void:
	_wall_mat = _mat(Color(0.16, 0.185, 0.165))
	_floor_mat = _mat(Color(0.115, 0.115, 0.125))
	_ceil_mat = _mat(Color(0.09, 0.095, 0.1))
	_metal_mat = _mat(Color(0.22, 0.24, 0.26), 0.55, 0.6)
	_dark_mat = _mat(Color(0.05, 0.055, 0.06), 0.7, 0.2)

	var we := WorldEnvironment.new()
	var env := Environment.new()
	env.background_mode = Environment.BG_COLOR
	env.background_color = Color(0.005, 0.008, 0.007)
	env.ambient_light_source = Environment.AMBIENT_SOURCE_COLOR
	env.ambient_light_color = Color(0.5, 0.62, 0.55)
	env.ambient_light_energy = 0.16
	env.fog_enabled = true
	env.fog_light_color = Color(0.02, 0.03, 0.025)
	env.fog_density = 0.012
	we.environment = env
	add_child(we)
	_env = env
	Settings.changed.connect(_apply_brightness)
	_apply_brightness()


func _apply_brightness() -> void:
	if _env == null:
		return
	var b := Settings.brightness_factor()
	_env.adjustment_enabled = not is_equal_approx(b, 1.0)
	_env.adjustment_brightness = b


func _build_shell() -> void:
	_box(Vector3(0, -0.1, 0), Vector3(30.6, 0.2, 18.6), _floor_mat)
	_box(Vector3(0, 3.1, 0), Vector3(30.6, 0.2, 18.6), _ceil_mat)
	# perimeter
	_wall(0, 9, 30.6, 0.3)
	_wall(0, -9, 30.6, 0.3)
	_wall(-15, 0, 0.3, 18.6)
	_wall(15, 0, 0.3, 18.6)
	# corridor walls with doorways at x = -10, 0, 10 (1.4 wide, 2.2 high)
	for z in [2.0, -2.0]:
		for seg in [[-15.0, -10.7], [-9.3, -0.7], [0.7, 9.3], [10.7, 15.0]]:
			var x1: float = seg[0]
			var x2: float = seg[1]
			_wall((x1 + x2) / 2.0, z, x2 - x1, 0.3)
		for cx in [-10.0, 0.0, 10.0]:
			_box(Vector3(cx, 2.6, z), Vector3(1.4, 0.8, 0.3), _wall_mat)
	# room dividers
	_wall(-5, 5.5, 0.3, 7.0)
	_wall(5, 5.5, 0.3, 7.0)
	_wall(-5, -5.5, 0.3, 7.0)
	_wall(5, -5.5, 0.3, 7.0)


func _build_lights() -> void:
	_room_light("bunk", Vector3(-10, 2.8, 5.5), Color(0.9, 0.85, 0.7), 0.9)
	_room_light("control", Vector3(0, 2.8, 5.5), Color(0.7, 0.9, 0.85), 1.0)
	_room_light("kitchen", Vector3(10, 2.8, 5.5), Color(0.95, 0.9, 0.75), 1.0)
	_room_light("utility", Vector3(-10, 2.8, -5.5), Color(1.0, 0.8, 0.55), 0.9)
	_room_light("server", Vector3(0, 2.8, -5.5), Color(0.6, 0.75, 1.0), 0.8)
	_room_light("storage", Vector3(10, 2.8, -5.5), Color(0.9, 0.85, 0.7), 0.8)
	for x in [-10.0, 0.0, 10.0]:
		_room_light("corridor", Vector3(x, 2.8, 0), Color(0.75, 0.95, 0.8), 0.8)
	_emergency = OmniLight3D.new()
	_emergency.position = Vector3(13.0, 2.6, 0)
	_emergency.light_color = Color(1.0, 0.15, 0.1)
	_emergency.light_energy = 0.0
	_emergency.omni_range = 12.0
	add_child(_emergency)


func _room_light(room: String, pos: Vector3, color: Color, energy: float) -> void:
	var light := OmniLight3D.new()
	light.position = pos
	light.light_color = color
	light.light_energy = energy
	light.omni_range = 9.0
	light.shadow_enabled = true
	add_child(light)
	var fmat := _glow_mat(color, 1.4)
	_box(pos + Vector3(0, 0.22, 0), Vector3(0.8, 0.06, 0.25), fmat, false)
	_rooms[room]["lights"].append(light)
	_rooms[room]["fixtures"].append(fmat)


func _build_control() -> void:
	_sign("sign.control", Vector3(0, 2.55, 2.25), PI)
	_box(Vector3(0, 0.78, 8.2), Vector3(4.6, 0.08, 0.9), _metal_mat)
	_box(Vector3(-2.1, 0.39, 8.2), Vector3(0.12, 0.78, 0.8), _metal_mat)
	_box(Vector3(2.1, 0.39, 8.2), Vector3(0.12, 0.78, 0.8), _metal_mat)
	for x in [-1.5, 0.0, 1.5]:
		_box(Vector3(x, 1.14, 8.35), Vector3(0.72, 0.6, 0.55), _dark_mat)
		var screen := _glow_mat(Color(0.15, 0.7, 0.35), 0.8 if x != 0.0 else 1.4)
		_box(Vector3(x, 1.14, 8.05), Vector3(0.56, 0.44, 0.02), screen, false)
	_box(Vector3(0, 0.86, 7.7), Vector3(0.9, 0.05, 0.25), _dark_mat)  # keyboard
	_box(Vector3(0, 0.28, 7.1), Vector3(0.55, 0.56, 0.55), _mat(Color(0.2, 0.16, 0.12)))  # chair
	_box(Vector3(0, 0.85, 7.36), Vector3(0.55, 0.6, 0.08), _mat(Color(0.2, 0.16, 0.12)))
	_interact("", terminal_open, Vector3(0, 1.1, 8.0), Vector3(4.2, 1.7, 1.4),
		func() -> String: return Loc.t("prompt.terminal"))
	# dot-matrix printer on its own stand, left of the console
	_box(Vector3(-3.9, 0.5, 8.3), Vector3(0.6, 1.0, 0.6), _metal_mat)
	_box(Vector3(-3.9, 1.12, 8.3), Vector3(0.66, 0.24, 0.5), _dark_mat)
	_box(Vector3(-3.9, 1.26, 8.42), Vector3(0.5, 0.02, 0.2), _mat(Color(0.8, 0.8, 0.72)))
	_interact("", _printer_touch, Vector3(-3.9, 1.1, 8.2), Vector3(1.0, 1.0, 1.0),
		func() -> String: return Loc.t("prompt.printer", [int(Game.inventory["paper"])]))
	# flatbed scanner, right of the console
	_box(Vector3(3.9, 0.5, 8.3), Vector3(0.6, 1.0, 0.6), _metal_mat)
	_box(Vector3(3.9, 1.06, 8.3), Vector3(0.62, 0.12, 0.5), _dark_mat)
	_box(Vector3(3.9, 1.13, 8.3), Vector3(0.5, 0.02, 0.4), _glow_mat(Color(0.4, 0.9, 0.5), 0.5))
	_interact("", Media.scan_doc, Vector3(3.9, 1.05, 8.2), Vector3(1.0, 1.0, 1.0),
		func() -> String: return Loc.t("prompt.scanner", [int(Game.inventory["docs"])]))
	# wall phone
	_box(Vector3(4.78, 1.5, 5.5), Vector3(0.12, 0.4, 0.24), _mat(Color(0.35, 0.1, 0.08)))
	_interact("", Events.answer_phone, Vector3(4.7, 1.5, 5.5), Vector3(0.5, 0.7, 0.5),
		func() -> String:
			return Loc.t("prompt.phone_ring") if Events.phone_ringing else Loc.t("prompt.phone"))


func terminal_open() -> void:
	if not Game.over:
		terminal.open()


func _printer_touch() -> void:
	Game.toast(Loc.t("printer.lines", [Loc.rand_i("printer.lines")]))


func _build_bunk() -> void:
	_sign("sign.bunk", Vector3(-10, 2.55, 2.25), PI)
	_box(Vector3(-13.9, 0.3, 7.3), Vector3(1.1, 0.35, 2.2), _mat(Color(0.25, 0.28, 0.2)))
	_box(Vector3(-13.9, 0.52, 8.1), Vector3(0.9, 0.12, 0.45), _mat(Color(0.75, 0.73, 0.65)))
	_interact("", _bed_menu, Vector3(-13.8, 0.7, 7.3), Vector3(1.5, 1.2, 2.5),
		func() -> String: return Loc.t("prompt.sleep"))
	_box(Vector3(-14.5, 1.0, 3.5), Vector3(0.7, 2.0, 0.6), _metal_mat)
	_interact("", _locker, Vector3(-14.4, 1.0, 3.5), Vector3(1.0, 2.1, 0.9),
		func() -> String: return Loc.t("prompt.locker"))
	_box(Vector3(-11.5, 0.4, 8.4), Vector3(0.6, 0.8, 0.6), _mat(Color(0.3, 0.24, 0.16)))  # nightstand


func _bed_menu() -> void:
	var till8 := int(fmod(1440.0 - Game.time_min + 8.0 * 60.0, 1440.0))
	if till8 < 30:
		till8 += 1440
	hud.modal(Loc.t("modal.bed"), [
		Loc.t("modal.bed.until", [till8 / 60, till8 % 60]),
		Loc.t("modal.bed.nap"),
	], func(idx: int) -> void:
		_do_sleep(float(till8) / 60.0 if idx == 0 else 4.0))


func _do_sleep(hours: float) -> void:
	await hud.fade_to(1.0, 0.9)
	Game.sleep_hours(hours)
	await hud.fade_to(0.0, 0.9)


func _locker() -> void:
	Game.toast(Loc.t("locker.lines", [Loc.rand_i("locker.lines")]))


func _build_kitchen() -> void:
	_sign("sign.kitchen", Vector3(10, 2.55, 2.25), PI)
	_box(Vector3(14.3, 0.45, 6.5), Vector3(1.0, 0.9, 4.2), _metal_mat)
	_box(Vector3(14.3, 1.05, 7.6), Vector3(0.3, 0.3, 0.3), _dark_mat)  # kettle
	_interact("", Game.eat_noodles, Vector3(14.2, 1.1, 7.6), Vector3(0.7, 0.6, 0.7),
		func() -> String: return Loc.t("prompt.noodles", [int(Game.inventory["noodles"])]))
	_box(Vector3(14.3, 1.15, 5.4), Vector3(0.35, 0.5, 0.35), _mat(Color(0.15, 0.1, 0.1)))  # coffee machine
	_interact("", Game.drink_coffee, Vector3(14.2, 1.15, 5.4), Vector3(0.75, 0.8, 0.75),
		func() -> String: return Loc.t("prompt.coffee", [int(Game.inventory["coffee"])]))
	_box(Vector3(6.2, 0.4, 3.2), Vector3(0.5, 0.8, 0.5), _mat(Color(0.18, 0.2, 0.18)))  # bin
	_interact("", Game.take_trash, Vector3(6.2, 0.5, 3.2), Vector3(0.9, 1.1, 0.9),
		func() -> String: return Loc.t("prompt.bin", [Game.bin, Game.BIN_MAX]))
	# trash chute panel
	_box(Vector3(14.78, 1.35, 8.4), Vector3(0.12, 0.7, 0.7), _dark_mat)
	_interact("", Game.dump_trash, Vector3(14.6, 1.35, 8.4), Vector3(0.6, 1.0, 1.0),
		func() -> String: return Loc.t("prompt.chute"))
	_box(Vector3(9.0, 0.4, 6.5), Vector3(1.2, 0.8, 1.2), _mat(Color(0.3, 0.24, 0.16)))  # table
	_box(Vector3(7.6, 0.25, 6.5), Vector3(0.5, 0.5, 0.5), _mat(Color(0.2, 0.16, 0.12)))  # stool


func _build_utility() -> void:
	_sign("sign.utility", Vector3(-10, 2.55, -2.25), 0.0)
	_box(Vector3(-12.0, 0.75, -7.2), Vector3(2.4, 1.5, 1.4), _mat(Color(0.32, 0.14, 0.1), 0.6, 0.4))
	_box(Vector3(-11.2, 1.7, -7.2), Vector3(0.25, 0.5, 0.25), _dark_mat)  # exhaust
	_interact("", _generator_menu, Vector3(-12.0, 0.9, -7.0), Vector3(2.8, 1.8, 2.0),
		func() -> String:
			var st := Loc.t("gen.running") if Game.gen_running() else Loc.t("gen.silent")
			return Loc.t("prompt.gen", [st, int(Game.fuel)]))
	_box(Vector3(-14.3, 0.6, -5.0), Vector3(0.8, 1.2, 0.8), _mat(Color(0.5, 0.32, 0.1), 0.5, 0.5))  # fuel drum
	# degausser: a squat coil cabinet nobody remembers ordering
	_box(Vector3(-13.8, 0.45, -3.3), Vector3(0.9, 0.9, 0.9), _metal_mat)
	_box(Vector3(-13.8, 1.0, -3.3), Vector3(0.55, 0.2, 0.55), _glow_mat(Color(0.9, 0.5, 0.2), 0.7))
	_interact("", _degauss_menu, Vector3(-13.8, 0.7, -3.1), Vector3(1.3, 1.4, 1.3),
		func() -> String: return Loc.t("prompt.degausser"))
	# UPS battery + hand dynamo
	_box(Vector3(-8.2, 0.5, -8.3), Vector3(1.2, 1.0, 0.7), _mat(Color(0.1, 0.14, 0.22), 0.6, 0.4))
	_box(Vector3(-8.2, 1.1, -8.3), Vector3(0.3, 0.2, 0.3), _metal_mat)  # crank
	_interact("", Game.crank_dynamo, Vector3(-8.2, 0.8, -8.1), Vector3(1.6, 1.4, 1.2),
		func() -> String: return Loc.t("prompt.crank", [int(Game.battery)]))
	# breaker box on the corridor-side wall
	_box(Vector3(-6.5, 1.5, -2.42), Vector3(0.6, 0.8, 0.18), _metal_mat)
	_interact("", Game.reset_breaker, Vector3(-6.5, 1.5, -2.6), Vector3(1.0, 1.2, 0.6),
		func() -> String:
			return Loc.t("prompt.breaker_ok" if Game.breaker_ok else "prompt.breaker_trip"))


func _generator_menu() -> void:
	var opts: Array = []
	var tags: Array = []
	opts.append(Loc.t("modal.gen.refuel", [int(Game.FUEL_PER_CAN), int(Game.inventory["fuel"])]))
	tags.append("refuel")
	opts.append(Loc.t("modal.gen.off" if Game.generator_on else "modal.gen.on"))
	tags.append("toggle")
	if int(Game.inventory["scrap"]) > 0:
		opts.append(Loc.t("modal.gen.burn", [int(Game.inventory["scrap"])]))
		tags.append("burn")
	if Game.carrying_trash:
		opts.append(Loc.t("modal.gen.burnbag"))
		tags.append("burnbag")
	hud.modal(Loc.t("modal.gen"), opts, _generator_action.bind(tags))


func _generator_action(idx: int, tags: Array) -> void:
	match tags[idx]:
		"refuel": Game.refuel_generator()
		"toggle": Game.toggle_generator()
		"burn": Game.burn_scrap()
		"burnbag": Game.burn_bag()


func _build_server() -> void:
	_sign("sign.server", Vector3(0, 2.55, -2.25), 0.0)
	for x in [-2.5, 2.5]:
		for z in [-6.8, -4.4]:
			_box(Vector3(x, 1.15, z), Vector3(1.0, 2.3, 0.8), _dark_mat)
			for i in 6:
				var led := _glow_mat(Color(0.2, 0.6, 1.0) if randf() > 0.3 else Color(0.2, 1.0, 0.4), 2.0)
				_box(Vector3(x - 0.3 + 0.12 * i, 1.7 - 0.25 * (i % 3), z + 0.42), Vector3(0.05, 0.05, 0.02), led, false)
				_leds.append(led)
	_box(Vector3(0, 1.0, -8.4), Vector3(1.8, 2.0, 0.8), _metal_mat)  # cooling unit
	_interact("", _cooling_menu, Vector3(0, 1.0, -8.2), Vector3(2.2, 2.2, 1.4),
		func() -> String: return Loc.t("prompt.cool", [int(Game.heat), int(Game.coolant)]))


func _streamer_menu() -> void:
	hud.modal(Loc.t("modal.streamer", [int(Media.head_dirt)]), [
		Loc.t("modal.streamer.clean", [int(Game.inventory["alcohol"])]),
	], func(_idx: int) -> void:
		Media.clean_heads())


func _degauss_menu() -> void:
	var opts: Array = []
	var ids: Array = []
	for it in Media.hdds() + Media.tapes():
		if opts.size() >= 6:
			break
		opts.append(Loc.t("modal.degauss.item",
			[str(it["label"]), Media.files_on(int(it["id"])).size()]))
		ids.append(int(it["id"]))
	if opts.is_empty():
		Game.toast(Loc.t("t.degauss_nothing"))
		return
	hud.modal(Loc.t("modal.degauss"), opts, func(idx: int) -> void:
		Media.degauss(int(ids[idx])))


func _cooling_menu() -> void:
	hud.modal(Loc.t("modal.cool"), [
		Loc.t("modal.cool.top", [int(Game.inventory["coolant"])]),
		Loc.t("modal.cool.clean"),
	], func(idx: int) -> void:
		if idx == 0:
			Game.top_up_coolant()
		else:
			Game.clean_vents())


func _build_storage() -> void:
	_sign("sign.storage", Vector3(10, 2.55, -2.25), 0.0)
	for z in [-4.2, -7.0]:
		_box(Vector3(13.5, 1.0, z), Vector3(0.7, 2.0, 1.8), _metal_mat)
	# reel-to-reel streamer and the tape shelf
	_box(Vector3(6.0, 0.9, -8.35), Vector3(1.0, 1.8, 0.5), _dark_mat)
	for y in [1.35, 0.85]:
		_box(Vector3(6.0, y, -8.08), Vector3(0.28, 0.28, 0.06),
			_mat(Color(0.45, 0.38, 0.25), 0.5, 0.3), false)
	_interact("", _streamer_menu, Vector3(6.0, 1.0, -8.1), Vector3(1.4, 2.0, 1.0),
		func() -> String:
			var j := Media.job_of("streamer")
			var st := Loc.t("streamer.busy") if not j.is_empty() else Loc.t("streamer.idle")
			return Loc.t("prompt.streamer", [st, int(Media.head_dirt)]))
	_box(Vector3(9.5, 0.5, -8.5), Vector3(1.6, 1.0, 0.4), _metal_mat)  # tape shelf
	for p in [Vector3(7.5, 0.4, -7.8), Vector3(8.6, 0.4, -7.6), Vector3(7.9, 1.2, -7.8)]:
		_box(p, Vector3(0.8, 0.8, 0.8), _mat(Color(0.35, 0.28, 0.18)))
	# delivery hatch
	_box(Vector3(14.78, 1.3, -5.5), Vector3(0.12, 0.9, 0.9), _mat(Color(0.3, 0.32, 0.2), 0.6, 0.5))
	_interact("", Game.collect_hatch, Vector3(14.55, 1.3, -5.5), Vector3(0.7, 1.2, 1.2),
		func() -> String:
			if Game.hatch.is_empty():
				return Loc.t("prompt.hatch_empty")
			return Loc.t("prompt.hatch_n", [Game.hatch.size()]))


func _build_corridor() -> void:
	_sign("sign.node", Vector3(-14.7, 2.2, 0), PI / 2.0, Color(0.5, 0.9, 0.65), 64)
	# sealed stairwell door, east end
	_box(Vector3(14.78, 1.15, 0), Vector3(0.15, 2.3, 1.2), _mat(Color(0.25, 0.22, 0.2), 0.6, 0.5))
	_box(Vector3(14.7, 2.55, 0), Vector3(0.1, 0.3, 1.0), _glow_mat(Color(1.0, 0.2, 0.15), 1.2), false)
	_sign("sign.exit", Vector3(14.62, 2.55, 0), -PI / 2.0, Color(1.0, 0.3, 0.25), 40)
	_interact("", _exit_door, Vector3(14.5, 1.2, 0), Vector3(0.8, 2.4, 1.6),
		func() -> String: return Loc.t("prompt.door"))


func _exit_door() -> void:
	if Game.anomaly >= 60.0:
		Game.toast(Loc.t("door.breath"))
		Game.anomaly = minf(Game.anomaly + 1.0, 100.0)
	elif Game.anomaly >= 25.0:
		Game.toast(Loc.t("door.warm"))
	else:
		Game.toast(Loc.t("door.calm"))
	Sfx.play("thud", -14.0)


## --- scrap -----------------------------------------------------------------------

func _spawn_scrap(n: int) -> void:
	var free: Array = []
	for i in SCRAP_SPOTS.size():
		if not _scraps.has(i):
			free.append(i)
	free.shuffle()
	n = mini(n, mini(free.size(), 6 - _scraps.size()))
	for k in n:
		var i: int = free[k]
		var node := Node3D.new()
		node.position = SCRAP_SPOTS[i]
		var m := _mat(Color(0.35, 0.3, 0.22), 0.8, 0.3)
		_box(Vector3(0, 0.0, 0), Vector3(0.42, 0.16, 0.3), m, false, node)
		_box(Vector3(0.1, 0.14, 0.05), Vector3(0.2, 0.12, 0.16), m, false, node)
		add_child(node)
		var area := Interactable.make("", _pick_scrap.bind(i), SCRAP_SPOTS[i] + Vector3(0, 0.25, 0), Vector3(0.8, 0.7, 0.8))
		area.prompt_fn = func() -> String: return Loc.t("prompt.scrap")
		add_child(area)
		_scraps[i] = [node, area]


func _pick_scrap(i: int) -> void:
	if not _scraps.has(i):
		return
	var pair: Array = _scraps[i]
	pair[0].queue_free()
	pair[1].queue_free()
	_scraps.erase(i)
	Game.pick_scrap()


func _daily_spawns(_d: int) -> void:
	_spawn_scrap(2 + randi() % 2)
	_spawn_docs(randi() % 3)
	if _ftapes.is_empty() and randf() < 0.25:
		_spawn_found_tape()


func _spawn_docs(n: int) -> void:
	var free: Array = []
	for i in DOC_SPOTS.size():
		if not _docs.has(i):
			free.append(i)
	free.shuffle()
	for k in mini(n, free.size()):
		var i: int = free[k]
		var node := Node3D.new()
		node.position = DOC_SPOTS[i]
		var m := _mat(Color(0.72, 0.72, 0.64), 0.95)
		_box(Vector3(0, 0.0, 0), Vector3(0.3, 0.02, 0.4), m, false, node)
		_box(Vector3(0.06, 0.02, -0.04), Vector3(0.3, 0.02, 0.4), m, false, node)
		add_child(node)
		var area := Interactable.make("", _pick_doc.bind(i),
			DOC_SPOTS[i] + Vector3(0, 0.15, 0), Vector3(0.7, 0.5, 0.7))
		area.prompt_fn = func() -> String: return Loc.t("prompt.doc")
		add_child(area)
		_docs[i] = [node, area]


func _pick_doc(i: int) -> void:
	if not _docs.has(i):
		return
	var pair: Array = _docs[i]
	pair[0].queue_free()
	pair[1].queue_free()
	_docs.erase(i)
	Game.pick_doc()


func _spawn_found_tape() -> void:
	var i := randi() % TAPE_SPOTS.size()
	if _ftapes.has(i):
		return
	var node := Node3D.new()
	node.position = TAPE_SPOTS[i]
	_box(Vector3(0, 0.0, 0), Vector3(0.3, 0.3, 0.08), _mat(Color(0.32, 0.26, 0.16), 0.6), false, node)
	_box(Vector3(0, 0.0, 0.05), Vector3(0.14, 0.14, 0.02), _mat(Color(0.5, 0.42, 0.28), 0.5), false, node)
	add_child(node)
	var area := Interactable.make("", _pick_found_tape.bind(i),
		TAPE_SPOTS[i], Vector3(0.7, 0.7, 0.7))
	area.prompt_fn = func() -> String: return Loc.t("prompt.tape_found")
	add_child(area)
	_ftapes[i] = [node, area]


func _pick_found_tape(i: int) -> void:
	if not _ftapes.has(i):
		return
	var pair: Array = _ftapes[i]
	pair[0].queue_free()
	pair[1].queue_free()
	_ftapes.erase(i)
	var it := Media.add_found_tape()
	Game.toast(Loc.t("t.found_tape", [str(it["label"])]))
	Sfx.play("blip")


## --- power & light control ---------------------------------------------------------

func _apply_power(on: bool) -> void:
	for room in _rooms:
		_set_room_light(room, on)
	_emergency.light_energy = 0.0 if on else 0.7
	Sfx.set_hum(on)
	if not on and terminal.visible:
		terminal.close()
		Game.toast(Loc.t("t.term_dies"))


func _set_room_light(room: String, on: bool) -> void:
	for l in _rooms[room]["lights"]:
		l.visible = on
	for f in _rooms[room]["fixtures"]:
		f.emission_energy_multiplier = 1.4 if on else 0.0


func random_room() -> String:
	return ROOMS[randi() % ROOMS.size()]


func _blink_leds(delta: float) -> void:
	_led_accum += delta
	if _led_accum < 0.35:
		return
	_led_accum = 0.0
	var powered := Game.power_on()
	for led in _leds:
		led.emission_energy_multiplier = (2.0 if randf() > 0.35 else 0.1) if powered else 0.0


## --- event hooks (called by Events) -------------------------------------------------

func flicker_room(room: String) -> void:
	if not Game.power_on() or _final:
		return
	var lights: Array = _rooms[room]["lights"]
	for i in 7:
		for l in lights:
			l.light_energy = randf_range(0.1, 1.1)
		await get_tree().create_timer(0.07).timeout
	for l in lights:
		l.light_energy = 0.9
	Sfx.play("blip", -20.0)


func lights_out(room: String, dur: float) -> void:
	if not Game.power_on():
		return
	_set_room_light(room, false)
	Game.toast(Loc.t("t.light_out", [Loc.t("room." + room)]))
	await get_tree().create_timer(dur).timeout
	if Game.power_on() and not _final:
		_set_room_light(room, true)


func crt_flash() -> void:
	hud.flash_nocarrier(0.3, Loc.t("crt.words", [Loc.rand_i("crt.words")]))


func nocarrier_takeover() -> void:
	if terminal.visible:
		terminal.close()
	hud.flash_nocarrier(2.4)


func knock() -> void:
	Sfx.play("knock", -4.0)
	Game.toast(Loc.t("t.knock"))


func spawn_silhouette() -> void:
	if _sil != null or player == null:
		return
	_sil = Node3D.new()
	var x := -14.0 if player.position.x > 0.0 else 14.0
	_sil.position = Vector3(x, 0, 0)
	var m := _mat(Color(0.01, 0.01, 0.015), 1.0)
	_box(Vector3(0, 0.85, 0), Vector3(0.45, 1.7, 0.3), m, false, _sil)
	_box(Vector3(0, 1.82, 0), Vector3(0.24, 0.26, 0.24), m, false, _sil)
	add_child(_sil)
	_sil_ttl = 7.0
	_sil_look = 0.0
	Sfx.play("static", -22.0)


func _update_silhouette(delta: float) -> void:
	if _sil == null:
		return
	_sil_ttl -= delta
	var cam := player.camera()
	var to_sil := (_sil.global_position + Vector3(0, 1.4, 0) - cam.global_position)
	if to_sil.length() < 24.0 and -cam.global_transform.basis.z.dot(to_sil.normalized()) > 0.96:
		_sil_look += delta
	if _sil_ttl <= 0.0 or _sil_look > 0.6 or to_sil.length() < 2.5:
		_sil.queue_free()
		_sil = null
		Game.toast(Loc.t("t.sil_gone"))
		Sfx.play("static", -14.0)


func entity_walk() -> void:
	if _entity != null or player == null:
		return
	_entity = Node3D.new()
	_entity.position = Vector3(14.2 if player.position.x < 0.0 else -14.2, 0, 0)
	var m := _mat(Color(0.008, 0.008, 0.01), 1.0)
	_box(Vector3(0, 0.8, 0), Vector3(0.5, 1.6, 0.4), m, false, _entity)
	_box(Vector3(0, 1.75, 0), Vector3(0.3, 0.3, 0.3), m, false, _entity)
	var eye := _glow_mat(Color(1.0, 0.1, 0.1), 2.5)
	_box(Vector3(-0.07, 1.78, 0.16), Vector3(0.03, 0.03, 0.03), eye, false, _entity)
	_box(Vector3(0.07, 1.78, 0.16), Vector3(0.03, 0.03, 0.03), eye, false, _entity)
	add_child(_entity)
	Game.toast(Loc.t("t.steps"))


func _update_entity(delta: float) -> void:
	if _entity == null:
		return
	var target := Vector3(-signf(_entity.position.x) * 15.5, 0, 0)
	_entity.position = _entity.position.move_toward(target, 1.15 * delta)
	_entity.look_at(Vector3(target.x, 1.0, target.z) + Vector3(0, 1.0, 0), Vector3.UP)
	_entity_step += delta
	if _entity_step >= 0.75:
		_entity_step = 0.0
		Sfx.play("thud", -16.0)
	var dist := _entity.position.distance_to(player.position)
	if dist < 1.7:
		_entity.queue_free()
		_entity = null
		_entity_caught()
	elif absf(_entity.position.x) >= 15.4:
		_entity.queue_free()
		_entity = null


func _entity_caught() -> void:
	Sfx.play("static", -2.0)
	await hud.fade_to(1.0, 0.08)
	player.position = Vector3(-13.0, 1.0, 6.5)
	Net.corrupt_random_file()
	Game.anomaly = minf(Game.anomaly + 5.0, 100.0)
	Game.energy = maxf(Game.energy - 25.0, 10.0)
	await get_tree().create_timer(1.4).timeout
	await hud.fade_to(0.0, 1.2)
	Game.toast(Loc.t("t.wake_bunk"))


func begin_final() -> void:
	if _final:
		return
	_final = true
	if terminal.visible:
		terminal.close()
	Game.toast(Loc.t("t.final"))
	Sfx.set_hum(false)
	Game.trip_breaker()
	await get_tree().create_timer(1.5).timeout
	for i in 8:
		await hud.flash_nocarrier(0.1 + 0.05 * i)
		Sfx.play("thud", -10.0 + i)
		await get_tree().create_timer(maxf(0.5 - 0.05 * i, 0.1)).timeout
	Game.finish("signal_lost")
