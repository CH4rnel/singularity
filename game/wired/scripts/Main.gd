extends Node3D
## Builds the Wired procedurally, wires the Lain NPC to the LainOS agent, and
## reacts to the Cyberia chain (the gate opens when the configured wallet holds
## enough CYBER). Everything is created in code so the .tscn stays trivial.

const INTERACT_RANGE := 3.0
const NPC_POS := Vector3(0.0, 1.0, -6.0)
const ORB_COUNT := 8
const FORGE_POS := Vector3(9.0, 0.0, -2.0)
const FORGE_RANGE := 3.5
const FORGE_COST := 3  # fragments spent to forge one NFT

var _player: WiredPlayer
var _npc: LainNpc
var _talking := false
var _awaiting_reply := false
var _signed := false

# Game mechanic: collect fragments → forge NFTs → NFTs open the gate
var _score := 0
var _orbs_total := 0
var _artifacts := 0   # CyberiaNFTs the player owns
var _supply := 0      # total CyberiaNFTs minted on-chain

# Forge + gallery
var _forge: Node3D
var _gallery: Node3D
var _mint_refresh_timer: Timer

# Duel state machine.
#   idle | training            (local fight, no chain)
#   auth | starting | fighting (on-chain run: model B ticket -> startRun -> act)
var _duel := "idle"
var _run := {}
var _ice_next := -1
var _await_move := false
var _game_start_ms := 0

# Node progression. Each Node is an ICE duel of a given tier; cracking one
# unlocks the next. Training Nodes are local; THE CORE is on-chain and mints
# the real NFT artifact. Built in _build_nodes().
var _nodes: Array = []
var _active_node := -1
var _train: TrainingDuel   # local tactical training fight (null when idle)
var _train_stake := 0      # fragments staked on the current fight (refunded on loss)
var _core_cracked := false # the Wired is beaten — gate stays open
var _local_gallery: Node3D
var _local_artifacts := 0

# Duel UI
var _duel_panel: PanelContainer
var _duel_title: Label
var _duel_brief: Label
var _help_panel: PanelContainer
var _duel_msg: RichTextLabel
var _duel_combat: VBoxContainer
var _php_bar: ProgressBar
var _ice_bar: ProgressBar
var _php_text: Label
var _ice_text: Label
var _energy_text: Label
var _pstatus_text: Label
var _istatus_text: Label
var _duel_info: RichTextLabel
var _duel_log: RichTextLabel
var _duel_footer: RichTextLabel
var _nodes_label: Label

# Gate (NFT-gated barrier)
var _gate_open := false
var _gate_mat: StandardMaterial3D
var _gate_collision: CollisionShape3D

# HUD
var _chain_label: Label
var _wallet_label: Label
var _score_label: Label
var _toast_label: Label
var _toast_timer: Timer
var _prompt_label: Label
var _dialogue_panel: PanelContainer
var _dialogue_log: RichTextLabel
var _input: LineEdit

# Web-only native text input. Godot's web export drops non-Latin / IME keystrokes
# (Cyrillic only types while Ctrl is held — godotengine/godot#53911, #91204), so on
# web we type into a real HTML <input> and poll its value into the LineEdit below.
var _web := false


func _ready() -> void:
	_build_environment()
	_build_ground()
	_build_gate()
	_build_forge()
	_build_orbs()
	_build_gallery_root()
	_spawn_player()
	_spawn_npc()
	_build_hud()
	_build_nodes()
	_setup_web_input()

	_mint_refresh_timer = Timer.new()
	_mint_refresh_timer.one_shot = true
	_mint_refresh_timer.wait_time = 6.0
	_mint_refresh_timer.timeout.connect(func() -> void: Nft.refresh())
	add_child(_mint_refresh_timer)

	Chain.block_updated.connect(_on_chain_changed)
	Chain.balance_updated.connect(_on_balance_changed)
	LainAgent.reply_received.connect(_on_reply)
	LainAgent.request_failed.connect(_on_reply_failed)
	Wallet.address_changed.connect(_on_wallet_address)
	Wallet.status_changed.connect(_on_wallet_status)
	Wallet.signature_received.connect(_on_wallet_signature)
	Wallet.wallet_error.connect(_on_wallet_error)
	Wallet.tx_sent.connect(_on_tx_sent)
	Wallet.tx_failed.connect(_on_tx_failed)
	Nft.balance_updated.connect(_on_nft_balance)
	Nft.supply_updated.connect(_on_nft_supply)
	Nft.owned_updated.connect(_on_nft_owned)
	Nft.mint_status.connect(_on_mint_status)
	WiredAuth.ticket_ready.connect(_on_ticket_ready)
	WiredAuth.auth_failed.connect(_on_auth_failed)
	Forge.run_updated.connect(_on_run_updated)
	Forge.ice_move.connect(_on_ice_move)
	Forge.duel_timeout.connect(_on_duel_timeout)

	_game_start_ms = Time.get_ticks_msec()


# ---------------------------------------------------------------- world build

func _build_environment() -> void:
	var env := Environment.new()
	env.background_mode = Environment.BG_COLOR
	env.background_color = Color(0.02, 0.02, 0.05)
	env.ambient_light_source = Environment.AMBIENT_SOURCE_COLOR
	env.ambient_light_color = Color(0.3, 0.3, 0.42)
	env.ambient_light_energy = 0.6
	env.fog_enabled = true
	env.fog_light_color = Color(0.05, 0.05, 0.12)
	env.fog_density = 0.015

	var we := WorldEnvironment.new()
	we.environment = env
	add_child(we)

	var sun := DirectionalLight3D.new()
	sun.rotation_degrees = Vector3(-50.0, -30.0, 0.0)
	sun.light_energy = 0.7
	sun.light_color = Color(0.7, 0.7, 0.95)
	sun.shadow_enabled = true
	add_child(sun)


func _build_ground() -> void:
	var body := StaticBody3D.new()

	var mesh := MeshInstance3D.new()
	var plane := BoxMesh.new()
	plane.size = Vector3(60.0, 0.5, 60.0)
	mesh.mesh = plane
	var mat := StandardMaterial3D.new()
	mat.albedo_color = Color(0.08, 0.09, 0.12)
	mat.metallic = 0.2
	mat.roughness = 0.7
	mesh.material_override = mat
	body.add_child(mesh)

	var shape := CollisionShape3D.new()
	var box := BoxShape3D.new()
	box.size = Vector3(60.0, 0.5, 60.0)
	shape.shape = box
	body.add_child(shape)

	body.position = Vector3(0.0, -0.25, 0.0)
	add_child(body)


