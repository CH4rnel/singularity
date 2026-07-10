extends Node
## Native in-game EVM wallet: generates/stores a secp256k1 key and signs
## Cyberia transactions entirely inside Godot (keccak + secp256k1 + RLP +
## RFC 6979), so the desktop "full" build never needs MetaMask. Works on web
## too. The key is a local hot wallet in user://nocarrier_wallet.json — never
## logged or committed (see project safety rules).

signal wallet_ready(address: String)

const KEY_PATH := "user://nocarrier_wallet.json"

var _priv := PackedByteArray()   # 32 bytes; kept in memory, never printed
var _addr := ""


func _ready() -> void:
	# harness/self-test runs have no scene tree lifecycle we care about
	if _load():
		pass
	else:
		_generate(false)
	call_deferred("_announce")


func _announce() -> void:
	if _addr != "":
		wallet_ready.emit(_addr)


func has_key() -> bool:
	return _priv.size() == 32


func address() -> String:
	return _addr


func short_address() -> String:
	if _addr.length() < 12:
		return _addr
	return _addr.substr(0, 6) + "…" + _addr.substr(_addr.length() - 4)


## The private key as 0x-hex, for the player to back up (copied to clipboard,
## never printed to logs). Treat like any wallet secret.
func export_hex() -> String:
	if _priv.size() != 32:
		return ""
	return "0x" + _priv.hex_encode()


## Build a signed raw transaction from the stored key (0x-prefixed hex),
## ready for eth_sendRawTransaction.
func build_raw(nonce: int, gas_price: int, gas_limit: int, to_hex: String, value: int, data_hex: String, chain_id: int) -> String:
	return sign_transaction(_priv, nonce, gas_price, gas_limit, to_hex, value, data_hex, chain_id)


func regenerate() -> void:
	_generate(true)
	_announce()


## Import a user-supplied private key (64 hex chars, optional 0x). Returns
## false if it is malformed or out of the valid scalar range [1, n-1]. The key
## is stored locally and never logged.
func import_hex(hex: String) -> bool:
	hex = hex.strip_edges()
	if hex.begins_with("0x") or hex.begins_with("0X"):
		hex = hex.substr(2)
	if hex.length() != 64:
		return false
	var b := PackedByteArray()
	for i in range(0, 64, 2):
		var hi := _nibble(hex[i])
		var lo := _nibble(hex[i + 1])
		if hi < 0 or lo < 0:
			return false
		b.append((hi << 4) | lo)
	var d := NcBig.from_bytes(b)
	if NcBig.is_zero(d) or NcBig.cmp(d, NcBig.N()) >= 0:
		return false
	_priv = b
	_addr = "0x" + NcSecp.address_from_priv(_priv).hex_encode()
	_save()
	_announce()
	return true


static func _nibble(c: String) -> int:
	return "0123456789abcdef".find(c.to_lower())


## --- key management ----------------------------------------------------------

func _generate(save_now: bool) -> void:
	var c := Crypto.new()
	var n := NcBig.N()
	while true:
		var b := c.generate_random_bytes(32)
		var d := NcBig.from_bytes(b)
		if not NcBig.is_zero(d) and NcBig.cmp(d, n) < 0:
			_priv = b
			break
	_addr = "0x" + NcSecp.address_from_priv(_priv).hex_encode()
	if save_now or not FileAccess.file_exists(KEY_PATH):
		_save()
	if Game and Game.has_method("toast"):
		Game.toast(Loc.t("t.wallet_gen", [short_address()]))


func _save() -> void:
	var f := FileAccess.open(KEY_PATH, FileAccess.WRITE)
	if f:
		f.store_string(JSON.stringify({"priv": _priv.hex_encode(), "addr": _addr}))


