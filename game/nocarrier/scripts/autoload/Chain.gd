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


func set_player(address: String) -> void:
	player_address = address
	if address == "":
		player_cyber = 0.0
		balance_updated.emit("", 0.0)
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
	http.request(RPC_URL, headers, HTTPClient.METHOD_POST, body)


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