func _build_gate() -> void:
	var gate := Node3D.new()

	var mesh := MeshInstance3D.new()
	var box := BoxMesh.new()
	box.size = Vector3(4.0, 4.0, 0.3)
	mesh.mesh = box
	_gate_mat = StandardMaterial3D.new()
	_gate_mat.transparency = BaseMaterial3D.TRANSPARENCY_ALPHA
	_gate_mat.albedo_color = Color(0.6, 0.1, 0.1, 0.55)
	_gate_mat.emission_enabled = true
	_gate_mat.emission = Color(0.6, 0.0, 0.0)
	mesh.material_override = _gate_mat
	gate.add_child(mesh)

	var sb := StaticBody3D.new()
	_gate_collision = CollisionShape3D.new()
	var shape := BoxShape3D.new()
	shape.size = Vector3(4.0, 4.0, 0.3)
	_gate_collision.shape = shape
	sb.add_child(_gate_collision)
	gate.add_child(sb)

	gate.position = Vector3(0.0, 2.0, -12.0)
	add_child(gate)


func _spawn_player() -> void:
	_player = WiredPlayer.new()
	_player.position = Vector3(0.0, 1.5, 4.0)
	add_child(_player)


func _spawn_npc() -> void:
	_npc = LainNpc.new()
	_npc.position = NPC_POS
	add_child(_npc)


# ----------------------------------------------------------------------- orbs

func _build_orbs() -> void:
	var rng := RandomNumberGenerator.new()
	rng.seed = 49406
	for _i in ORB_COUNT:
		var pos := Vector3(rng.randf_range(-18.0, 18.0), 1.0, rng.randf_range(-18.0, 9.0))
		_spawn_orb(pos)
	_orbs_total = ORB_COUNT


func _spawn_orb(pos: Vector3) -> void:
	var area := Area3D.new()
	area.position = pos

	var mesh := MeshInstance3D.new()
	var sphere := SphereMesh.new()
	sphere.radius = 0.3
	sphere.height = 0.6
	mesh.mesh = sphere
	var m := StandardMaterial3D.new()
	m.albedo_color = Color(0.4, 0.9, 1.0)
	m.emission_enabled = true
	m.emission = Color(0.3, 0.8, 1.0)
	m.emission_energy_multiplier = 3.0
	mesh.material_override = m
	area.add_child(mesh)

	var light := OmniLight3D.new()
	light.light_color = Color(0.4, 0.9, 1.0)
	light.light_energy = 1.5
	light.omni_range = 4.0
	area.add_child(light)

	var cs := CollisionShape3D.new()
	var shape := SphereShape3D.new()
	shape.radius = 0.6
	cs.shape = shape
	area.add_child(cs)

	area.body_entered.connect(func(body: Node) -> void: _on_orb_body(area, body))
	add_child(area)


func _on_orb_body(orb: Area3D, body: Node) -> void:
	if body != _player:
		return
	orb.queue_free()
	_score += 1
	_update_score_label()
	if _score >= _orbs_total:
		_toast("all fragments gathered. the Wired hums for you.")
	else:
		_toast("fragment collected (%d/%d)" % [_score, _orbs_total])


func _update_score_label() -> void:
	if _score_label:
		_score_label.text = "fragments: %d / %d   ·   artifacts: %d / %d minted" % [
			_score, _orbs_total, _artifacts, _supply,
		]


# ----------------------------------------------------------- forge & gallery

func _build_forge() -> void:
	_forge = Node3D.new()
	_forge.position = FORGE_POS

	var plat := MeshInstance3D.new()
	var cyl := CylinderMesh.new()
	cyl.top_radius = 1.4
	cyl.bottom_radius = 1.6
	cyl.height = 0.4
	plat.mesh = cyl
	plat.position = Vector3(0.0, 0.2, 0.0)
	var pm := StandardMaterial3D.new()
	pm.albedo_color = Color(0.1, 0.12, 0.18)
	pm.metallic = 0.6
	pm.roughness = 0.4
	plat.material_override = pm
	_forge.add_child(plat)

	var ring := MeshInstance3D.new()
	var torus := TorusMesh.new()
	torus.inner_radius = 0.7
	torus.outer_radius = 0.95
	ring.mesh = torus
	ring.position = Vector3(0.0, 1.6, 0.0)
	var rm := StandardMaterial3D.new()
	rm.albedo_color = Color(1.0, 0.7, 0.2)
	rm.emission_enabled = true
	rm.emission = Color(1.0, 0.6, 0.1)
	rm.emission_energy_multiplier = 3.0
	ring.material_override = rm
	_forge.add_child(ring)

	var light := OmniLight3D.new()
	light.light_color = Color(1.0, 0.7, 0.3)
	light.light_energy = 3.0
	light.omni_range = 8.0
	light.position = Vector3(0.0, 1.6, 0.0)
	_forge.add_child(light)

	add_child(_forge)


func _build_gallery_root() -> void:
	_gallery = Node3D.new()
	_gallery.position = Vector3(0.0, 0.0, -16.0)  # beyond the gate
	add_child(_gallery)

	# Separate row for locally-forged artifacts so an on-chain refresh can't wipe them.
	_local_gallery = Node3D.new()
	_local_gallery.position = Vector3(0.0, 0.0, -18.5)
	add_child(_local_gallery)


func _make_pedestal(id: int) -> Node3D:
	var root := Node3D.new()

	var base := MeshInstance3D.new()
	var bmesh := BoxMesh.new()
	bmesh.size = Vector3(0.8, 0.8, 0.8)
	base.mesh = bmesh
	base.position = Vector3(0.0, 0.4, 0.0)
	var bm := StandardMaterial3D.new()
	bm.albedo_color = Color(0.1, 0.1, 0.15)
	bm.roughness = 0.6
	base.material_override = bm
	root.add_child(base)

	var shard := MeshInstance3D.new()
	var smesh := BoxMesh.new()
	smesh.size = Vector3(0.5, 0.5, 0.5)
	shard.mesh = smesh
	shard.position = Vector3(0.0, 1.4, 0.0)
	shard.rotation = Vector3(0.6, 0.6, 0.0)
	var sm := StandardMaterial3D.new()
	sm.albedo_color = Color(0.9, 0.4, 0.7)
	sm.emission_enabled = true
	sm.emission = Color(0.8, 0.2, 0.5)
	sm.emission_energy_multiplier = 2.5
	shard.material_override = sm
	root.add_child(shard)

	var label := Label3D.new()
	label.text = "#%d" % id
	label.position = Vector3(0.0, 2.1, 0.0)
	label.modulate = Color(0.9, 0.9, 1.0)
	label.billboard = BaseMaterial3D.BILLBOARD_ENABLED
	root.add_child(label)

	return root


