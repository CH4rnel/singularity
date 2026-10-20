class_name NcTraceField
extends Node3D
## Ordinary junctions become impossible spaces through the passive receiver.
## Closing a source requires proximity, aim AND a physics ray against that
## junction, so a radio bearing through a wall cannot ground it through a wall.

const BREACH_SHADER := preload("res://shaders/carrier_breach.gdshader")
var main: Node3D
var receiver_on := false
var _panels: Array[MeshInstance3D] = []
var _materials: Array[ShaderMaterial] = []
var _lights: Array[OmniLight3D] = []
var _view: NcReceiver
var _phase := 0.0
var _beep_left := 0.0
var _contact := false


func _ready() -> void:
	for i in Trace.SPOTS.size():
		_build_junction(i)
	var layer := CanvasLayer.new()
	layer.layer = 9
	add_child(layer)
	_view = NcReceiver.new()
	layer.add_child(_view)
	_view.visible = false


func _build_junction(index: int) -> void:
	var junction := Node3D.new()
	junction.position = Trace.SPOTS[index]
	junction.rotation.y = Trace.ROTATIONS[index]
	add_child(junction)
	var housing := MeshInstance3D.new()
	var box := BoxMesh.new()
	box.size = Vector3(.95, 1.22, .16)
	housing.mesh = box
	var metal := StandardMaterial3D.new()
	metal.albedo_color = Color(.13, .17, .16)
	metal.metallic = .65
	metal.roughness = .65
	housing.material_override = metal
	junction.add_child(housing)
	var body := StaticBody3D.new()
	body.set_meta("trace_source", index)
	var collision := CollisionShape3D.new()
	var shape := BoxShape3D.new()
	shape.size = box.size
	collision.shape = shape
	body.add_child(collision)
	junction.add_child(body)

	var panel := MeshInstance3D.new()
	var quad := QuadMesh.new()
	quad.size = Vector2(.79, 1.04)
	panel.mesh = quad
	panel.position.z = .087
	var material := ShaderMaterial.new()
	material.shader = BREACH_SHADER
	panel.material_override = material
	panel.visible = false
	junction.add_child(panel)
	_panels.append(panel)
	_materials.append(material)

	var label := Label3D.new()
	label.text = "J-%02d / 07" % (index + 1)
	label.font = NcHud.mono_font()
	label.font_size = 24
	label.pixel_size = .002
	label.position = Vector3(0, .53, .092)
	label.modulate = Color(.55, .66, .60)
	junction.add_child(label)
	for x in [-.42, .42]:
		for y in [-.54, .54]:
			var screw := MeshInstance3D.new()
			var cylinder := CylinderMesh.new()
			cylinder.top_radius = .018
			cylinder.bottom_radius = .018
			cylinder.height = .012
			screw.mesh = cylinder
			screw.material_override = metal
			screw.rotation.x = PI / 2.0
			screw.position = Vector3(x, y, .087)
			junction.add_child(screw)
	var light := OmniLight3D.new()
	light.position.z = .22
	light.light_color = Color(1.0, .45, .13)
	light.light_energy = .0
	light.omni_range = 2.2
	light.shadow_enabled = false
	junction.add_child(light)
	_lights.append(light)


func toggle_receiver() -> void:
	receiver_on = not receiver_on
	Trace.lock_seconds = 0.0
	_beep_left = 0.0
	if receiver_on:
		Trace.introduce()
	Sfx.play("blip", -18.0)


func _available() -> bool:
	return main != null and main.player != null and not main.player.frozen \
		and not Game.paused and not Game.asleep and not Game.over


func has_contact(reading: Dictionary) -> bool:
	if Trace.source < 0 or float(reading["distance"]) > Trace.REACH \
		or float(reading["alignment"]) < .975:
		return false
	var camera: Camera3D = main.player.camera()
	var ray := PhysicsRayQueryParameters3D.create(camera.global_position, Trace.SPOTS[Trace.source])
	ray.exclude = [main.player.get_rid()]
	var hit := get_world_3d().direct_space_state.intersect_ray(ray)
	return not hit.is_empty() and int(hit["collider"].get_meta("trace_source", -1)) == Trace.source


func _physics_process(delta: float) -> void:
	_contact = false
	if not receiver_on or not _available():
		Trace.update_lock(0.0, false, false)
		return
	var camera: Camera3D = main.player.camera()
	var reading := Trace.reception(camera.global_position, -camera.global_basis.z)
	_contact = has_contact(reading)
	if Trace.update_lock(delta, _contact, Input.is_physical_key_pressed(KEY_E)):
		_contact = false


func _process(delta: float) -> void:
	var available := _available()
	var listening := receiver_on and available
	_view.visible = listening
	if not Game.paused and not Game.over:
		_phase += delta
	for i in _panels.size():
		var revealed := i == Trace.source and listening
		_panels[i].visible = revealed
		_lights[i].visible = revealed
		if revealed:
			var amount := Trace.lock_seconds / Trace.HOLD_SECONDS
			_materials[i].set_shader_parameter("phase", _phase)
			_materials[i].set_shader_parameter("lock_amount", amount)
			var camera: Camera3D = main.player.camera()
			var local := _panels[i].to_local(camera.global_position)
			_materials[i].set_shader_parameter("parallax", Vector2(local.x, local.y).limit_length(2.0))
			_lights[i].light_energy = .55 + amount * .45
			_lights[i].light_color = Color(1.0, .45, .13).lerp(Color(.2, 1.0, .6), amount)
	if not listening:
		return
	var camera: Camera3D = main.player.camera()
	var reading := Trace.reception(camera.global_position, -camera.global_basis.z)
	_view.strength = float(reading["strength"])
	_view.alignment = float(reading["alignment"])
	_view.side = float(reading["side"])
	_view.contact = _contact
	_view.active_source = Trace.source >= 0
	_view.progress = Trace.lock_seconds / Trace.HOLD_SECONDS
	_view.tired = Game.energy < Trace.ENERGY_COST
	_view.phase = _phase
	_view.queue_redraw()
	_beep_left -= delta
	if Trace.source >= 0 and _beep_left <= 0.0:
		_beep_left = lerpf(1.35, .13, _view.strength)
		Sfx.play("trace_tick", lerpf(-29.0, -14.0, _view.strength))
