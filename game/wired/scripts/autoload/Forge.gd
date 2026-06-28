extends Node
## On-chain duel client for WiredForge (model C). Autoloaded as `Forge`.
##
## startRun is sent as raw calldata pre-encoded by the LainOS auth server (model
## B). Each turn `act(move)` is its own transaction. Run state and the ICE's move
## are read over eth_call and polled, so the UI reflects the real on-chain duel.

signal run_updated(run: Dictionary)
signal ice_move(turn: int, move: int)
signal duel_timeout()

const FORGE_ADDRESS := "0x2daa4A79EC2224AD02D9D4eBf937924b76F669Fa"
const RPC_URL := "https://rpc.cyberia.church"
const SEL_ACT := "96f04333"      # act(uint8)
const SEL_RUNS := "42764d6e"     # runs(address)
const SEL_PREVIEW := "764afc7b"  # previewIceMove(bytes32,uint8)

var _http_run: HTTPRequest
var _http_ice: HTTPRequest
var _poll: Timer
var _player := ""
var _run_busy := false
var _polls_without_change := 0
var _last_signature := ""  # turn|iceHp|active fingerprint to detect changes


func _ready() -> void:
	_http_run = HTTPRequest.new()
	add_child(_http_run)
	_http_run.request_completed.connect(_on_run_read)

	_http_ice = HTTPRequest.new()
	add_child(_http_ice)
	_http_ice.request_completed.connect(_on_ice_read)

	_poll = Timer.new()
	_poll.wait_time = 1.5
	add_child(_poll)
	_poll.timeout.connect(_do_poll)


func start_run(start_calldata: String, player: String) -> void:
	_player = player.to_lower()
	_last_signature = ""
	_polls_without_change = 0
	Wallet.send_transaction(FORGE_ADDRESS, start_calldata)
	_begin_poll()


func act(move: int) -> void:
	var data := "0x" + SEL_ACT + _word_uint(move)
	Wallet.send_transaction(FORGE_ADDRESS, data)
	_polls_without_change = 0
	_begin_poll()


func _begin_poll() -> void:
	_poll.start()
	_do_poll()


func _do_poll() -> void:
	if _player == "" or _run_busy:
		return
	_run_busy = true
	var data := "0x" + SEL_RUNS + _word_address(_player)
	_eth_call(_http_run, data)

	_polls_without_change += 1
	if _polls_without_change > 24:  # ~36s with no change -> give up (tx rejected?)
		_poll.stop()
		_polls_without_change = 0
		duel_timeout.emit()


func _on_run_read(_result: int, code: int, _headers: PackedStringArray, body: PackedByteArray) -> void:
	_run_busy = false
	var hexres := _result_hex(code, body)
	if hexres.length() < 2 + 64 * 7:
		return
	var h := hexres.substr(2)
	var run := {
		"active": _word(h, 0).hex_to_int() == 1,
		"tier": _word(h, 1).hex_to_int(),
		"seed": "0x" + _word(h, 2),
		"playerHp": _word(h, 3).hex_to_int(),
		"iceHp": _word(h, 4).hex_to_int(),
		"turn": _word(h, 5).hex_to_int(),
		"maxTurns": _word(h, 6).hex_to_int(),
	}

	var sig := "%s|%d|%d" % [str(run["active"]), int(run["turn"]), int(run["iceHp"])]
	if sig != _last_signature:
		_last_signature = sig
		_polls_without_change = 0
		run_updated.emit(run)
		if bool(run["active"]):
			_read_ice_move(run["seed"], int(run["turn"]))
		else:
			_poll.stop()


func _read_ice_move(seed: String, turn: int) -> void:
	var s := seed.substr(2) if seed.begins_with("0x") else seed
	var data := "0x" + SEL_PREVIEW + s + _word_uint(turn)
	_eth_call(_http_ice, data)


func _on_ice_read(_result: int, code: int, _headers: PackedStringArray, body: PackedByteArray) -> void:
	var hexres := _result_hex(code, body)
	if hexres.length() < 2 + 64:
		return
	var move := hexres.substr(2, 64).hex_to_int()
	ice_move.emit(int(_last_turn_from_signature()), move)


func _last_turn_from_signature() -> int:
	var parts := _last_signature.split("|")
	return int(parts[1]) if parts.size() >= 2 else 0


func _eth_call(http: HTTPRequest, data_hex: String) -> void:
	var body := JSON.stringify({
		"jsonrpc": "2.0", "id": 1, "method": "eth_call",
		"params": [{"to": FORGE_ADDRESS, "data": data_hex}, "latest"],
	})
	var headers := PackedStringArray(["Content-Type: application/json"])
	http.request(RPC_URL, headers, HTTPClient.METHOD_POST, body)


func _result_hex(code: int, body: PackedByteArray) -> String:
	if code != 200:
		return ""
	var data: Variant = JSON.parse_string(body.get_string_from_utf8())
	if typeof(data) != TYPE_DICTIONARY or not data.has("result"):
		return ""
	return String(data["result"])


func _word(h: String, i: int) -> String:
	return h.substr(i * 64, 64)


func _word_uint(value: int) -> String:
	var s := "%x" % value
	while s.length() < 64:
		s = "0" + s
	return s


func _word_address(addr: String) -> String:
	var a := addr.to_lower()
	if a.begins_with("0x"):
		a = a.substr(2)
	while a.length() < 64:
		a = "0" + a
	return a
