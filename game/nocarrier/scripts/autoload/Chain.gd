extends Node
## Cyberia chain client (read-only) over JSON-RPC via HTTPRequest.
## Polls the latest block — diegetically, NODE-07's backbone is the Cyberia
## mesh — and, once a wallet is connected, the player's native CYBER balance.

signal block_updated(block: int)
signal balance_updated(address: String, cyber: float)

const RPC_URL := "https://rpc.cyberia.church"
const CHAIN_ID := 49406
const POLL_INTERVAL := 6.0

var player_address := ""
var latest_block := 0
var player_cyber := 0.0

var _http_block: HTTPRequest
var _http_balance: HTTPRequest
var _block_busy := false
var _bal_busy := false
var _id := 0

# on-demand JSON-RPC calls (nonce, gas, sendRawTransaction), serialized
var _call_http: HTTPRequest
var _call_q: Array = []
var _call_cur: Callable
var _call_busy := false


func _ready() -> void:
	_http_block = HTTPRequest.new()
	add_child(_http_block)
	_http_block.request_completed.connect(_on_block_completed)

	_http_balance = HTTPRequest.new()
	add_child(_http_balance)
	_http_balance.request_completed.connect(_on_balance_completed)

	_call_http = HTTPRequest.new()
	add_child(_call_http)
	_call_http.request_completed.connect(_on_call_completed)

	var timer := Timer.new()
	timer.wait_time = POLL_INTERVAL
	timer.autostart = true
	add_child(timer)
	timer.timeout.connect(_poll)
	_poll()


## Fire an arbitrary JSON-RPC call; cb.call(ok: bool, value: String) on reply.
## Calls are serialized so the single HTTPRequest is never reused mid-flight.
func call_rpc(method: String, params: Array, cb: Callable) -> void:
	_id += 1
	var body := JSON.stringify({"jsonrpc": "2.0", "id": _id, "method": method, "params": params})
	_call_q.append({"body": body, "cb": cb})
	_pump_calls()


func _pump_calls() -> void:
	if _call_busy or _call_q.is_empty():
		return
	var item: Dictionary = _call_q.pop_front()
	_call_busy = true
	_call_cur = item["cb"]
	var headers := PackedStringArray(["Content-Type: application/json"])
	var err := _call_http.request(RPC_URL, headers, HTTPClient.METHOD_POST, item["body"])
	if err != OK:
		_call_busy = false
		if _call_cur.is_valid():
			_call_cur.call(false, "request failed to start")
		_pump_calls()


func _on_call_completed(_result: int, code: int, _headers: PackedStringArray, body: PackedByteArray) -> void:
	_call_busy = false
	var cb := _call_cur
	if code != 200:
		if cb.is_valid():
			cb.call(false, "http %d" % code)
		_pump_calls()
		return
	var data: Variant = JSON.parse_string(body.get_string_from_utf8())
	if typeof(data) == TYPE_DICTIONARY and data.has("result") and data["result"] != null:
		if cb.is_valid():
			cb.call(true, str(data["result"]))
	elif typeof(data) == TYPE_DICTIONARY and data.has("error"):
		var msg := "rpc error"
		if typeof(data["error"]) == TYPE_DICTIONARY and data["error"].has("message"):
			msg = str(data["error"]["message"])
		if cb.is_valid():
			cb.call(false, msg)
	else:
		if cb.is_valid():
			cb.call(false, "no result")
	_pump_calls()


func set_player(address: String) -> void:
	player_address = address
	if address == "":
		player_cyber = 0.0
		balance_updated.emit("", 0.0)
		return
	_poll()


func _poll() -> void:
	if not _block_busy:
		_block_busy = true
		_rpc(_http_block, "eth_blockNumber", [])
	if player_address != "" and not _bal_busy:
		_bal_busy = true
		_rpc(_http_balance, "eth_getBalance", [player_address, "latest"])


func _rpc(http: HTTPRequest, method: String, params: Array) -> void:
	_id += 1
	var body := JSON.stringify({
		"jsonrpc": "2.0",
		"id": _id,
		"method": method,
		"params": params,
	})
	var headers := PackedStringArray(["Content-Type: application/json"])
	http.request(RPC_URL, headers, HTTPClient.METHOD_POST, body)


func _on_block_completed(_result: int, code: int, _headers: PackedStringArray, body: PackedByteArray) -> void:
	_block_busy = false
	var data: Variant = _parse(code, body)
	if data == null or not data.has("result"):
		return
	latest_block = String(data["result"]).hex_to_int()
	block_updated.emit(latest_block)


func _on_balance_completed(_result: int, code: int, _headers: PackedStringArray, body: PackedByteArray) -> void:
	_bal_busy = false
	var data: Variant = _parse(code, body)
	if data == null or not data.has("result"):
		return
	player_cyber = _wei_hex_to_cyber(String(data["result"]))
	balance_updated.emit(player_address, player_cyber)


func _parse(code: int, body: PackedByteArray) -> Variant:
	if code != 200:
		return null
	var data: Variant = JSON.parse_string(body.get_string_from_utf8())
	if typeof(data) != TYPE_DICTIONARY:
		return null
	return data


## Convert a hex wei string to CYBER as a float, digit-by-digit to avoid
## 64-bit overflow on large balances.
func _wei_hex_to_cyber(hex: String) -> float:
	var s := hex
	if s.begins_with("0x") or s.begins_with("0X"):
		s = s.substr(2)
	var wei := 0.0
	for i in s.length():
		wei = wei * 16.0 + float("0123456789abcdef".find(s[i].to_lower()))
	return wei / 1e18
