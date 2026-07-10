class_name SysopPlayer
extends CharacterBody3D
## First-person controller for NO CARRIER. Self-contained: builds its own
## collision, camera and flashlight in _ready so the scene can be assembled
## procedurally. Interaction is a camera ray against Interactable areas.

const SPEED := 3.6
const SPRINT := 6.2
const JUMP_VELOCITY := 4.0
const MOUSE_SENS := 0.0025
const REACH := 2.8

var frozen := false
var main: Node3D = null

var _gravity: float = ProjectSettings.get_setting("physics/3d/default_gravity", 9.8)
var _head: Node3D
var _camera: Camera3D
var _lamp: SpotLight3D
var _focus: Interactable = null


func _ready() -> void:
	var shape := CollisionShape3D.new()
	var capsule := CapsuleShape3D.new()
	capsule.height = 1.7
	capsule.radius = 0.32
	shape.shape = capsule
	add_child(shape)

	_head = Node3D.new()
	_head.position = Vector3(0.0, 0.68, 0.0)
	add_child(_head)

	_camera = Camera3D.new()
	_camera.current = true
	_camera.fov = 80.0
	_head.add_child(_camera)

	_lamp = SpotLight3D.new()
	_lamp.light_color = Color(1.0, 0.95, 0.8)
	_lamp.light_energy = 2.2
	_lamp.spot_range = 14.0
	_lamp.spot_angle = 28.0
	_lamp.visible = false
	_head.add_child(_lamp)

	Input.mouse_mode = Input.MOUSE_MODE_CAPTURED


func camera() -> Camera3D:
	return _camera


func _unhandled_input(event: InputEvent) -> void:
	var click := event as InputEventMouseButton
	if click and click.pressed and click.button_index == MOUSE_BUTTON_LEFT:
		if not frozen and Input.mouse_mode != Input.MOUSE_MODE_CAPTURED:
			Input.mouse_mode = Input.MOUSE_MODE_CAPTURED
		return

	if frozen:
		return

	var motion := event as InputEventMouseMotion
	if motion and Input.mouse_mode == Input.MOUSE_MODE_CAPTURED:
		var sens := MOUSE_SENS * Settings.mouse_factor()
		rotate_y(-motion.relative.x * sens)
		_head.rotate_x(-motion.relative.y * sens)
		_head.rotation.x = clamp(_head.rotation.x, -1.45, 1.45)
		return

	var key := event as InputEventKey
	if key and key.pressed and not key.echo:
		match key.physical_keycode:
			KEY_E:
				if _focus != null:
					_focus.run()
			KEY_F:
				_lamp.visible = not _lamp.visible
				Sfx.play("blip", -16.0)
			KEY_C:
				if Wallet.available():
					Wallet.connect_wallet()
				else:
					Game.toast(Loc.t("t.wallet_only"))
			KEY_ESCAPE:
				if main != null:
					main.request_pause()


func _physics_process(delta: float) -> void:
	if not is_on_floor():
		velocity.y -= _gravity * delta

	_update_focus()

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
	if Game.carrying_trash:
		speed *= 0.8
	var direction := (transform.basis * Vector3(input_dir.x, 0.0, input_dir.y)).normalized()
	if direction != Vector3.ZERO:
		velocity.x = direction.x * speed
		velocity.z = direction.z * speed
	else:
		velocity.x = move_toward(velocity.x, 0.0, speed)
		velocity.z = move_toward(velocity.z, 0.0, speed)

	move_and_slide()


func _update_focus() -> void:
	var prev := _focus
	_focus = null
	if not frozen:
		var from := _camera.global_position
		var to := from - _camera.global_transform.basis.z * REACH
		var query := PhysicsRayQueryParameters3D.create(from, to)
		query.collide_with_areas = true
		query.collide_with_bodies = true
		query.exclude = [get_rid()]
		var hit := get_world_3d().direct_space_state.intersect_ray(query)
		if not hit.is_empty() and hit["collider"] is Interactable:
			_focus = hit["collider"]
	if main == null:
		return
	if _focus != null:
		main.hud.set_hint("[E] " + _focus.get_prompt())
	elif prev != null:
		main.hud.set_hint("")
