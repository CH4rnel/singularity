extends Node
## Cyberia chain client (read-only) over JSON-RPC via HTTPRequest.
##
## Autoloaded as `Chain`. Polls the latest block and — if `player_address` is
## set — the player's native CYBER balance, driving on-chain game mechanics
## (the gate in Main opens when the wallet holds enough CYBER).

signal block_updated(block: int)
signal balance_updated(address: String, cyber: float)
signal gate_state_changed(is_open: bool)

const RPC_URL := "https://rpc.cyberia.church"
const CHAIN_ID := 49406
const POLL_INTERVAL := 4.0

## Set this to a wallet address (0x…) to drive the on-chain gate.
var player_address := ""
## Gate opens once `player_address` holds at least this much CYBER.
var gate_threshold := 0.0001

var latest_block := 0
var player_cyber := 0.0

var _gate_open := false
var _http_block: HTTPRequest
var _http_balance: HTTPRequest
var _id := 0


func _ready() -> void:
	_http_block = HTTPRequest.new()
	add_child(_http_block)
	_http_block.request_completed.connect(_on_block_completed)

	_http_balance = HTTPRequest.new()
	add_child(_http_balance)
	_http_balance.request_completed.connect(_on_balance_completed)

	var timer := Timer.new()
	timer.wait_time = POLL_INTERVAL
	timer.autostart = true
	add_child(timer)
	timer.timeout.connect(_poll)
	_poll()


## Point the chain at a wallet (e.g. the one connected in the browser) and
## refresh immediately. Drives the gate and the balance HUD.
func set_player(address: String) -> void:
	player_address = address
	if address == "":
		player_cyber = 0.0
		balance_updated.emit("", 0.0)
		if _gate_open:
			_gate_open = false
			gate_state_changed.emit(false)
		return
	_poll()


func _poll() -> void:
	_rpc(_http_block, "eth_blockNumber", [])
	if player_address != "":
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
	var err := http.request(RPC_URL, headers, HTTPClient.METHOD_POST, body)
	if err != OK:
		push_warning("Chain: RPC '%s' failed to start (%s)" % [method, err])


func _on_block_completed(_result: int, code: int, _headers: PackedStringArray, body: PackedByteArray) -> void:
	var data: Variant = _parse(code, body)
	if data == null or not data.has("result"):
		return
	latest_block = String(data["result"]).hex_to_int()
	block_updated.emit(latest_block)


func _on_balance_completed(_result: int, code: int, _headers: PackedStringArray, body: PackedByteArray) -> void:
	var data: Variant = _parse(code, body)
	if data == null or not data.has("result"):
		return
	player_cyber = _wei_hex_to_cyber(String(data["result"]))
	balance_updated.emit(player_address, player_cyber)

	var should_open := player_cyber >= gate_threshold
	if should_open != _gate_open:
		_gate_open = should_open
		gate_state_changed.emit(should_open)


func _parse(code: int, body: PackedByteArray) -> Variant:
	if code != 200:
		return null
	var data: Variant = JSON.parse_string(body.get_string_from_utf8())
	if typeof(data) != TYPE_DICTIONARY:
		return null
	return data


## Convert a hex wei string (e.g. "0x1bc16d674ec80000") to CYBER as a float.
## Done digit-by-digit to avoid 64-bit integer overflow on large balances.
func _wei_hex_to_cyber(hex: String) -> float:
	var s := hex
	if s.begins_with("0x") or s.begins_with("0X"):
		s = s.substr(2)
	var wei := 0.0
	for i in s.length():
		wei = wei * 16.0 + float(_hex_digit(s[i]))
	return wei / 1e18


func _hex_digit(c: String) -> int:
	match c.to_lower():
		"0": return 0
		"1": return 1
		"2": return 2
		"3": return 3
		"4": return 4
		"5": return 5
		"6": return 6
		"7": return 7
		"8": return 8
		"9": return 9
		"a": return 10
		"b": return 11
		"c": return 12
		"d": return 13
		"e": return 14
		"f": return 15
		_: return 0
