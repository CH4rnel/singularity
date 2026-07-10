extends SceneTree
## Validates the pure-GDScript crypto stack against published vectors:
## Keccak-256, secp256k1 address derivation, and a full EIP-155 signed
## transaction (which exercises keccak + RLP + ECDSA + RFC 6979 + low-s +
## recovery id all at once). Run:
##   godot4 --headless --path . -s tests/crypto_test.gd

var _fail := 0


func _check(cond: bool, name: String) -> void:
	if cond:
		print("ok - ", name)
	else:
		_fail += 1
		printerr("FAIL - ", name)


func _hexb(h: String) -> PackedByteArray:
	if h.begins_with("0x"):
		h = h.substr(2)
	var b := PackedByteArray()
	for i in range(0, h.length(), 2):
		b.append(("0x" + h.substr(i, 2)).hex_to_int())
	return b


func _hmac(key: PackedByteArray, msg: PackedByteArray) -> PackedByteArray:
	var ctx := HMACContext.new()
	ctx.start(HashingContext.HASH_SHA256, key)
	ctx.update(msg)
	return ctx.finish()


func _cat(parts: Array) -> PackedByteArray:
	var out := PackedByteArray()
	for p in parts:
		out.append_array(p)
	return out


func _rep(byte: int, count: int) -> PackedByteArray:
	var b := PackedByteArray()
	b.resize(count)
	b.fill(byte)
	return b


func _rfc6979(h: PackedByteArray, priv: PackedByteArray) -> PackedInt64Array:
	var n := NcBig.N()
	var zmod := NcBig.mod(NcBig.from_bytes(h), n)
	var b2o := NcBig.to_bytes(zmod, 32)
	var v := _rep(0x01, 32)
	var k := _rep(0x00, 32)
	k = _hmac(k, _cat([v, _hexb("00"), priv, b2o]))
	v = _hmac(k, v)
	k = _hmac(k, _cat([v, _hexb("01"), priv, b2o]))
	v = _hmac(k, v)
	while true:
		var t := PackedByteArray()
		while t.size() < 32:
			v = _hmac(k, v)
			t.append_array(v)
		var cand := NcBig.from_bytes(t)
		if not NcBig.is_zero(cand) and NcBig.cmp(cand, n) < 0:
			return cand
		k = _hmac(k, _cat([v, _hexb("00")]))
		v = _hmac(k, v)
	return PackedInt64Array()


func _sign_tx(priv: PackedByteArray, nonce: int, gp: int, gl: int, to: String, value: int, data: String, cid: int) -> String:
	var to_b := _hexb(to)
	var data_b := _hexb(data)
	var unsigned := NcRlp.encode([
		NcRlp.int_bytes(nonce), NcRlp.int_bytes(gp), NcRlp.int_bytes(gl),
		to_b, NcRlp.int_bytes(value), data_b,
		NcRlp.int_bytes(cid), PackedByteArray(), PackedByteArray(),
	])
	var h := NcKeccak.hash256(unsigned)
	var k := _rfc6979(h, priv)
	var sig := NcSecp.sign(h, priv, k)
	var v: int = sig["rec"] + cid * 2 + 35
	var signed := NcRlp.encode([
		NcRlp.int_bytes(nonce), NcRlp.int_bytes(gp), NcRlp.int_bytes(gl),
		to_b, NcRlp.int_bytes(value), data_b,
		NcRlp.int_bytes(v), NcRlp.strip_zeros(sig["r"]), NcRlp.strip_zeros(sig["s"]),
	])
	return "0x" + signed.hex_encode()


func _initialize() -> void:
	var t0 := Time.get_ticks_msec()

	# --- Keccak-256 vectors ---
	_check(NcKeccak.hash256(PackedByteArray()).hex_encode()
		== "c5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470",
		"keccak256('')")
	_check(NcKeccak.hash256("abc".to_utf8_buffer()).hex_encode()
		== "4e03657aea45a94fc7d47ba826c8d667c0d1e6e33a64a036ec44f58fa12d6c45",
		"keccak256('abc')")

	# --- address / pubkey for private key = 1 ---
	var priv1 := _hexb("0000000000000000000000000000000000000000000000000000000000000001")
	var pub1 := NcSecp.pubkey_from_priv(priv1)
	_check(pub1.slice(0, 32).hex_encode()
		== "79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798",
		"pubkey(1).x == G.x")
	_check(NcSecp.address_from_priv(priv1).hex_encode()
		== "7e5f4552091a69125d5dfcb7b8c2659029395bdf", "address(priv=1)")

	# --- full EIP-155 signed transaction (spec example) ---
	var priv := _hexb("4646464646464646464646464646464646464646464646464646464646464646")
	var raw := _sign_tx(priv, 9, 20000000000, 21000,
		"3535353535353535353535353535353535353535", 1000000000000000000, "", 1)
	var want := "0xf86c098504a817c800825208943535353535353535353535353535353535353535880de0b6b3a76400008025a028ef61340bd939bc2195fe537567866003e1a15d3c71ff63e1590620aa636276a067cbe9d8997f761aecb703304b3800ccf555c9f3dc64214b297fb1966a3b6d83"
	_check(raw == want, "EIP-155 signed tx matches spec vector")
	if raw != want:
		printerr("  got:  ", raw)
		printerr("  want: ", want)

	# --- determinism (RFC 6979) ---
	var raw2 := _sign_tx(priv, 9, 20000000000, 21000,
		"3535353535353535353535353535353535353535", 1000000000000000000, "", 1)
	_check(raw == raw2, "signing is deterministic")

	print("--- crypto in %d ms" % (Time.get_ticks_msec() - t0))
	print("failures: ", _fail)
	quit(1 if _fail > 0 else 0)
