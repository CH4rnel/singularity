class_name Interactable
extends Area3D
## Invisible interaction volume. The player's camera ray hits it (layer 2)
## before the prop's own StaticBody, shows get_prompt() as a hint, and E
## calls run(). Prompts can be static or computed per-frame via prompt_fn.

var prompt := ""
var prompt_fn: Callable
var action: Callable


static func make(p_prompt: String, p_action: Callable, pos: Vector3, size: Vector3) -> Interactable:
	var area := Interactable.new()
	area.prompt = p_prompt
	area.action = p_action
	area.position = pos
	area.collision_layer = 2
	area.collision_mask = 0
	var shape := CollisionShape3D.new()
	var box := BoxShape3D.new()
	box.size = size
	shape.shape = box
	area.add_child(shape)
	return area


func get_prompt() -> String:
	if prompt_fn.is_valid():
		return str(prompt_fn.call())
	return prompt


func run() -> void:
	if action.is_valid():
		action.call()
