class_name LainNpc
extends StaticBody3D
## The physical body of Lain. Her mind is the LainOS agent reached through the
## LainAgent autoload; this node just gives her a form in the Wired.
##
## Drop a real model at res://assets/lain.glb and it will be used automatically;
## otherwise a stylised procedural avatar (floating robed figure + halo) stands in.

const GREETING := "...you found me. ask, and i'll read the chain for you."
const MODEL_PATH := "res://assets/lain.glb"
const MODEL_SCALE := 1.0   # tweak if the model is too big/small
const MODEL_ROT_Y := 0.0   # extra facing rotation (radians) if it faces away

var _t := 0.0
var _visual: Node3D
var _has_model := false


func _ready() -> void:
	_visual = Node3D.new()
	add_child(_visual)

	var loaded := false
	if ResourceLoader.exists(MODEL_PATH):
		var res: Resource = load(MODEL_PATH)
		if res is PackedScene:
			# Ground the NPC first so global transforms are final before we
			# measure the model's bounds.
			position.y = 0.0
			var inst: Node = (res as PackedScene).instantiate()
			if inst is Node3D:
				(inst as Node3D).scale = Vector3.ONE * MODEL_SCALE
				(inst as Node3D).rotation.y = MODEL_ROT_Y
			_visual.add_child(inst)
			# The model's pivot can be anywhere (this one sits at the head), so
			# auto-drop it until its lowest point rests on the floor (y = 0).
			if inst is Node3D:
				_ground_to_floor(inst as Node3D)
			_autoplay_animation(inst)
			_has_model = true
			loaded = true
	if not loaded:
		_build_avatar()

	# Collision is independent of the visual so swapping in a .glb is safe.
	var shape := CollisionShape3D.new()
	var capsule := CapsuleShape3D.new()
	capsule.height = 1.7
	capsule.radius = 0.4
	shape.shape = capsule
	shape.position = Vector3(0.0, 0.85, 0.0)
	add_child(shape)

	var glow := OmniLight3D.new()
	glow.light_color = Color(0.7, 0.5, 0.8)
	glow.light_energy = 1.8
	glow.omni_range = 6.0
	glow.position = Vector3(0.0, 1.0, 0.0)
	add_child(glow)


# Shift `inst` up so the lowest point of its combined mesh bounds rests on the
# floor (the NPC origin, which is grounded at y = 0). Works regardless of where
# the model's pivot is.
func _ground_to_floor(inst: Node3D) -> void:
	var visuals := _collect_visuals(inst)
	if visuals.is_empty():
		return
	var merged := AABB()
	var first := true
	for vi in visuals:
		var box: AABB = vi.global_transform * vi.get_aabb()
		if first:
			merged = box
			first = false
		else:
			merged = merged.merge(box)
	# merged.position.y is the lowest point in global space; raise it to 0.
	inst.position.y -= merged.position.y


func _collect_visuals(node: Node) -> Array:
	var out: Array = []
	if node is VisualInstance3D:
		out.append(node)
	for child in node.get_children():
		out.append_array(_collect_visuals(child))
	return out


# If the model ships animations, Godot imports an AnimationPlayer — find it and
# loop a sensible idle so Lain isn't a frozen statue.
func _autoplay_animation(inst: Node) -> void:
	var ap := _find_anim_player(inst)
	if ap == null:
		print("[lain-model] no AnimationPlayer (static model)")
		return
	var names := ap.get_animation_list()
	print("[lain-model] animations: ", names)
	if names.is_empty():
		return
	var chosen := String(names[0])
	for n in names:
		var ln := String(n).to_lower()
		if ln.contains("idle") or ln.contains("loop") or ln.contains("stand"):
			chosen = String(n)
			break
	var anim := ap.get_animation(chosen)
	if anim:
		anim.loop_mode = Animation.LOOP_LINEAR
	ap.play(chosen)


func _find_anim_player(node: Node) -> AnimationPlayer:
	if node is AnimationPlayer:
		return node as AnimationPlayer
	for child in node.get_children():
		var found := _find_anim_player(child)
		if found:
			return found
	return null


