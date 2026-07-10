class_name NcSecp
extends RefCounted
## secp256k1 in pure GDScript: key -> public key / address, and ECDSA signing
## with a caller-supplied (RFC 6979) nonce. Points are Jacobian [X, Y, Z] limb
## arrays mod p; infinity is Z == 0. Enough to sign Ethereum transactions
## without any native library.

static var _gx: PackedInt64Array
static var _gy: PackedInt64Array


static func _g() -> Array:
	if _gx.is_empty():
		_gx = NcBig.from_hex("79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798")
		_gy = NcBig.from_hex("483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8")
	return [_gx, _gy, NcBig.from_int(1)]


static func _inf() -> Array:
	return [NcBig.from_int(1), NcBig.from_int(1), PackedInt64Array()]


static func _is_inf(p: Array) -> bool:
	return NcBig.is_zero(p[2])


# short aliases for field ops
static func mp(a: PackedInt64Array, b: PackedInt64Array) -> PackedInt64Array: return NcBig.mul_mod_p(a, b)
static func ap(a: PackedInt64Array, b: PackedInt64Array) -> PackedInt64Array: return NcBig.add_mod_p(a, b)
static func sp(a: PackedInt64Array, b: PackedInt64Array) -> PackedInt64Array: return NcBig.sub_mod_p(a, b)
static func smp(a: PackedInt64Array, c: int) -> PackedInt64Array: return NcBig.smp(a, c)


static func _dbl(p: Array) -> Array:
	if _is_inf(p) or NcBig.is_zero(p[1]):
		return _inf()
	var x: PackedInt64Array = p[0]
	var y: PackedInt64Array = p[1]
	var z: PackedInt64Array = p[2]
	var a := mp(x, x)                       # A = X^2
	var b := mp(y, y)                       # B = Y^2
	var c := mp(b, b)                       # C = B^2
	var xb := ap(x, b)
	var d := smp(sp(sp(mp(xb, xb), a), c), 2)   # D = 2*((X+B)^2 - A - C)
	var e := smp(a, 3)                      # E = 3A
	var f := mp(e, e)                       # F = E^2
	var x3 := sp(f, ap(d, d))               # X3 = F - 2D
	var y3 := sp(mp(e, sp(d, x3)), smp(c, 8))   # Y3 = E*(D - X3) - 8C
	var yz := mp(y, z)
	var z3 := ap(yz, yz)                    # Z3 = 2YZ
	return [x3, y3, z3]


static func _add(p: Array, q: Array) -> Array:
	if _is_inf(p):
		return q
	if _is_inf(q):
		return p
	var x1: PackedInt64Array = p[0]
	var y1: PackedInt64Array = p[1]
	var z1: PackedInt64Array = p[2]
	var x2: PackedInt64Array = q[0]
	var y2: PackedInt64Array = q[1]
	var z2: PackedInt64Array = q[2]
	var z1z1 := mp(z1, z1)
	var z2z2 := mp(z2, z2)
	var u1 := mp(x1, z2z2)
	var u2 := mp(x2, z1z1)
	var s1 := mp(mp(y1, z2), z2z2)
	var s2 := mp(mp(y2, z1), z1z1)
	if NcBig.cmp(u1, u2) == 0:
		if NcBig.cmp(s1, s2) != 0:
			return _inf()
		return _dbl(p)
	var h := sp(u2, u1)
	var hh := mp(h, h)
	var i := smp(hh, 4)
	var j := mp(h, i)
	var r := smp(sp(s2, s1), 2)
	var v := mp(u1, i)
	var x3 := sp(sp(mp(r, r), j), ap(v, v))         # r^2 - J - 2V
	var s1j := mp(s1, j)
	var y3 := sp(mp(r, sp(v, x3)), ap(s1j, s1j))    # r*(V - X3) - 2*S1*J
	var z1z2 := ap(z1, z2)
	var z3 := mp(sp(sp(mp(z1z2, z1z2), z1z1), z2z2), h)
	return [x3, y3, z3]


static func _to_affine(p: Array) -> Array:
	# returns [x, y] limb arrays, or [] for infinity
	if _is_inf(p):
		return []
	var zi := NcBig.inv_p(p[2])
	var zi2 := mp(zi, zi)
	var zi3 := mp(zi2, zi)
	return [mp(p[0], zi2), mp(p[1], zi3)]


static func scalar_mul(k: PackedInt64Array, point: Array) -> Array:
	var r := _inf()
	for bit in range(255, -1, -1):
		r = _dbl(r)
		if NcBig.get_bit(k, bit) == 1:
			r = _add(r, point)
	return r


## Public key as uncompressed 64-byte X||Y.
static func pubkey_from_priv(priv: PackedByteArray) -> PackedByteArray:
	var d := NcBig.from_bytes(priv)
	var aff := _to_affine(scalar_mul(d, _g()))
	var out := NcBig.to_bytes(aff[0], 32)
	out.append_array(NcBig.to_bytes(aff[1], 32))
	return out


## 20-byte Ethereum address = keccak256(pubkey)[12:].
static func address_from_priv(priv: PackedByteArray) -> PackedByteArray:
	var h := NcKeccak.hash256(pubkey_from_priv(priv))
	return h.slice(12, 32)


## ECDSA sign of a 32-byte hash with a 32-byte private key and a caller-chosen
## nonce k (limb array, RFC 6979). Returns {r, s, rec} with low-s normalisation
## and the EIP-155 recovery id, or {} if the (astronomically rare) degenerate
## r/s == 0 case is hit (caller should retry with the next RFC 6979 candidate).
static func sign(hash32: PackedByteArray, priv: PackedByteArray, k: PackedInt64Array) -> Dictionary:
	var n := NcBig.N()
	var d := NcBig.from_bytes(priv)
	var aff := _to_affine(scalar_mul(k, _g()))
	var rx: PackedInt64Array = aff[0]
	var ry: PackedInt64Array = aff[1]
	var r := NcBig.mod(rx, n)
	if NcBig.is_zero(r):
		return {}
	var z := NcBig.mod(NcBig.from_bytes(hash32), n)
	var kinv := NcBig.inv_n(k)
	var s := NcBig.mul_mod_n(kinv, NcBig.add_mod_n(z, NcBig.mul_mod_n(r, d)))
	if NcBig.is_zero(s):
		return {}
	var rec := ry[0] & 1 if ry.size() > 0 else 0
	if NcBig.cmp(rx, n) >= 0:
		rec += 2
	var half := _half_n()
	if NcBig.cmp(s, half) > 0:
		s = NcBig.sub(n, s)
		rec ^= 1
	return {"r": NcBig.to_bytes(r, 32), "s": NcBig.to_bytes(s, 32), "rec": rec}


static var _half_cache: PackedInt64Array
static func _half_n() -> PackedInt64Array:
	if _half_cache.is_empty():
		# n >> 1
		var n := NcBig.N()
		var out := PackedInt64Array()
		out.resize(n.size())
		var carry := 0
		for i in range(n.size() - 1, -1, -1):
			var v: int = (carry << 16) | n[i]
			out[i] = v >> 1
			carry = v & 1
		_half_cache = NcBig.trim(out)
	return _half_cache
