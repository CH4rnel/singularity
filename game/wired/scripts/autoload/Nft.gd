extends Node
## Artifact inventory layer for the Wired NFT game.
##
## Autoloaded as `Nft`. Reads the player's holdings of the WiredForge artifact
## collection (ERC-721) over JSON-RPC — drives the NFT-gated gate and gallery.
## Artifacts are NOT minted here: they are *earned* by winning the on-chain duel
## (see Forge.gd / WiredForge). This layer is read-only inventory.
##
## Contract: WiredForge — ERC721URIStorage, public `nextId`.

signal balance_updated(count: int)
signal supply_updated(total: int)
signal owned_updated(ids: Array)
signal mint_status(text: String)

const NFT_ADDRESS := "0x2daa4A79EC2224AD02D9D4eBf937924b76F669Fa"
const RPC_URL := "https://rpc.cyberia.church"

# Function selectors (keccak256(sig)[:4]).
const SEL_NEXTID := "61b8ce8c"     # nextId()
const SEL_BALANCEOF := "70a08231"  # balanceOf(address)
const SEL_OWNEROF := "6352211e"    # ownerOf(uint256)

# Only scan the most recent N token ids when listing what the player owns.
const SCAN_LIMIT := 40

var player := ""
var balance := 0
var total := 0

var _http: HTTPRequest
var _queue: Array = []     # FIFO of {id, body, tag}
var _pending := {}         # request id -> tag
var _busy := false
var _id := 0
var _owned: Array = []
var _scan_pending := 0


func _ready() -> void:
	_http = HTTPRequest.new()
	add_child(_http)
	_http.request_completed.connect(_on_completed)


## Point the layer at a wallet and refresh holdings.
func set_player(address: String) -> void:
	player = address.to_lower()
	balance = 0
	_owned.clear()
	if player == "":
		balance_updated.emit(0)
		owned_updated.emit([])
		return
	refresh()


func refresh() -> void:
	if player == "":
		return
	_enqueue("0x" + SEL_BALANCEOF + _word_address(player), "balance")
	_enqueue("0x" + SEL_NEXTID, "nextid")


## Artifacts are earned by winning the duel, not minted directly.
func mint(_uri: String) -> void:
	mint_status.emit("artifacts are forged by cracking the Node — win the duel at the Forge")


# ---------------------------------------------------------------- rpc queue

func _enqueue(data_hex: String, tag: String) -> void:
	_id += 1
	var body := JSON.stringify({
		"jsonrpc": "2.0",
		"id": _id,
		"method": "eth_call",
		"params": [{"to": NFT_ADDRESS, "data": data_hex}, "latest"],
	})
	_queue.append({"id": _id, "body": body, "tag": tag})
	_pump()


func _pump() -> void:
	if _busy or _queue.is_empty():
		return
	var item: Dictionary = _queue.pop_front()
	_busy = true
	_pending[item["id"]] = item["tag"]
	var headers := PackedStringArray(["Content-Type: application/json"])
	var err := _http.request(RPC_URL, headers, HTTPClient.METHOD_POST, item["body"])
	if err != OK:
		_busy = false
		push_warning("Nft: RPC request failed to start (%s)" % err)


func _on_completed(_result: int, code: int, _headers: PackedStringArray, body: PackedByteArray) -> void:
	_busy = false
	var data: Variant = null
	if code == 200:
		data = JSON.parse_string(body.get_string_from_utf8())
	var rid := -1
	var hexres := ""
	if typeof(data) == TYPE_DICTIONARY and data.has("id") and data.has("result"):
		rid = int(data["id"])
		hexres = String(data["result"])
	if _pending.has(rid):
		_route(_pending[rid], hexres)
		_pending.erase(rid)
	_pump()


func _route(tag: String, hexres: String) -> void:
	if tag == "balance":
		balance = _hex_to_int(hexres)
		balance_updated.emit(balance)
	elif tag == "nextid":
		total = _hex_to_int(hexres)
		supply_updated.emit(total)
		_start_scan()
	elif tag.begins_with("own:"):
		var id := int(tag.substr(4))
		var owner := _decode_address(hexres)
		if owner == player and owner != "":
			_owned.append(id)
		_scan_pending -= 1
		if _scan_pending <= 0:
			_owned.sort()
			owned_updated.emit(_owned.duplicate())


func _start_scan() -> void:
	_owned.clear()
	if total <= 0:
		_scan_pending = 0
		owned_updated.emit([])
		return
	var start: int = max(1, total - SCAN_LIMIT + 1)
	_scan_pending = total - start + 1
	for id in range(start, total + 1):
		_enqueue("0x" + SEL_OWNEROF + _word_uint(id), "own:%d" % id)


# ------------------------------------------------------------- abi helpers

func _word_hex(value: int) -> String:
	var h := "%x" % value
	while h.length() < 64:
		h = "0" + h
	return h


func _word_uint(value: int) -> String:
	return _word_hex(value)


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
	var out := _word_hex(32)          # offset to the string data
	out += _word_hex(n)               # string length
	var data_hex := bytes.hex_encode() # lowercase hex of the bytes
	var pad := (32 - (n % 32)) % 32
	for _i in pad:
		data_hex += "00"
	return out + data_hex


func _hex_to_int(hexres: String) -> int:
	if hexres == "" or hexres == "0x":
		return 0
	return hexres.hex_to_int()


func _decode_address(hexres: String) -> String:
	var h := hexres
	if h.begins_with("0x"):
		h = h.substr(2)
	if h.length() < 40:
		return ""
	return "0x" + h.substr(h.length() - 40)
