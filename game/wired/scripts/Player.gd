class_name WiredPlayer
extends CharacterBody3D
## First-person controller. Self-contained: builds its own collision, body
## mesh, and camera in _ready so the scene can be assembled procedurally.

const SPEED := 5.0
const SPRINT := 8.0
const JUMP_VELOCITY := 4.5
const MOUSE_SENS := 0.0025

## When true, input is ignored (used while talking to an NPC).
var frozen := false

var _gravity: float = ProjectSettings.get_setting("physics/3d/default_gravity", 9.8)
var _head: Node3D
var _camera: Camera3D


func _ready() -> void:
	var shape := CollisionShape3D.new()
	var capsule := CapsuleShape3D.new()
	capsule.height = 1.8
	capsule.radius = 0.4
	shape.shape = capsule
	add_child(shape)

	var mesh := MeshInstance3D.new()
	var body := CapsuleMesh.new()
	body.height = 1.8
	body.radius = 0.4
	mesh.mesh = body
	var mat := StandardMaterial3D.new()
	mat.albedo_color = Color(0.2, 0.8, 0.9)
	mat.metallic = 0.3
	mesh.material_override = mat
	add_child(mesh)

	_head = Node3D.new()
	_head.position = Vector3(0.0, 0.7, 0.0)
	add_child(_head)

	_camera = Camera3D.new()
	_camera.current = true
	_head.add_child(_camera)

	Input.mouse_mode = Input.MOUSE_MODE_CAPTURED


func _unhandled_input(event: InputEvent) -> void:
	if frozen:
		return

	# Browsers only grant pointer lock in response to a user gesture, so the
	# mouse can't be captured at startup (web). Capture it on the first click.
	var click := event as InputEventMouseButton
	if click and click.pressed and click.button_index == MOUSE_BUTTON_LEFT:
		if Input.mouse_mode != Input.MOUSE_MODE_CAPTURED:
			Input.mouse_mode = Input.MOUSE_MODE_CAPTURED
		return

	var motion := event as InputEventMouseMotion
	if motion and Input.mouse_mode == Input.MOUSE_MODE_CAPTURED:
		rotate_y(-motion.relative.x * MOUSE_SENS)
		_head.rotate_x(-motion.relative.y * MOUSE_SENS)
		_head.rotation.x = clamp(_head.rotation.x, -1.4, 1.4)


func _physics_process(delta: float) -> void:
	if not is_on_floor():
		velocity.y -= _gravity * delta

	if frozen:
		velocity.x = 0.0
		velocity.z = 0.0
		move_and_slide()
		return

	if Input.is_physical_key_pressed(KEY_SPACE) and is_on_floor():
		velocity.y = JUMP_VELOCITY

	var input_dir := Vector2.ZERO
	if Input.is_physical_key_pressed(KEY_W):
		input_dir.y -= 1.0
	if Input.is_physical_key_pressed(KEY_S):
		input_dir.y += 1.0
	if Input.is_physical_key_pressed(KEY_A):
		input_dir.x -= 1.0
	if Input.is_physical_key_pressed(KEY_D):
		input_dir.x += 1.0
	input_dir = input_dir.normalized()

	var speed := SPRINT if Input.is_physical_key_pressed(KEY_SHIFT) else SPEED
	var direction := (transform.basis * Vector3(input_dir.x, 0.0, input_dir.y)).normalized()
	if direction != Vector3.ZERO:
		velocity.x = direction.x * speed
		velocity.z = direction.z * speed
	else:
		velocity.x = move_toward(velocity.x, 0.0, speed)
		velocity.z = move_toward(velocity.z, 0.0, speed)

	move_and_slide()