func _load() -> bool:
	if not FileAccess.file_exists(KEY_PATH):
		return false
	var f := FileAccess.open(KEY_PATH, FileAccess.READ)
	if f == null:
		return false
	var data: Variant = JSON.parse_string(f.get_as_text())
	if typeof(data) != TYPE_DICTIONARY or not data.has("priv"):
		return false
	var hex := str(data["priv"])
	var b := PackedByteArray()
	for i in range(0, hex.length(), 2):
		b.append(("0x" + hex.substr(i, 2)).hex_to_int())
	if b.size() != 32:
		return false
	_priv = b
	_addr = str(data.get("addr", "0x" + NcSecp.address_from_priv(_priv).hex_encode()))
	return true


## --- static signing (pure, testable without the autoload) --------------------

static func sign_transaction(priv: PackedByteArray, nonce: int, gas_price: int, gas_limit: int, to_hex: String, value: int, data_hex: String, chain_id: int) -> String:
	var to_b := _hexb(to_hex)
	var data_b := _hexb(data_hex)
	var unsigned := NcRlp.encode([
		NcRlp.int_bytes(nonce), NcRlp.int_bytes(gas_price), NcRlp.int_bytes(gas_limit),
		to_b, NcRlp.int_bytes(value), data_b,
		NcRlp.int_bytes(chain_id), PackedByteArray(), PackedByteArray(),
	])
	var h := NcKeccak.hash256(unsigned)
	var k := rfc6979(h, priv)
	var sig := NcSecp.sign(h, priv, k)
	var v: int = int(sig["rec"]) + chain_id * 2 + 35
	var signed := NcRlp.encode([
		NcRlp.int_bytes(nonce), NcRlp.int_bytes(gas_price), NcRlp.int_bytes(gas_limit),
		to_b, NcRlp.int_bytes(value), data_b,
		NcRlp.int_bytes(v), NcRlp.strip_zeros(sig["r"]), NcRlp.strip_zeros(sig["s"]),
	])
	return "0x" + signed.hex_encode()


## RFC 6979 deterministic nonce (HMAC-SHA256) — removes any dependence on RNG
## quality for the ECDSA nonce, the classic secp256k1 footgun.
static func rfc6979(h: PackedByteArray, priv: PackedByteArray) -> PackedInt64Array:
	var n := NcBig.N()
	var b2o := NcBig.to_bytes(NcBig.mod(NcBig.from_bytes(h), n), 32)
	var v := _rep(0x01, 32)
	var k := _rep(0x00, 32)
	k = _hmac(k, _cat([v, PackedByteArray([0x00]), priv, b2o]))
	v = _hmac(k, v)
	k = _hmac(k, _cat([v, PackedByteArray([0x01]), priv, b2o]))
	v = _hmac(k, v)
	var guard := 0
	while guard < 64:
		guard += 1
		var t := PackedByteArray()
		while t.size() < 32:
			v = _hmac(k, v)
			t.append_array(v)
		var cand := NcBig.from_bytes(t)
		if not NcBig.is_zero(cand) and NcBig.cmp(cand, n) < 0:
			return cand
		k = _hmac(k, _cat([v, PackedByteArray([0x00])]))
		v = _hmac(k, v)
	return NcBig.from_int(1)


static func _hmac(key: PackedByteArray, msg: PackedByteArray) -> PackedByteArray:
	var ctx := HMACContext.new()
	ctx.start(HashingContext.HASH_SHA256, key)
	ctx.update(msg)
	return ctx.finish()


static func _cat(parts: Array) -> PackedByteArray:
	var out := PackedByteArray()
	for p in parts:
		out.append_array(p)
	return out


static func _rep(byte: int, count: int) -> PackedByteArray:
	var b := PackedByteArray()
	b.resize(count)
	b.fill(byte)
	return b


static func _hexb(h: String) -> PackedByteArray:
	if h.begins_with("0x"):
		h = h.substr(2)
	if h.length() % 2 == 1:
		h = "0" + h
	var b := PackedByteArray()
	for i in range(0, h.length(), 2):
		b.append(("0x" + h.substr(i, 2)).hex_to_int())
	return b
