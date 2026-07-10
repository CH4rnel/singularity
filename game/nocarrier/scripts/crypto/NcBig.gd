class_name NcBig
extends RefCounted
## Minimal unsigned big-integer over little-endian base-2^16 limbs
## (PackedInt64Array), enough for secp256k1 field/scalar arithmetic and the
## RLP integer encoding. Not constant-time — this is a game hot wallet, not an
## HSM. All functions are static and pure.

const MASK := 0xFFFF

static var _p_cache: PackedInt64Array
static var _n_cache: PackedInt64Array


static func P() -> PackedInt64Array:
	if _p_cache.is_empty():
		_p_cache = from_hex("FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F")
	return _p_cache


static func N() -> PackedInt64Array:
	if _n_cache.is_empty():
		_n_cache = from_hex("FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141")
	return _n_cache


## --- construction / conversion ------------------------------------------------

static func from_int(n: int) -> PackedInt64Array:
	var out := PackedInt64Array()
	while n > 0:
		out.append(n & MASK)
		n >>= 16
	return out


static func from_bytes(b: PackedByteArray) -> PackedInt64Array:
	# big-endian bytes -> little-endian 16-bit limbs
	var limbs := PackedInt64Array()
	var i := b.size() - 1
	while i >= 0:
		var lo := int(b[i])
		var hi := int(b[i - 1]) if i - 1 >= 0 else 0
		limbs.append((hi << 8) | lo)
		i -= 2
	return trim(limbs)


static func from_hex(h: String) -> PackedInt64Array:
	h = h.strip_edges()
	if h.begins_with("0x") or h.begins_with("0X"):
		h = h.substr(2)
	if h.length() % 2 == 1:
		h = "0" + h
	var bytes := PackedByteArray()
	for i in range(0, h.length(), 2):
		bytes.append(("0x" + h.substr(i, 2)).hex_to_int())
	return from_bytes(bytes)


static func to_bytes(a: PackedInt64Array, length: int) -> PackedByteArray:
	var out := PackedByteArray()
	out.resize(length)
	out.fill(0)
	for i in a.size():
		var v: int = a[i]
		var pos_lo := length - 1 - 2 * i
		var pos_hi := pos_lo - 1
		if pos_lo >= 0:
			out[pos_lo] = v & 0xFF
		if pos_hi >= 0:
			out[pos_hi] = (v >> 8) & 0xFF
	return out


## --- inspection ---------------------------------------------------------------

static func trim(a: PackedInt64Array) -> PackedInt64Array:
	var n := a.size()
	while n > 0 and a[n - 1] == 0:
		n -= 1
	a.resize(n)
	return a


static func _msize(a: PackedInt64Array) -> int:
	var n := a.size()
	while n > 0 and a[n - 1] == 0:
		n -= 1
	return n


static func is_zero(a: PackedInt64Array) -> bool:
	return _msize(a) == 0


static func cmp(a: PackedInt64Array, b: PackedInt64Array) -> int:
	var na := _msize(a)
	var nb := _msize(b)
	if na != nb:
		return -1 if na < nb else 1
	for i in range(na - 1, -1, -1):
		if a[i] != b[i]:
			return -1 if a[i] < b[i] else 1
	return 0


static func get_bit(a: PackedInt64Array, i: int) -> int:
	var limb := i >> 4
	if limb >= a.size():
		return 0
	return (a[limb] >> (i & 15)) & 1


## --- arithmetic ---------------------------------------------------------------

static func add(a: PackedInt64Array, b: PackedInt64Array) -> PackedInt64Array:
	var n: int = max(a.size(), b.size())
	var out := PackedInt64Array()
	var carry := 0
	for i in n:
		var v := carry
		if i < a.size():
			v += a[i]
		if i < b.size():
			v += b[i]
		out.append(v & MASK)
		carry = v >> 16
	if carry > 0:
		out.append(carry)
	return out


## a - b, assumes a >= b.
static func sub(a: PackedInt64Array, b: PackedInt64Array) -> PackedInt64Array:
	var out := PackedInt64Array()
	var borrow := 0
	for i in a.size():
		var d := a[i] - borrow - (b[i] if i < b.size() else 0)
		if d < 0:
			d += 0x10000
			borrow = 1
		else:
			borrow = 0
		out.append(d)
	return trim(out)


static func mul(a: PackedInt64Array, b: PackedInt64Array) -> PackedInt64Array:
	if a.is_empty() or b.is_empty():
		return PackedInt64Array()
	var out := PackedInt64Array()
	out.resize(a.size() + b.size())
	out.fill(0)
	for i in a.size():
		var carry := 0
		for j in b.size():
			var idx := i + j
			var t: int = out[idx] + a[i] * b[j] + carry
			out[idx] = t & MASK
			carry = t >> 16
		var k := i + b.size()
		while carry > 0:
			var t2: int = out[k] + carry
			out[k] = t2 & MASK
			carry = t2 >> 16
			k += 1
	return trim(out)