# ------------------------------------------------------------------------ HUD

func _build_hud() -> void:
	var layer := CanvasLayer.new()
	add_child(layer)

	_chain_label = Label.new()
	_chain_label.position = Vector2(16.0, 12.0)
	_chain_label.add_theme_color_override("font_color", Color(0.4, 1.0, 0.7))
	_chain_label.text = "cyberia: connecting…"
	layer.add_child(_chain_label)

	_score_label = Label.new()
	_score_label.position = Vector2(16.0, 36.0)
	_score_label.add_theme_color_override("font_color", Color(0.5, 0.85, 1.0))
	layer.add_child(_score_label)
	_update_score_label()

	_wallet_label = Label.new()
	_wallet_label.set_anchors_preset(Control.PRESET_TOP_WIDE)
	_wallet_label.offset_top = 12.0
	_wallet_label.offset_right = -16.0
	_wallet_label.horizontal_alignment = HORIZONTAL_ALIGNMENT_RIGHT
	_wallet_label.add_theme_color_override("font_color", Color(1.0, 0.8, 0.4))
	layer.add_child(_wallet_label)
	_refresh_wallet_label()

	_toast_label = Label.new()
	_toast_label.set_anchors_preset(Control.PRESET_TOP_WIDE)
	_toast_label.offset_top = 64.0
	_toast_label.horizontal_alignment = HORIZONTAL_ALIGNMENT_CENTER
	_toast_label.add_theme_color_override("font_color", Color(1.0, 1.0, 1.0))
	layer.add_child(_toast_label)

	_toast_timer = Timer.new()
	_toast_timer.one_shot = true
	_toast_timer.wait_time = 4.0
	_toast_timer.timeout.connect(func() -> void: _toast_label.text = "")
	add_child(_toast_timer)

	_prompt_label = Label.new()
	_prompt_label.add_theme_color_override("font_color", Color(1.0, 1.0, 1.0))
	_prompt_label.set_anchors_preset(Control.PRESET_BOTTOM_WIDE)
	_prompt_label.offset_top = -90.0
	_prompt_label.horizontal_alignment = HORIZONTAL_ALIGNMENT_CENTER
	_prompt_label.visible = false
	layer.add_child(_prompt_label)

	_dialogue_panel = PanelContainer.new()
	_dialogue_panel.set_anchors_preset(Control.PRESET_BOTTOM_WIDE)
	_dialogue_panel.offset_left = 40.0
	_dialogue_panel.offset_right = -40.0
	_dialogue_panel.offset_top = -220.0
	_dialogue_panel.offset_bottom = -20.0
	_dialogue_panel.visible = false
	layer.add_child(_dialogue_panel)

	var vbox := VBoxContainer.new()
	_dialogue_panel.add_child(vbox)

	_dialogue_log = RichTextLabel.new()
	_dialogue_log.bbcode_enabled = true
	_dialogue_log.scroll_active = true
	_dialogue_log.custom_minimum_size = Vector2(0.0, 150.0)
	vbox.add_child(_dialogue_log)

	_input = LineEdit.new()
	_input.placeholder_text = "ask Lain…  (Enter to send · Esc to leave)"
	_input.text_submitted.connect(_on_input_submitted)
	vbox.add_child(_input)

	_nodes_label = Label.new()
	_nodes_label.position = Vector2(16.0, 60.0)
	_nodes_label.add_theme_color_override("font_color", Color(0.8, 0.7, 1.0))
	layer.add_child(_nodes_label)

	_duel_panel = PanelContainer.new()
	_duel_panel.set_anchors_preset(Control.PRESET_CENTER)
	_duel_panel.offset_left = -320.0
	_duel_panel.offset_right = 320.0
	_duel_panel.offset_top = -180.0
	_duel_panel.offset_bottom = 180.0
	_duel_panel.visible = false
	layer.add_child(_duel_panel)

	var dmargin := MarginContainer.new()
	for side in ["margin_left", "margin_right", "margin_top", "margin_bottom"]:
		dmargin.add_theme_constant_override(side, 20)
	_duel_panel.add_child(dmargin)

	var dvbox := VBoxContainer.new()
	dvbox.add_theme_constant_override("separation", 12)
	dmargin.add_child(dvbox)

	_duel_title = Label.new()
	_duel_title.add_theme_color_override("font_color", Color(1.0, 0.8, 0.4))
	dvbox.add_child(_duel_title)

	_duel_brief = Label.new()
	_duel_brief.add_theme_color_override("font_color", Color(0.65, 0.95, 0.8))
	dvbox.add_child(_duel_brief)

	_duel_msg = RichTextLabel.new()
	_duel_msg.bbcode_enabled = true
	_duel_msg.fit_content = true
	_duel_msg.custom_minimum_size = Vector2(580.0, 110.0)
	dvbox.add_child(_duel_msg)

	_duel_combat = VBoxContainer.new()
	_duel_combat.add_theme_constant_override("separation", 8)
	dvbox.add_child(_duel_combat)

	_php_bar = _make_bar(Color(0.35, 0.8, 1.0))
	_php_text = Label.new()
	_duel_combat.add_child(_bar_row("YOU", _php_bar, _php_text))

	_energy_text = Label.new()
	_energy_text.add_theme_color_override("font_color", Color(1.0, 0.85, 0.4))
	_duel_combat.add_child(_energy_text)

	_pstatus_text = Label.new()
	_pstatus_text.add_theme_color_override("font_color", Color(0.7, 0.85, 1.0))
	_duel_combat.add_child(_pstatus_text)

	_ice_bar = _make_bar(Color(1.0, 0.4, 0.55))
	_ice_text = Label.new()
	_duel_combat.add_child(_bar_row("ICE", _ice_bar, _ice_text))

	_istatus_text = Label.new()
	_istatus_text.add_theme_color_override("font_color", Color(1.0, 0.7, 0.7))
	_duel_combat.add_child(_istatus_text)

	_duel_info = RichTextLabel.new()
	_duel_info.bbcode_enabled = true
	_duel_info.fit_content = true
	_duel_info.custom_minimum_size = Vector2(580.0, 44.0)
	_duel_combat.add_child(_duel_info)

	_duel_log = RichTextLabel.new()
	_duel_log.bbcode_enabled = true
	_duel_log.fit_content = true
	_duel_log.custom_minimum_size = Vector2(580.0, 60.0)
	_duel_combat.add_child(_duel_log)

	_duel_footer = RichTextLabel.new()
	_duel_footer.bbcode_enabled = true
	_duel_footer.fit_content = true
	_duel_footer.custom_minimum_size = Vector2(580.0, 28.0)
	_duel_combat.add_child(_duel_footer)

	_build_help_panel(layer)


