extends Node
## On-chain sealing for NO CARRIER. Decoded NONSTANDARD / echo captures can be
## sealed to the Cyberia chain as CyberiaNFTs via `mint(string)`. Two signing
## backends:
##   • native  — Godot signs locally (Signer); default everywhere, the only
##               path on desktop. Fetches nonce/gas, builds+signs+sends raw.
##   • browser — MetaMask / EIP-1193 (Wallet); optional, web only.
## Reads (balanceOf) are anonymous JSON-RPC and work on desktop too.

signal balance_updated(count: int)
signal seal_result(ok: bool, info: String)

const NFT_ADDRESS := "0x546462FAbf30734E63b64f32B30EC8ADD9B6EBa7"
const RPC_URL := "https://rpc.cyberia.church"
const CHAIN_ID := 49406
const SEL_MINT := "d85d3d27"       # mint(string)
const SEL_BALANCEOF := "70a08231"  # balanceOf(address)

var balance := 0
var pending_file_id := -1

var _active := ""
# in-flight native seal
var _seal_data := ""
var _seal_id := -1
var _nonce := -1
var _gas_price := -1
var _gas := -1


func _ready() -> void:
	Wallet.address_changed.connect(func(_a: String) -> void: _sync_active())
	Wallet.status_changed.connect(func(_s: String) -> void: _sync_active())
	Wallet.tx_sent.connect(_on_meta_tx_sent)
	Wallet.tx_failed.connect(_on_meta_tx_failed)
	Signer.wallet_ready.connect(func(_a: String) -> void: _sync_active())
	call_deferred("_sync_active")


## Whichever wallet is authoritative right now.
func using_metamask() -> bool:
	return Wallet.available() and Wallet.is_connected_wallet()


func active_address() -> String:
	if using_metamask():
		return Wallet.get_address()
	if Signer.has_key():
		return Signer.address()
	return ""


func _sync_active() -> void:
	var a := active_address()
	if a == _active:
		return
	_active = a
	Chain.set_player(a)
	balance = 0
	balance_updated.emit(0)
	refresh()


func refresh() -> void:
	if _active == "":
		return
	# routed through Chain's serialized queue so it never collides with the
	# balance poll or an in-flight seal call
	Chain.call_rpc("eth_call",
		[{"to": NFT_ADDRESS, "data": "0x" + SEL_BALANCEOF + _word_address(_active)}, "latest"],
		_on_balance)


func _on_balance(ok: bool, val: String) -> void:
	if not ok:
		return
	balance = 0 if val == "" or val == "0x" else val.hex_to_int()
	balance_updated.emit(balance)


## --- sealing ------------------------------------------------------------------

func seal(f: Dictionary) -> void:
	if pending_file_id >= 0:
		seal_result.emit(false, Loc.t("up.pending"))
		return
	var uri := _uri_for(f)
	var data := "0x" + SEL_MINT + _encode_string_arg(uri)
	if using_metamask():
		pending_file_id = int(f["id"])
		Wallet.send_transaction(NFT_ADDRESS, data)
		return
	if not Signer.has_key():
		seal_result.emit(false, Loc.t("up.connect_first"))
		return
	# native path
	pending_file_id = int(f["id"])
	_seal_id = int(f["id"])
	_seal_data = data
	_nonce = -1
	_gas_price = -1
	_gas = -1
	Chain.call_rpc("eth_getTransactionCount", [_active, "pending"], _on_rpc.bind("nonce"))
	Chain.call_rpc("eth_gasPrice", [], _on_rpc.bind("gasprice"))
	Chain.call_rpc("eth_estimateGas", [{"from": _active, "to": NFT_ADDRESS, "data": data}], _on_rpc.bind("gas"))


func _on_rpc(ok: bool, val: String, which: String) -> void:
	match which:
		"nonce":
			_nonce = val.hex_to_int() if ok else 0
		"gasprice":
			_gas_price = val.hex_to_int() if ok else 1000000000
		"gas":
			_gas = int(val.hex_to_int() * 1.25) if ok else 300000
	if _nonce >= 0 and _gas_price >= 0 and _gas >= 0:
		_do_native_send()


func _do_native_send() -> void:
	# signing is pure GDScript (~0.4s); acceptable for an occasional mint
	var raw := Signer.build_raw(_nonce, _gas_price, _gas, NFT_ADDRESS, 0, _seal_data, CHAIN_ID)
	# reset the accumulator so a later reply can't re-trigger
	_nonce = -1
	_gas_price = -1
	_gas = -1
	Chain.call_rpc("eth_sendRawTransaction", [raw], _on_native_sent)


func _on_native_sent(ok: bool, val: String) -> void:
	if ok:
		Net.seal_file(_seal_id, val)
		seal_result.emit(true, val)
		refresh()
	else:
		Game.toast(Loc.t("up.txfail", [val.left(60)]))
		seal_result.emit(false, val)
	pending_file_id = -1
	_seal_id = -1


func _on_meta_tx_sent(tx: String) -> void:
	if pending_file_id < 0:
		return
	var fid := pending_file_id
	pending_file_id = -1
	Net.seal_file(fid, tx)
	seal_result.emit(true, tx)
	refresh()


func _on_meta_tx_failed(msg: String) -> void:
	if pending_file_id < 0:
		return
	pending_file_id = -1
	seal_result.emit(false, msg)
	Game.toast(Loc.t("up.txfail", [msg.left(60)]))


func _uri_for(f: Dictionary) -> String:
	# chain metadata is language-independent (always en)
	var title := Loc.t_in("en", "title." + str(f["cls"]), [int(f.get("ti", 0))])
	return "nocarrier://day%d/%s/%s" % [Game.day, str(f["name"]), title]


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
	var out := _word_hex(32)
	out += _word_hex(n)
	var data_hex := bytes.hex_encode()
	var pad := (32 - (n % 32)) % 32
	for _i in pad:
		data_hex += "00"
	return out + data_hex
