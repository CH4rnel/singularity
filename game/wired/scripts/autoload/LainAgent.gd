extends Node
## Bridge to the LainOS HTTP service (`npm run serve` in services/lainos).
##
## Autoloaded as `LainAgent`. The Lain NPC's mind lives in LainOS — this just
## relays the player's words and emits the agent's reply.

signal reply_received(text: String)
signal request_failed(message: String)

const BASE_URL := "http://127.0.0.1:7777"

var _http: HTTPRequest
var _busy := false


func _ready() -> void:
	_http = HTTPRequest.new()
	add_child(_http)
	_http.request_completed.connect(_on_completed)


func is_busy() -> bool:
	return _busy


func chat(text: String, room: String = "wired", user: String = "player") -> void:
	if _busy:
		return
	_busy = true
	var body := JSON.stringify({"roomId": room, "userId": user, "text": text})
	var headers := PackedStringArray(["Content-Type: application/json"])
	var err := _http.request(BASE_URL + "/chat", headers, HTTPClient.METHOD_POST, body)
	if err != OK:
		_busy = false
		request_failed.emit("could not reach LainOS (%s). is `npm run serve` running?" % err)


func _on_completed(result: int, code: int, _headers: PackedStringArray, body: PackedByteArray) -> void:
	_busy = false
	if result != HTTPRequest.RESULT_SUCCESS or code != 200:
		request_failed.emit("LainOS offline (result %s, code %s). run `npm run serve`." % [result, code])
		return
	var data: Variant = JSON.parse_string(body.get_string_from_utf8())
	if typeof(data) == TYPE_DICTIONARY and data.has("text"):
		reply_received.emit(String(data["text"]))
	else:
		request_failed.emit("unexpected response from LainOS.")