func _build_help_panel(layer: CanvasLayer) -> void:
	_help_panel = PanelContainer.new()
	_help_panel.set_anchors_preset(Control.PRESET_CENTER)
	_help_panel.offset_left = -340.0
	_help_panel.offset_right = 340.0
	_help_panel.offset_top = -220.0
	_help_panel.offset_bottom = 220.0
	_help_panel.visible = false
	layer.add_child(_help_panel)  # added after the duel panel → draws on top

	var hm := MarginContainer.new()
	for side in ["margin_left", "margin_right", "margin_top", "margin_bottom"]:
		hm.add_theme_constant_override(side, 22)
	_help_panel.add_child(hm)

	var help := RichTextLabel.new()
	help.bbcode_enabled = true
	help.fit_content = true
	help.custom_minimum_size = Vector2(620.0, 400.0)
	help.text = "\n".join([
		"[b]HOW THE WIRED FIGHTS[/b]",
		"",
		"Read the ICE's [color=#ffaa55]INTENT[/color] each turn and answer it. Energy ◆ caps your moves — [b]Guard[/b] refunds +2, so you bank energy to burst later.",
		"",
		"[b]Your moves[/b]",
		"[color=#ffffff][1] Strike[/color]   cheap, steady damage  (weak vs armor)",
		"[color=#ffffff][2] Guard[/color]    block the incoming hit + regain energy",
		"[color=#ffffff][3] Overload[/color] big burst — [b]+50%[/b] vs a charging ICE",
		"[color=#ffffff][4] Pierce[/color]   damage that [b]ignores armor[/b]",
		"[color=#ffffff][5] Virus[/color]    poison: damage every turn, ignores armor & repair — [b]stack it[/b]",
		"[color=#ffffff][6] Patch[/color]    heal + cleanse corruption",
		"",
		"[b]Counters[/b]",
		"charging NUKE   →  Guard it, or burst it while it's exposed",
		"armor           →  Pierce",
		"self-repair     →  Virus (out-bleed the heal)",
		"corruption      →  Patch",
		"",
		"[color=#9fb0c0][Tab] close[/color]",
	])
	hm.add_child(help)


func _process(_delta: float) -> void:
	if _web and _talking:
		_poll_web_input()
	if _talking or _player == null:
		return
	if _duel != "idle":
		_prompt_label.visible = false
		return
	# On web the cursor isn't captured until the player clicks — guide them.
	if Input.mouse_mode != Input.MOUSE_MODE_CAPTURED:
		_prompt_label.visible = true
		_prompt_label.text = "click to look around"
		return
	var hint := ""
	var near_npc := _npc != null and _player.global_position.distance_to(_npc.global_position) <= INTERACT_RANGE
	var near_node := _nearest_node_in_range()
	if near_npc:
		hint = "[E] talk to Lain"
		if Wallet.available() and Wallet.is_connected_wallet() and not _signed:
			hint += "      [F] sign the Wired"
	elif near_node >= 0:
		hint = _node_prompt(near_node)
	elif Wallet.available() and not Wallet.is_connected_wallet():
		hint = "[C] connect wallet"
	_prompt_label.visible = hint != ""
	_prompt_label.text = hint


func _unhandled_input(event: InputEvent) -> void:
	var key := event as InputEventKey
	if key == null or not key.pressed or key.echo:
		return
	if _duel == "training":
		if _help_panel.visible:
			if key.physical_keycode in [KEY_TAB, KEY_H, KEY_ESCAPE]:
				_help_panel.visible = false
			return  # swallow combat input while the help overlay is open
		var ab := -1
		match key.physical_keycode:
			KEY_1: ab = 0
			KEY_2: ab = 1
			KEY_3: ab = 2
			KEY_4: ab = 3
			KEY_5: ab = 4
			KEY_6: ab = 5
			KEY_TAB, KEY_H: _help_panel.visible = true; return
			KEY_ESCAPE: _on_training_loss(); return  # bail out of a training fight
		if ab >= 0:
			_train_act(ab)
		return
	if _duel == "fighting":
		if not _await_move:
			if key.physical_keycode == KEY_1:
				_send_move(0)
			elif key.physical_keycode == KEY_2:
				_send_move(1)
			elif key.physical_keycode == KEY_3:
				_send_move(2)
		return
	if _duel != "idle":
		return  # auth / starting: swallow input until the duel begins
	if key.physical_keycode == KEY_E and not _talking:
		if _player and _npc and _player.global_position.distance_to(_npc.global_position) <= INTERACT_RANGE:
			_start_dialogue()
	elif key.physical_keycode == KEY_C and not _talking:
		Wallet.connect_wallet()
		if Wallet.available():
			_toast("opening your wallet… approve the connection")
	elif key.physical_keycode == KEY_F and not _talking:
		_try_sign()
	elif key.physical_keycode == KEY_M and not _talking:
		_challenge_nearest_node()
	elif key.physical_keycode == KEY_ESCAPE:
		if _talking:
			_end_dialogue()
		else:
			# toggle the cursor free so the window can be closed comfortably
			Input.mouse_mode = (
				Input.MOUSE_MODE_VISIBLE
				if Input.mouse_mode == Input.MOUSE_MODE_CAPTURED
				else Input.MOUSE_MODE_CAPTURED
			)


# ------------------------------------------------------------------- dialogue

func _start_dialogue() -> void:
	_talking = true
	_player.frozen = true
	_prompt_label.visible = false
	_dialogue_panel.visible = true
	Input.mouse_mode = Input.MOUSE_MODE_VISIBLE
	_input.editable = true
	if _web:
		_input.text = ""
		_web_call("window._wiredInputShow && window._wiredInputShow()")
	else:
		_input.grab_focus()
	if _dialogue_log.text == "":
		_append("[color=#ff5fa0]lain[/color]: " + _npc.get_greeting())


func _end_dialogue() -> void:
	_talking = false
	_player.frozen = false
	_dialogue_panel.visible = false
	Input.mouse_mode = Input.MOUSE_MODE_CAPTURED
	if _web:
		_web_call("window._wiredInputHide && window._wiredInputHide()")