static func mul_small(a: PackedInt64Array, c: int) -> PackedInt64Array:
	var out := PackedInt64Array()
	var carry := 0
	for i in a.size():
		var v: int = a[i] * c + carry
		out.append(v & MASK)
		carry = v >> 16
	while carry > 0:
		out.append(carry & MASK)
		carry >>= 16
	return trim(out)


## Multiply by base^k (shift up by k 16-bit limbs).
static func shl_limbs(a: PackedInt64Array, k: int) -> PackedInt64Array:
	if is_zero(a):
		return PackedInt64Array()
	var out := PackedInt64Array()
	out.resize(k)
	out.fill(0)
	out.append_array(a)
	return out


static func lshift1(a: PackedInt64Array) -> PackedInt64Array:
	var out := PackedInt64Array()
	var carry := 0
	for i in a.size():
		var v: int = (a[i] << 1) | carry
		out.append(v & MASK)
		carry = v >> 16
	if carry > 0:
		out.append(carry)
	return out


static func _slice(a: PackedInt64Array, s: int, e: int) -> PackedInt64Array:
	var out := PackedInt64Array()
	for i in range(s, min(e, a.size())):
		out.append(a[i])
	return out


## --- modular: secp256k1 field (mod p) -----------------------------------------

## Reduce an arbitrary-size value mod p = 2^256 - 2^32 - 977, exploiting
## 2^256 ≡ 2^32 + 977 (mod p).
static func mod_p(a: PackedInt64Array) -> PackedInt64Array:
	var p := P()
	a = trim(a.duplicate())
	var guard := 0
	while _msize(a) > 16:
		guard += 1
		if guard > 24:
			break
		var lo := _slice(a, 0, 16)
		var hi := _slice(a, 16, _msize(a))
		var t1 := shl_limbs(hi, 2)      # hi * 2^32
		var t2 := mul_small(hi, 977)    # hi * 977
		a = trim(add(add(lo, t1), t2))
	while cmp(a, p) >= 0:
		a = sub(a, p)
	return a


static func add_mod_p(a: PackedInt64Array, b: PackedInt64Array) -> PackedInt64Array:
	return mod_p(add(a, b))


static func sub_mod_p(a: PackedInt64Array, b: PackedInt64Array) -> PackedInt64Array:
	if cmp(a, b) >= 0:
		return sub(a, b)
	return sub(add(a, P()), b)


static func mul_mod_p(a: PackedInt64Array, b: PackedInt64Array) -> PackedInt64Array:
	return mod_p(mul(a, b))


static func smp(a: PackedInt64Array, c: int) -> PackedInt64Array:
	return mod_p(mul_small(a, c))


static func modpow_p(base: PackedInt64Array, exp: PackedInt64Array) -> PackedInt64Array:
	var result := from_int(1)
	var nbits := _msize(exp) * 16
	for bit in range(nbits - 1, -1, -1):
		result = mul_mod_p(result, result)
		if get_bit(exp, bit) == 1:
			result = mul_mod_p(result, base)
	return result


static func inv_p(a: PackedInt64Array) -> PackedInt64Array:
	return modpow_p(a, sub(P(), from_int(2)))


## --- modular: generic (mod n and friends) -------------------------------------

## a mod m via binary long division. Used for the group order n (cold path).
static func mod(a: PackedInt64Array, m: PackedInt64Array) -> PackedInt64Array:
	a = trim(a.duplicate())
	if cmp(a, m) < 0:
		return a
	var r := PackedInt64Array()
	var top := a.size() * 16 - 1
	for bit in range(top, -1, -1):
		r = lshift1(r)
		if get_bit(a, bit) == 1:
			if r.is_empty():
				r.append(1)
			else:
				r[0] = r[0] | 1
		if cmp(r, m) >= 0:
			r = sub(r, m)
	return trim(r)


static func add_mod_n(a: PackedInt64Array, b: PackedInt64Array) -> PackedInt64Array:
	return mod(add(a, b), N())


static func mul_mod_n(a: PackedInt64Array, b: PackedInt64Array) -> PackedInt64Array:
	return mod(mul(a, b), N())


static func modpow_n(base: PackedInt64Array, exp: PackedInt64Array) -> PackedInt64Array:
	var result := from_int(1)
	var nbits := _msize(exp) * 16
	for bit in range(nbits - 1, -1, -1):
		result = mul_mod_n(result, result)
		if get_bit(exp, bit) == 1:
			result = mul_mod_n(result, base)
	return result


static func inv_n(a: PackedInt64Array) -> PackedInt64Array:
	return modpow_n(a, sub(N(), from_int(2)))
