extends SceneTree
## Reverse-carrier regression tests. Run with an isolated XDG_DATA_HOME:
## godot --headless --path . -s tests/trace_test.gd

var _fail := 0


func _check(condition: bool, label: String) -> void:
	if condition:
		print("ok - ", label)
	else:
		_fail += 1
		printerr("FAIL - ", label)


func _initialize() -> void:
	call_deferred("_run")


func _run() -> void:
	var game: Node = root.get_node("Game")
	var trace: Node = root.get_node("Trace")
	var loc: Node = root.get_node("Loc")
	root.get_node("Sfx").enabled = false
	game.set_process(false)
	game.reset_all()
	game.paused = false
	_check(trace.source == 0, "first shift has an accessible tutorial source")
	var target: Vector3 = trace.SPOTS[0]
	var near: Dictionary = trace.reception(target + Vector3(0, 0, 2), Vector3.FORWARD)
	var far: Dictionary = trace.reception(target + Vector3(0, 0, 15), Vector3.FORWARD)
	var backward: Dictionary = trace.reception(target + Vector3(0, 0, 2), Vector3.BACK)
	_check(near.strength > far.strength, "signal strengthens with proximity")
	_check(near.strength > backward.strength * 10.0, "receiver is directional")
	var right: Dictionary = trace.reception(target + Vector3(-2, 0, 2), Vector3.FORWARD)
	_check(right.side > 0.0, "bearing points toward the source")

	game.anomaly = 20.0
	game.advance_minutes(60.0)
	_check(absf(game.anomaly - 20.4) < .001, "source leak uses simulation minutes, including passive decay")
	for i in 20:
		trace.update_lock(.1, true, true)
	_check(trace.lock_seconds > 1.9 and trace.source >= 0, "grounding requires sustained contact")
	trace.update_lock(.1, false, true)
	_check(trace.lock_seconds == 0.0, "looking away cancels the lock")
	trace.update_lock(.1, true, true)
	trace.update_lock(.1, true, false)
	_check(trace.lock_seconds == 0.0, "releasing E cancels the lock")
	for flag in ["paused", "asleep", "over"]:
		trace.update_lock(.1, true, true)
		game.set(flag, true)
		trace.update_lock(.1, true, true)
		_check(trace.lock_seconds == 0.0, "%s interrupts grounding" % flag)
		game.set(flag, false)
	game.energy = 5.9
	for i in 40:
		trace.update_lock(.1, true, true)
	_check(trace.source >= 0 and trace.lock_seconds == 0.0, "insufficient energy cannot close a source")
	game.energy = 40.0
	game.anomaly = 20.0
	var mails: int = game.mails.size()
	for i in 35:
		trace.update_lock(.1, true, true)
	_check(trace.source == -1 and trace.resolved == 1, "3.5 seconds grounds exactly one source")
	_check(game.energy == 34.0 and game.anomaly == 12.0, "grounding spends six energy and removes eight noise")
	_check(game.mails.size() == mails + 1, "grounding records a localized maintenance log")
	for i in 40:
		trace.update_lock(.1, true, true)
	trace.ensure_source()
	_check(trace.source == -1 and trace.resolved == 1 and game.energy == 34.0, "holding E cannot farm a closed source")

	game.day = 2
	trace.ensure_source()
	_check(trace.source == -1, "quiet shifts do not spawn a source")
	game.anomaly = 25.0
	trace.ensure_source()
	_check(trace.source == 1, "a noisy later shift rotates the source")
	game.day = 3
	trace.ensure_source()
	_check(trace.source == 1, "an unresolved source survives a shift boundary")
	trace.introduce()
	var guide_count: int = game.mails.size()
	trace.introduce()
	_check(game.mails.size() == guide_count, "receiver instructions arrive only once")
	trace.lock_seconds = 1.2
	game.save()
	trace.reset()
	game._load()
	_check(trace.source == 1 and trace.resolved == 1 and trace.introduced, "trace state survives save/load")
	_check(trace.lock_seconds == 0.0, "partial grounding never survives reload")
	trace.set_state({})
	_check(trace.source == -1 and trace.resolved == 0, "old saves migrate without a trace block")
	trace.ensure_source()
	_check(trace.source >= 0, "old saves receive a source on the next simulation step")
	for key in loc.S:
		if str(key).begins_with("trace."):
			_check(loc.S[key].has("en") and loc.S[key].has("ru"), "bilingual %s" % key)
	loc.set_lang("ru")
	_check("%" in loc.t("trace.signal", [42]), "Russian receiver readout formats correctly")
	loc.set_lang("en")

	# Build the actual station to exercise its collision gates and input wiring.
	var main: Node3D = load("res://scenes/Main.tscn").instantiate()
	root.add_child(main)
	current_scene = main
	main.player.set_physics_process(false)
	Input.mouse_mode = Input.MOUSE_MODE_VISIBLE
	await physics_frame
	await physics_frame
	for index in trace.SPOTS.size():
		trace.source = index
		var point: Vector3 = trace.SPOTS[index]
		var normal := Vector3.BACK.rotated(Vector3.UP, trace.ROTATIONS[index])
		main.player.position = point + normal * 2.0 - Vector3(0, .68, 0)
		main.player.camera().look_at(point)
		await physics_frame
		var reading: Dictionary = trace.reception(main.player.camera().global_position, -main.player.camera().global_basis.z)
		_check(main.trace_field.has_contact(reading), "junction %d can be grounded from its room" % index)
	trace.source = 0
	main.player.position = trace.SPOTS[0] - Vector3(0, .68, 1.2)
	main.player.camera().look_at(trace.SPOTS[0])
	await physics_frame
	var blocked: Dictionary = trace.reception(main.player.camera().global_position, -main.player.camera().global_basis.z)
	_check(blocked.distance < trace.REACH and not main.trace_field.has_contact(blocked), "wall blocks grounding even at close range")
	game.paused = true
	_check(not main.trace_field._available(), "pause suppresses receiver interaction")
	game.paused = false
	main.player.frozen = false
	main.trace_field.receiver_on = true
	main.trace_field._process(.016)
	_check(main.trace_field._view.visible, "receiver HUD is displayed during exploration")
	main.player.frozen = true
	main.trace_field._process(.016)
	_check(not main.trace_field._view.visible, "terminal/modal suppresses the receiver HUD")
	trace.lock_seconds = 1.0
	main.trace_field.toggle_receiver()
	_check(trace.lock_seconds == 0.0, "lowering the receiver cancels a partial lock")
	# Exercise the actual input/polling path, not only the simulation helper.
	main.player.frozen = false
	main.trace_field.receiver_on = false
	var raise := InputEventKey.new()
	raise.physical_keycode = KEY_R
	raise.pressed = true
	main.player._unhandled_input(raise)
	_check(main.trace_field.receiver_on, "R raises the receiver through player input")
	main.trace_field.set_physics_process(false)
	main.player.position = trace.SPOTS[0] + Vector3(0, -.68, 2.0)
	main.player.camera().look_at(trace.SPOTS[0])
	await physics_frame
	game.energy = 40.0
	game.anomaly = 20.0
	trace.lock_seconds = 0.0
	var before_resolved: int = trace.resolved
	var hold := InputEventKey.new()
	hold.physical_keycode = KEY_E
	hold.pressed = true
	Input.parse_input_event(hold)
	Input.flush_buffered_events()
	for i in 35:
		main.trace_field._physics_process(.1)
	var release := InputEventKey.new()
	release.physical_keycode = KEY_E
	release.pressed = false
	Input.parse_input_event(release)
	_check(trace.source == -1 and trace.resolved == before_resolved + 1,
		"holding physical E closes a visible source through field physics")
	_check(game.energy == 34.0 and game.anomaly == 12.0,
		"field grounding applies the simulation cost and relief once")
	# Never leave test saves or a deferred scene reload for the real player.
	current_scene = null
	main.queue_free()
	await process_frame
	game.reset_all()
	print("trace failures: ", _fail)
	quit(1 if _fail > 0 else 0)