func _on_input_submitted(text: String) -> void:
	text = text.strip_edges()
	if text == "" or _awaiting_reply:
		return
	_append("[color=#5fd0ff]you[/color]: " + text)
	_input.clear()
	_awaiting_reply = true
	_input.editable = false
	_input.placeholder_text = "lain is thinking…"
	if _web:
		_web_call("window._wiredInputBusy && window._wiredInputBusy(true)")
	LainAgent.chat(text)


func _on_reply(text: String) -> void:
	_awaiting_reply = false
	_append("[color=#ff5fa0]lain[/color]: " + text)
	_reset_input()


func _on_reply_failed(message: String) -> void:
	_awaiting_reply = false
	_append("[color=#ff6666](offline)[/color] " + message)
	_reset_input()


func _reset_input() -> void:
	_input.editable = true
	_input.placeholder_text = "ask Lain…  (Enter to send · Esc to leave)"
	if _talking:
		if _web:
			_web_call("window._wiredInputBusy && window._wiredInputBusy(false)")
		else:
			_input.grab_focus()


# --------------------------------------------------------------- web text input
# Sidesteps the engine's broken web keyboard/IME path: a native HTML <input>
# overlays the canvas and captures real keystrokes (Cyrillic, IME, mobile soft
# keyboards). Godot's keyboard listeners live on the <canvas>, so events on this
# sibling <input> never reach them. We poll the input each frame (no JS→GDScript
# callbacks, which proved fragile) and copy its value into the display-only LineEdit.

const _WEB_INPUT_JS := """
(function(){
	if (window._wiredInput) return;
	var inp = document.createElement('input');
	inp.type = 'text';
	inp.id = 'wired-chat-input';
	inp.autocomplete = 'off';
	inp.autocapitalize = 'sentences';
	inp.setAttribute('autocorrect', 'off');
	inp.setAttribute('spellcheck', 'false');
	inp.setAttribute('enterkeyhint', 'send');
	var s = inp.style;
	s.position = 'fixed'; s.left = '0px'; s.bottom = '0px';
	s.width = '1px'; s.height = '1px';
	s.opacity = '0'; s.zIndex = '2147483647';
	s.border = '0'; s.padding = '0';
	s.background = 'transparent'; s.color = 'transparent'; s.caretColor = 'transparent';
	document.body.appendChild(inp);
	window._wiredInput = inp;
	window._wiredActive = false;
	window._wiredSubmit = false;
	window._wiredEscape = false;
	var stop = function(e){ e.stopPropagation(); };
	inp.addEventListener('keyup', stop, false);
	inp.addEventListener('keypress', stop, false);
	inp.addEventListener('keydown', function(e){
		e.stopPropagation();
		if (e.key === 'Enter') { e.preventDefault(); window._wiredSubmit = true; }
		else if (e.key === 'Escape') { e.preventDefault(); window._wiredEscape = true; }
	}, false);
	var canvasFocus = function(){ var c = document.getElementById('canvas') || document.querySelector('canvas'); if (c) { try { c.focus(); } catch(e){} } };
	window._wiredInputShow = function(){ var i = window._wiredInput; if (!i) return; i.disabled = false; i.value = ''; window._wiredActive = true; window._wiredSubmit = false; window._wiredEscape = false; try { i.focus({preventScroll:true}); } catch(e){ i.focus(); } };
	window._wiredInputHide = function(){ window._wiredActive = false; var i = window._wiredInput; if (i) i.blur(); canvasFocus(); };
	window._wiredInputBusy = function(b){ var i = window._wiredInput; if (!i) return; i.disabled = !!b; if (b) { i.value = ''; window._wiredActive = false; i.blur(); } else { window._wiredActive = true; try { i.focus(); } catch(e){} } };
	// Called once per frame from GDScript: keep focus, drain flags, report value.
	window._wiredPoll = function(){
		var i = window._wiredInput; if (!i) return '';
		if (window._wiredActive && document.activeElement !== i) { try { i.focus(); } catch(e){} }
		var sub = window._wiredSubmit; window._wiredSubmit = false;
		var esc = window._wiredEscape; window._wiredEscape = false;
		return JSON.stringify({ v: i.value, s: !!sub, e: !!esc });
	};
})();
"""


func _setup_web_input() -> void:
	_web = OS.has_feature("web")
	if not _web:
		return
	# Keep the Godot LineEdit from ever grabbing keyboard focus — its IME path is
	# what drops Cyrillic. It stays purely as the visible field; the HTML input types.
	_input.focus_mode = Control.FOCUS_NONE
	JavaScriptBridge.eval(_WEB_INPUT_JS, true)


func _web_call(js: String) -> void:
	if _web:
		JavaScriptBridge.eval(js, true)


func _poll_web_input() -> void:
	var raw: Variant = JavaScriptBridge.eval("window._wiredPoll ? window._wiredPoll() : ''", true)
	if typeof(raw) != TYPE_STRING or raw == "":
		return
	var data: Variant = JSON.parse_string(raw)
	if typeof(data) != TYPE_DICTIONARY:
		return
	if bool(data.get("e", false)):
		_end_dialogue()
		return
	if bool(data.get("s", false)):
		_on_input_submitted(String(data.get("v", "")))
		return
	if not _awaiting_reply:
		var v := String(data.get("v", ""))
		if _input.text != v:
			_input.text = v
			_input.caret_column = v.length()


func _append(bbcode: String) -> void:
	_dialogue_log.append_text(bbcode + "\n")


# --------------------------------------------------------------------- chain

func _on_chain_changed(_block: int) -> void:
	_refresh_chain_label()


func _on_balance_changed(_address: String, _cyber: float) -> void:
	_refresh_chain_label()


func _refresh_chain_label() -> void:
	var line := "cyberia · block %d" % Chain.latest_block
	if Chain.player_address != "":
		line += " · %s: %.4f CYBER" % [_short(Chain.player_address), Chain.player_cyber]
	else:
		line += " · connect a wallet [C] to unlock the gate"
	_chain_label.text = line


func _set_gate_open(is_open: bool) -> void:
	if is_open == _gate_open:
		return
	_gate_open = is_open
	_gate_collision.disabled = is_open
	if is_open:
		_gate_mat.albedo_color = Color(0.1, 0.7, 0.2, 0.2)
		_gate_mat.emission = Color(0.0, 0.7, 0.2)
		_toast("the gate knows your mark. it opens.")
	else:
		_gate_mat.albedo_color = Color(0.6, 0.1, 0.1, 0.55)
		_gate_mat.emission = Color(0.6, 0.0, 0.0)


