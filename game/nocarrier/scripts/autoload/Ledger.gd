extends Node
## On-chain sealing for NO CARRIER. Decoded NONSTANDARD / echo captures can be
## "sealed to the chain": a real `mint(string)` on the shared CyberiaNFT
## collection, signed by the player's own browser wallet. Reads (balanceOf)
## are anonymous JSON-RPC and work on desktop too.
##
## Contract: CyberiaNFT 0x546462FAbf30734E63b64f32B30EC8ADD9B6EBa7 (Cyberia).

signal balance_updated(count: int)
signal seal_result(ok: bool, info: String)

const NFT_ADDRESS := "0x546462FAbf30734E63b64f32B30EC8ADD9B6EBa7"
const RPC_URL := "https://rpc.cyberia.church"
const SEL_MINT := "d85d3d27"       # mint(string)
const SEL_BALANCEOF := "70a08231"  # balanceOf(address)

var player := ""
var balance := 0
var pending_file_id := -1          # capture waiting for its seal tx

var _http: HTTPRequest


func _ready() -> void:
	_http = HTTPRequest.new()
	add_child(_http)
	_http.request_completed.connect(_on_completed)
	Wallet.address_changed.connect(_on_address)
	Wallet.tx_sent.connect(_on_tx_sent)
	Wallet.tx_failed.connect(_on_tx_failed)


func _on_address(addr: String) -> void:
	player = addr.to_lower()
	Chain.set_player(addr)
	if addr != "":
		Game.toast(Loc.t("t.wallet_conn", [Wallet.short_address()]))
		refresh()


func refresh() -> void:
	if player == "":
		return
	var body := JSON.stringify({
		"jsonrpc": "2.0", "id": 1, "method": "eth_call",
		"params": [{"to": NFT_ADDRESS, "data": "0x" + SEL_BALANCEOF + _word_address(player)}, "latest"],
	})
	var headers := PackedStringArray(["Content-Type: application/json"])
	_http.request(RPC_URL, headers, HTTPClient.METHOD_POST, body)


func _on_completed(_result: int, code: int, _headers: PackedStringArray, body: PackedByteArray) -> void:
	if code != 200:
		return
	var data: Variant = JSON.parse_string(body.get_string_from_utf8())
	if typeof(data) != TYPE_DICTIONARY or not data.has("result"):
		return
	var h := String(data["result"])
	balance = 0 if h == "" or h == "0x" else h.hex_to_int()
	balance_updated.emit(balance)


## Ask the wallet to seal a capture. The file is only consumed once the tx
## is actually submitted (tx_sent).
func seal(f: Dictionary) -> void:
	if not Wallet.available() or not Wallet.is_connected_wallet():
		seal_result.emit(false, Loc.t("up.connect_first"))
		return
	if pending_file_id >= 0:
		seal_result.emit(false, Loc.t("up.pending"))
		return
	pending_file_id = int(f["id"])
	# chain metadata is language-independent (always en)
	var title := Loc.t_in("en", "title." + str(f["cls"]), [int(f.get("ti", 0))])
	var uri := "nocarrier://day%d/%s/%s" % [Game.day, str(f["name"]), title]
	var data := "0x" + SEL_MINT + _encode_string_arg(uri)
	Wallet.send_transaction(NFT_ADDRESS, data)


func _on_tx_sent(tx: String) -> void:
	if pending_file_id < 0:
		return
	var fid := pending_file_id
	pending_file_id = -1
	Net.seal_file(fid, tx)
	seal_result.emit(true, tx)
	refresh()


func _on_tx_failed(msg: String) -> void:
	pending_file_id = -1
	seal_result.emit(false, msg)
	Game.toast(Loc.t("up.txfail", [msg.left(60)]))


## --- abi helpers -----------------------------------------------------------

func _word_hex(value: int) -> String:
	var h := "%x" % value
	while h.length() < 64:
		h = "0" + h
	return h


func _word_address(addr: String) -> String:
	var a := addr.to_lower()
	if a.begins_with("0x"):
		a = a.substr(2)
	while a.length() < 64:
		a = "0" + a
	return a


func _encode_string_arg(s: String) -> String:
	var bytes := s.to_utf8_buffer()
	var n := bytes.size()
	var out := _word_hex(32)           # offset to the string data
	out += _word_hex(n)                # string length
	var data_hex := bytes.hex_encode()
	var pad := (32 - (n % 32)) % 32
	for _i in pad:
		data_hex += "00"
	return out + data_hex
