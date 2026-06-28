extends Node
## Talks to the LainOS Wired auth server (model B). Autoloaded as `WiredAuth`.
## Chains /wired/session/start then /wired/session/ticket and hands the game
## ready-to-send startRun calldata.

signal ticket_ready(start_calldata: String)
signal auth_failed(message: String)

const BASE_URL := "http://127.0.0.1:7788"

var _http: HTTPRequest
var _stage := ""  # "" | "start" | "ticket"
var _address := ""
var _collected := 0
var _elapsed_ms := 0
var _session_id := ""


func _ready() -> void:
	_http = HTTPRequest.new()
	add_child(_http)
	_http.request_completed.connect(_on_completed)


func busy() -> bool:
	return _stage != ""


## Run the full handshake: start a session, then request a signed ticket.
func begin(address: String, collected: int, elapsed_ms: int) -> void:
	if _stage != "":
		return
	_address = address
	_collected = collected
	_elapsed_ms = elapsed_ms
	_stage = "start"
	_post("/wired/session/start", JSON.stringify({"address": address}))


func _post(path: String, body: String) -> void:
	var headers := PackedStringArray(["Content-Type: application/json"])
	var err := _http.request(BASE_URL + path, headers, HTTPClient.METHOD_POST, body)
	if err != OK:
		_fail("can't reach wired server (%s). is `npm run serve:wired` running?" % err)


func _on_completed(result: int, code: int, _headers: PackedStringArray, body: PackedByteArray) -> void:
	if result != HTTPRequest.RESULT_SUCCESS:
		_fail("wired server offline. run `npm run serve:wired`.")
		return
	var data: Variant = JSON.parse_string(body.get_string_from_utf8())
	if typeof(data) != TYPE_DICTIONARY:
		_fail("bad response from wired server")
		return
	if code != 200:
		_fail(str(data.get("error", "wired server error %s" % code)))
		return

	if _stage == "start":
		_session_id = str(data.get("sessionId", ""))
		_stage = "ticket"
		_post("/wired/session/ticket", JSON.stringify({
			"sessionId": _session_id,
			"address": _address,
			"proof": {"collected": _collected, "elapsedMs": _elapsed_ms},
		}))
	elif _stage == "ticket":
		_stage = ""
		var calldata := str(data.get("startCalldata", ""))
		if calldata == "":
			_fail("no startCalldata in ticket response")
			return
		ticket_ready.emit(calldata)


func _fail(message: String) -> void:
	_stage = ""
	auth_failed.emit(message)