func _short(addr: String) -> String:
	if addr.length() <= 12:
		return addr
	return addr.substr(0, 6) + "…" + addr.substr(addr.length() - 4)


# -------------------------------------------------------------------- wallet

func _on_wallet_address(address: String) -> void:
	# Link the connected wallet to the chain + NFT layers so HUD and gate react.
	Chain.set_player(address)
	Nft.set_player(address)
	_signed = false
	if address != "":
		_toast("wallet linked: %s" % _short(address))
	else:
		_toast("wallet disconnected")
		_set_gate_open(false)
	_refresh_wallet_label()
	_refresh_chain_label()


func _on_wallet_status(_status: String) -> void:
	_refresh_wallet_label()


func _on_wallet_signature(signature: String) -> void:
	_signed = true
	_toast("Lain: your mark is on the Wired now. (%s…)" % signature.substr(0, 12))


func _on_wallet_error(message: String) -> void:
	_toast("wallet: %s" % message)


func _try_sign() -> void:
	if not Wallet.available():
		_toast("wallet works only in the browser build")
		return
	if not Wallet.is_connected_wallet():
		_toast("connect a wallet first  ·  [C]")
		return
	var msg := "I enter the Wired as %s — Cyberia %d" % [Wallet.get_address(), Chain.CHAIN_ID]
	Wallet.sign(msg)
	_toast("check your wallet to sign the ritual…")


func _refresh_wallet_label() -> void:
	if _wallet_label == null:
		return
	if not Wallet.available():
		_wallet_label.text = "wallet: browser build only"
	elif Wallet.is_connected_wallet():
		_wallet_label.text = "wallet: %s ◆ Cyberia" % _short(Wallet.get_address())
	else:
		_wallet_label.text = "wallet: not connected  ·  [C] connect"


func _toast(message: String) -> void:
	if _toast_label == null:
		return
	_toast_label.text = message
	if _toast_timer:
		_toast_timer.start()


# ----------------------------------------------------------------- nft game

# ----------------------------------------------------------------- nodes

func _build_nodes() -> void:
	# The chain of ICE Nodes. Crack one to unlock the next; THE CORE is the
	# on-chain finale that mints the real artifact. Existing _forge = CORE body.
	_nodes = [
		{"name": "NODE α", "short": "α", "tier": 1, "pos": Vector3(-8.0, 0.0, 3.0), "chain": false, "cracked": false},
		{"name": "NODE β", "short": "β", "tier": 2, "pos": Vector3(-11.0, 0.0, -7.0), "chain": false, "cracked": false},
		{"name": "NODE γ", "short": "γ", "tier": 3, "pos": Vector3(5.0, 0.0, -11.0), "chain": false, "cracked": false},
		{"name": "THE CORE", "short": "CORE", "tier": 4, "pos": FORGE_POS, "chain": true, "cracked": false, "body": _forge},
	]
	for i in _nodes.size():
		if not _nodes[i].has("body"):
			_nodes[i]["body"] = _spawn_node_visual(_nodes[i])
		_refresh_node_visual(i)
	_refresh_nodes_hud()


func _spawn_node_visual(n: Dictionary) -> Node3D:
	var root := Node3D.new()
	root.position = n["pos"]

	var mat := StandardMaterial3D.new()
	mat.metallic = 0.5
	mat.roughness = 0.4
	mat.emission_enabled = true
	n["mat"] = mat

	var obelisk := MeshInstance3D.new()
	var box := BoxMesh.new()
	box.size = Vector3(1.0, 3.0, 1.0)
	obelisk.mesh = box
	obelisk.position = Vector3(0.0, 1.5, 0.0)
	obelisk.material_override = mat
	root.add_child(obelisk)

	var ring := MeshInstance3D.new()
	var torus := TorusMesh.new()
	torus.inner_radius = 0.6
	torus.outer_radius = 0.85
	ring.mesh = torus
	ring.position = Vector3(0.0, 3.3, 0.0)
	ring.material_override = mat
	root.add_child(ring)

	var light := OmniLight3D.new()
	light.omni_range = 7.0
	light.light_energy = 2.0
	light.position = Vector3(0.0, 2.0, 0.0)
	n["light"] = light
	root.add_child(light)

	var label := Label3D.new()
	label.text = "%s\ntier %d" % [n["name"], int(n["tier"])]
	label.position = Vector3(0.0, 4.0, 0.0)
	label.billboard = BaseMaterial3D.BILLBOARD_ENABLED
	label.modulate = Color(0.9, 0.9, 1.0)
	root.add_child(label)

	add_child(root)
	return root


func _refresh_node_visual(i: int) -> void:
	var n: Dictionary = _nodes[i]
	if not n.has("mat"):
		return  # CORE keeps the forge's own look
	var col: Color
	if n["cracked"]:
		col = Color(0.2, 0.9, 0.4)
	elif _node_unlocked(i):
		col = Color(0.3, 0.7, 1.0)
	else:
		col = Color(0.55, 0.12, 0.16)
	var mat: StandardMaterial3D = n["mat"]
	mat.albedo_color = col.darkened(0.4)
	mat.emission = col
	mat.emission_energy_multiplier = 2.5
	if n.has("light"):
		(n["light"] as OmniLight3D).light_color = col


func _node_unlocked(i: int) -> bool:
	return i == 0 or bool(_nodes[i - 1]["cracked"])


func _refresh_nodes_hud() -> void:
	if _nodes_label == null:
		return
	var parts: Array = []
	for i in _nodes.size():
		var n: Dictionary = _nodes[i]
		var mark := "✓" if n["cracked"] else ("▶" if _node_unlocked(i) else "✕")
		parts.append("%s%s" % [n["short"], mark])
	_nodes_label.text = "nodes:  " + "   ".join(parts)


func _nearest_node_in_range() -> int:
	if _player == null:
		return -1
	var best := -1
	var best_d := FORGE_RANGE
	for i in _nodes.size():
		var p: Vector3 = _nodes[i]["pos"]
		var d := _player.global_position.distance_to(p)
		if d <= best_d:
			best_d = d
			best = i
	return best


func _node_prompt(i: int) -> String:
	var n: Dictionary = _nodes[i]
	if n["cracked"]:
		return "%s · cracked ✓" % n["name"]
	if not _node_unlocked(i):
		return "%s — locked (crack %s first)" % [n["name"], _nodes[i - 1]["name"]]
	if n["chain"]:
		if _score < FORGE_COST:
			return "%s — need %d fragments for the final breach (have %d)" % [n["name"], FORGE_COST, _score]
		return "[M] breach %s — the final boss" % n["name"]
	if _score < 1:
		return "%s — need 1 fragment to jack in (collect orbs)" % n["name"]
	return "[M] jack into %s   (tier %d · training)" % [n["name"], int(n["tier"])]


