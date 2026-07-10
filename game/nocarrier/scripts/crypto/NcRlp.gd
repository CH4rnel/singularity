class_name NcRlp
extends RefCounted
## Recursive-length-prefix encoding, enough to build Ethereum legacy
## (EIP-155) transactions. encode() takes a PackedByteArray (byte string) or
## an Array (list, possibly nested) and returns the encoded bytes.

static func encode(item) -> PackedByteArray:
	if item is Array:
		var payload := PackedByteArray()
		for it in item:
			payload.append_array(encode(it))
		return _prefix(payload, 0xc0, 0xf7)
	var b: PackedByteArray = item
	if b.size() == 1 and b[0] < 0x80:
		return b
	return _prefix(b, 0x80, 0xb7)


static func _prefix(b: PackedByteArray, short_base: int, long_base: int) -> PackedByteArray:
	var out := PackedByteArray()
	if b.size() <= 55:
		out.append(short_base + b.size())
	else:
		var lb := int_bytes(b.size())
		out.append(long_base + lb.size())
		out.append_array(lb)
	out.append_array(b)
	return out


## Minimal big-endian encoding of a non-negative integer (0 -> empty string),
## as RLP expects for scalar quantities.
static func int_bytes(n: int) -> PackedByteArray:
	var b := PackedByteArray()
	while n > 0:
		b.insert(0, n & 0xFF)
		n >>= 8
	return b


static func strip_zeros(b: PackedByteArray) -> PackedByteArray:
	var i := 0
	while i < b.size() and b[i] == 0:
		i += 1
	return b.slice(i)