# Stylised placeholder: a hooded figure (oversized hoodie + dark bob).
# Generic on purpose — drop a real model at res://assets/lain.glb to replace it.
func _build_avatar() -> void:
	var hoodie_col := Color(0.16, 0.17, 0.22)

	# Hoodie body — slightly tapered, oversized.
	var body := MeshInstance3D.new()
	var body_mesh := CylinderMesh.new()
	body_mesh.top_radius = 0.32
	body_mesh.bottom_radius = 0.42
	body_mesh.height = 1.1
	body.mesh = body_mesh
	body.position = Vector3(0.0, 0.6, 0.0)
	body.material_override = _mat(hoodie_col, Color.BLACK)
	_visual.add_child(body)

	# Sleeves (long, hands hidden).
	for side in [-1.0, 1.0]:
		var sleeve := MeshInstance3D.new()
		var sleeve_mesh := CapsuleMesh.new()
		sleeve_mesh.radius = 0.11
		sleeve_mesh.height = 0.8
		sleeve.mesh = sleeve_mesh
		sleeve.position = Vector3(0.34 * side, 0.55, 0.0)
		sleeve.rotation = Vector3(0.0, 0.0, 0.25 * side)
		sleeve.material_override = _mat(hoodie_col, Color.BLACK)
		_visual.add_child(sleeve)

	# Head.
	var head := MeshInstance3D.new()
	var head_mesh := SphereMesh.new()
	head_mesh.radius = 0.21
	head_mesh.height = 0.42
	head.mesh = head_mesh
	head.position = Vector3(0.0, 1.32, 0.0)
	head.material_override = _mat(Color(0.92, 0.86, 0.84), Color.BLACK)
	_visual.add_child(head)

	# Dark bob hair — a dome over the head with short side framing.
	var hair := MeshInstance3D.new()
	var hair_mesh := SphereMesh.new()
	hair_mesh.radius = 0.25
	hair_mesh.height = 0.42
	hair.mesh = hair_mesh
	hair.position = Vector3(0.0, 1.37, -0.02)
	hair.material_override = _mat(Color(0.14, 0.12, 0.15), Color.BLACK)
	_visual.add_child(hair)
	for side in [-1.0, 1.0]:
		var strand := MeshInstance3D.new()
		var strand_mesh := BoxMesh.new()
		strand_mesh.size = Vector3(0.1, 0.34, 0.16)
		strand.mesh = strand_mesh
		strand.position = Vector3(0.2 * side, 1.24, 0.02)
		strand.material_override = _mat(Color(0.14, 0.12, 0.15), Color.BLACK)
		_visual.add_child(strand)

	# Hood resting on the shoulders, behind the head.
	var hood := MeshInstance3D.new()
	var hood_mesh := SphereMesh.new()
	hood_mesh.radius = 0.3
	hood_mesh.height = 0.5
	hood.mesh = hood_mesh
	hood.position = Vector3(0.0, 1.12, -0.18)
	hood.material_override = _mat(hoodie_col, Color.BLACK)
	_visual.add_child(hood)

	# Two little ears on the hood — generic "animal hoodie" read.
	for side in [-1.0, 1.0]:
		var ear := MeshInstance3D.new()
		var ear_mesh := SphereMesh.new()
		ear_mesh.radius = 0.09
		ear_mesh.height = 0.18
		ear.mesh = ear_mesh
		ear.position = Vector3(0.16 * side, 1.55, -0.1)
		ear.material_override = _mat(hoodie_col, Color(0.25, 0.08, 0.18))
		_visual.add_child(ear)


func _mat(albedo: Color, emission: Color, emit_energy := 1.0) -> StandardMaterial3D:
	var m := StandardMaterial3D.new()
	m.albedo_color = albedo
	m.roughness = 0.5
	if emission != Color.BLACK:
		m.emission_enabled = true
		m.emission = emission
		m.emission_energy_multiplier = emit_energy
	return m


func _process(delta: float) -> void:
	_t += delta
	if _has_model:
		# Subtle "alive" idle for a static model: gentle sway + faint breathing,
		# feet stay planted (no full spin — looks odd on a character).
		_visual.rotation.y = MODEL_ROT_Y + sin(_t * 0.6) * 0.12
		_visual.rotation.z = sin(_t * 0.9) * 0.015
	else:
		_visual.position.y = 0.15 + sin(_t * 1.5) * 0.06   # gentle float
		_visual.rotation.y = _t * 0.3


func get_greeting() -> String:
	return GREETING