func _challenge_nearest_node() -> void:
	if _duel != "idle":
		return
	var i := _nearest_node_in_range()
	if i < 0:
		return
	var n: Dictionary = _nodes[i]
	if n["cracked"]:
		_toast("%s is already cracked." % n["name"])
		return
	if not _node_unlocked(i):
		_toast("%s is locked — crack %s first." % [n["name"], _nodes[i - 1]["name"]])
		return
	# Every Node (incl. THE CORE finale) is the local tactical fight — beatable
	# without any wallet/server. The real on-chain WiredForge mint stays an
	# optional extra (needs the wired auth server + a wallet); it never gates
	# finishing the game.
	_start_training(i)


# --------------------------------------------------------- training duel

func _start_training(i: int) -> void:
	var stake: int = FORGE_COST if bool(_nodes[i]["chain"]) else 1
	if _score < stake:
		_toast("need %d fragment%s to jack in — collect orbs." % [stake, "" if stake == 1 else "s"])
		return
	_score -= stake  # entry stake; refunded if the ICE wins
	_train_stake = stake
	_update_score_label()
	_active_node = i
	_duel = "training"
	_player.frozen = true
	_train = TrainingDuel.new(int(_nodes[i]["tier"]), randi())
	_render_training()


func _train_act(ab: int) -> void:
	if _train == null or _train.over:
		return
	if not _train.can_afford(ab):
		_toast("not enough energy for %s (need %d)" % [TrainingDuel.ANAMES[ab], _train.cost(ab)])
		return
	_train.act(ab)
	if _train.over:
		if _train.won:
			_on_training_win()
		else:
			_on_training_loss()
	else:
		_render_training()


func _on_training_win() -> void:
	var i := _active_node
	_nodes[i]["cracked"] = true
	_refresh_node_visual(i)
	if i + 1 < _nodes.size():
		_refresh_node_visual(i + 1)
	_refresh_nodes_hud()
	var is_core := bool(_nodes[i]["chain"])
	_end_training()
	if is_core:
		# The Wired is cracked — game won. Open the gate and forge the artifact.
		_core_cracked = true
		_set_gate_open(true)
		_forge_local_artifact()
		_toast("THE CORE is cracked. you beat the Wired — an artifact is forged. (on-chain mint: optional)")
	else:
		var tail := ""
		if i + 1 < _nodes.size():
			tail = "  %s is unlocked." % _nodes[i + 1]["name"]
		_toast("%s cracked ✓.%s" % [_nodes[i]["name"], tail])


func _on_training_loss() -> void:
	_score += _train_stake  # refund the entry stake — no progress, no penalty
	_update_score_label()
	_toast("the ICE severed the link. ejected — %d fragment%s refunded." % [_train_stake, "" if _train_stake == 1 else "s"])
	_end_training()


func _end_training() -> void:
	_duel = "idle"
	_train = null
	_active_node = -1
	_train_stake = 0
	if _player:
		_player.frozen = false
	_hide_duel()


## Drop a forged artifact onto a pedestal in the local gallery (the victory trophy).
func _forge_local_artifact() -> void:
	if _local_gallery == null:
		return
	_local_artifacts += 1
	var pedestal := _make_pedestal(_local_artifacts)
	pedestal.position = Vector3((float(_local_artifacts) - 1.0) * 2.5, 0.0, 0.0)
	_local_gallery.add_child(pedestal)


func _pips(n: int, total: int) -> String:
	var s := ""
	for i in total:
		s += "◆" if i < n else "◇"
	return s


func _render_training() -> void:
	var t := _train
	_duel_panel.visible = true
	_duel_msg.visible = false
	_duel_combat.visible = true
	_energy_text.visible = true
	_pstatus_text.visible = true
	_istatus_text.visible = true
	_duel_log.visible = true

	_duel_title.text = "%s · %s    [training · tier %d]" % [_nodes[_active_node]["name"], t.arch_name, t.tier]
	_duel_brief.visible = true
	_duel_brief.text = t.counter_brief() + "      ·      [Tab] help"

	_php_bar.max_value = TrainingDuel.MAX_HP
	_php_bar.value = t.hp
	_php_text.text = "%d / %d" % [t.hp, TrainingDuel.MAX_HP]
	_energy_text.text = "energy  " + _pips(t.energy, TrainingDuel.E_MAX)

	var ps: Array = []
	if t.block > 0:
		ps.append("block %d" % t.block)
	if t.corrupt > 0:
		ps.append("corrupt -%d (%d turns)" % [t.corrupt_dmg, t.corrupt])
	_pstatus_text.text = ("status: " + "  ·  ".join(ps)) if ps.size() > 0 else " "

	_ice_bar.max_value = t.ice_max
	_ice_bar.value = t.ice_hp
	_ice_text.text = "%d / %d" % [t.ice_hp, t.ice_max]
	var ist: Array = []
	if t.armor > 0:
		ist.append("armor %d" % t.armor)
	if t.bleed > 0:
		ist.append("bleed %d/turn" % t.bleed)
	_istatus_text.text = ("ICE: " + "  ·  ".join(ist)) if ist.size() > 0 else " "

	var icol := "ff5555" if (t.nuke or t.intent_type == "charge") else "ffaa55"
	_duel_info.text = "INTENT:  [color=#%s]%s[/color]" % [icol, t.intent_label()]

	var lines: Array = []
	for ln in t.logs:
		lines.append("[color=#9fb0c0]%s[/color]" % ln)
	if lines.is_empty():
		lines.append("[color=#ffd86b]read the INTENT below and answer it. press [Tab] for help.[/color]")
	_duel_log.text = "\n".join(lines)

	var parts: Array = []
	for ab in 6:
		var col := "ffffff" if t.can_afford(ab) else "555c6b"
		parts.append("[color=#%s][%d] %s(%d)[/color]" % [col, ab + 1, TrainingDuel.ANAMES[ab], t.cost(ab)])
	_duel_footer.text = "   ".join(parts)


# ------------------------------------------------------------ duel UI bars

func _make_bar(fill: Color) -> ProgressBar:
	var bar := ProgressBar.new()
	bar.show_percentage = false
	bar.custom_minimum_size = Vector2(380.0, 16.0)
	var bg := StyleBoxFlat.new()
	bg.bg_color = Color(0.1, 0.1, 0.14)
	bg.set_corner_radius_all(3)
	var fg := StyleBoxFlat.new()
	fg.bg_color = fill
	fg.set_corner_radius_all(3)
	bar.add_theme_stylebox_override("background", bg)
	bar.add_theme_stylebox_override("fill", fg)
	return bar


func _bar_row(tag: String, bar: ProgressBar, value: Label) -> HBoxContainer:
	var row := HBoxContainer.new()
	row.add_theme_constant_override("separation", 10)
	var tagl := Label.new()
	tagl.text = tag
	tagl.custom_minimum_size = Vector2(46.0, 0.0)
	row.add_child(tagl)
	row.add_child(bar)
	value.custom_minimum_size = Vector2(70.0, 0.0)
	row.add_child(value)
	return row


func _render_combat(node_idx: int, tier: int, php: int, ice: int, turn: int, maxt: int, ice_next: int, awaiting: bool) -> void:
	var n: Dictionary = _nodes[node_idx] if node_idx >= 0 and node_idx < _nodes.size() else {}
	var is_chain := bool(n.get("chain", false))
	_duel_panel.visible = true
	_duel_msg.visible = false
	_duel_combat.visible = true
	# Training-only widgets stay hidden for the raw on-chain protocol fight.
	_duel_brief.visible = false
	_energy_text.visible = false
	_pstatus_text.visible = false
	_istatus_text.visible = false
	_duel_log.visible = false

	_duel_title.text = "%s · tier %d    [%s]" % [
		String(n.get("name", "NODE")), tier, "on-chain · mints NFT" if is_chain else "training",
	]
	_php_bar.max_value = DuelRules.PLAYER_HP
	_php_bar.value = php
	_php_text.text = "%d / %d" % [php, DuelRules.PLAYER_HP]
	var imax := DuelRules.ice_hp(tier)
	_ice_bar.max_value = imax
	_ice_bar.value = ice
	_ice_text.text = "%d / %d" % [ice, imax]

	var ice_txt := DuelRules.move_name(ice_next) if ice_next >= 0 else "reading…"
	var info := "turn %d / %d\n\n" % [turn, maxt]
	info += "ICE next move: [color=#ffaa55]%s[/color]\n" % ice_txt
	info += "[color=#9fb0c0]%s[/color]" % DuelRules.ice_hint(ice_next)
	_duel_info.text = info
	_duel_footer.text = "sending move… approve in wallet" if awaiting else "[1] Strike    [2] Guard    [3] Overload"


func _on_nft_balance(count: int) -> void:
	_artifacts = count
	_update_score_label()
	_set_gate_open(count >= 1 or _core_cracked)


func _on_nft_supply(total: int) -> void:
	_supply = total
	_update_score_label()


func _on_nft_owned(ids: Array) -> void:
	if _gallery == null:
		return
	for child in _gallery.get_children():
		child.queue_free()
	var n := ids.size()
	for i in n:
		var pedestal := _make_pedestal(int(ids[i]))
		pedestal.position = Vector3((float(i) - (n - 1) / 2.0) * 2.5, 0.0, 0.0)
		_gallery.add_child(pedestal)


func _on_mint_status(text: String) -> void:
	_toast(text)


func _on_tx_sent(tx_hash: String) -> void:
	_toast("tx %s… submitted" % tx_hash.substr(0, 12))


func _on_tx_failed(message: String) -> void:
	_toast("wallet: %s" % message)
	if _duel == "starting":
		# startRun was rejected before the duel began — refund the stake.
		_score += FORGE_COST
		_update_score_label()
		_reset_duel()
	elif _duel == "fighting":
		_await_move = false  # move rejected; let the player choose again
		_render_duel()


# -------------------------------------------------------------------- duel

func _on_ticket_ready(start_calldata: String) -> void:
	if _duel != "auth":
		return
	# Entry granted — pay the fragment stake and broadcast startRun.
	_score = max(0, _score - FORGE_COST)
	_update_score_label()
	_duel = "starting"
	_show_duel("[b]ticket signed.[/b]\n\napprove [b]startRun[/b] in your wallet to enter the Node…")
	Forge.start_run(start_calldata, Wallet.get_address())


func _on_auth_failed(message: String) -> void:
	if _duel == "auth":
		_toast("entry refused: %s" % message)
		_reset_duel()


func _on_run_updated(run: Dictionary) -> void:
	_run = run
	if _duel == "starting":
		if bool(run.get("active", false)):
			_duel = "fighting"
			_await_move = false
			_ice_next = -1
			_render_duel()
	elif _duel == "fighting":
		_await_move = false
		if bool(run.get("active", false)):
			_render_duel()
		else:
			_end_duel(run)


func _on_ice_move(_turn: int, move: int) -> void:
	_ice_next = move
	if _duel == "fighting":
		_render_duel()


func _on_duel_timeout() -> void:
	if _duel == "idle":
		return
	_toast("the Node went quiet — tx may have been rejected. leaving.")
	_reset_duel()


func _send_move(move: int) -> void:
	if _duel != "fighting" or _await_move:
		return
	_await_move = true
	_render_duel()
	Forge.act(move)


func _end_duel(run: Dictionary) -> void:
	if int(run.get("iceHp", 1)) == 0:
		if _active_node >= 0:
			_nodes[_active_node]["cracked"] = true
			_refresh_node_visual(_active_node)
			_refresh_nodes_hud()
		_toast("THE CORE is cracked. an artifact is forged for you.")
		Nft.refresh()
		if _mint_refresh_timer:
			_mint_refresh_timer.start()
	else:
		_toast("the ICE traced you and severed the link. ejected.")
	_reset_duel()


func _reset_duel() -> void:
	_duel = "idle"
	_await_move = false
	_run = {}
	_ice_next = -1
	_active_node = -1
	if _player:
		_player.frozen = false
	_hide_duel()


func _show_duel(text: String) -> void:
	if _duel_panel == null:
		return
	_duel_panel.visible = true
	_duel_combat.visible = false
	_duel_brief.visible = false
	_duel_msg.visible = true
	_duel_msg.text = text


func _hide_duel() -> void:
	if _duel_panel:
		_duel_panel.visible = false
	if _help_panel:
		_help_panel.visible = false


func _render_duel() -> void:
	if _run.is_empty():
		return
	_render_combat(
		_active_node,
		int(_run.get("tier", 1)),
		int(_run.get("playerHp", 0)),
		int(_run.get("iceHp", 0)),
		int(_run.get("turn", 0)),
		int(_run.get("maxTurns", 0)),
		_ice_next,
		_await_move,
	)
