class_name NcKeccak
extends RefCounted
## Keccak-256 (Ethereum's pre-standard SHA-3, domain suffix 0x01) in pure
## GDScript. Godot's HashingContext offers SHA-256 but not Keccak, and EVM
## addresses / transaction hashes need Keccak-256. Lanes are 64-bit; GDScript
## ints are exactly 64-bit two's complement, so bitwise ops wrap correctly and
## only the rotate needs an explicit logical-shift mask.

const RATE := 136  # 1088-bit rate for 256-bit output


static func hash256(msg: PackedByteArray) -> PackedByteArray:
	var data := msg.duplicate()
	var q := RATE - (data.size() % RATE)
	if q == 0:
		q = RATE
	var pad := PackedByteArray()
	pad.resize(q)
	pad.fill(0)
	pad[0] = pad[0] | 0x01
	pad[q - 1] = pad[q - 1] | 0x80
	data.append_array(pad)

	var st: Array = []
	st.resize(25)
	st.fill(0)

	var off := 0
	while off < data.size():
		for i in 17:  # 17 lanes * 8 bytes = 136-byte rate
			var lane := 0
			for b in 8:
				lane = lane | (int(data[off + i * 8 + b]) << (8 * b))
			st[i] = st[i] ^ lane
		_keccakf(st)
		off += RATE

	var out := PackedByteArray()
	for i in 4:  # first 32 bytes = 4 lanes
		var lane: int = st[i]
		for b in 8:
			out.append((lane >> (8 * b)) & 0xFF)
	return out


static func _rotl(x: int, n: int) -> int:
	# n in 1..63; logical right shift via masking the sign-extended bits away
	return (x << n) | ((x >> (64 - n)) & ((1 << n) - 1))


static func _rc() -> Array:
	var hi := 1 << 63
	return [
		0x0000000000000001, 0x0000000000008082, hi | 0x808a, hi | 0x80008000,
		0x000000000000808b, 0x0000000080000001, hi | 0x80008081, hi | 0x8009,
		0x000000000000008a, 0x0000000000000088, 0x0000000080008009, 0x000000008000000a,
		0x000000008000808b, hi | 0x8b, hi | 0x8089, hi | 0x8003,
		hi | 0x8002, hi | 0x80, 0x000000000000800a, hi | 0x8000000a,
		hi | 0x80008081, hi | 0x8080, 0x0000000080000001, hi | 0x80008008,
	]


static func _keccakf(st: Array) -> void:
	var rc := _rc()
	var rotc := [1, 3, 6, 10, 15, 21, 28, 36, 45, 55, 2, 14,
		27, 41, 56, 8, 25, 43, 62, 18, 39, 61, 20, 44]
	var piln := [10, 7, 11, 17, 18, 3, 5, 16, 8, 21, 24, 4,
		15, 23, 19, 13, 12, 2, 20, 14, 22, 9, 6, 1]
	var bc := [0, 0, 0, 0, 0]

	for r in 24:
		# theta
		for i in 5:
			bc[i] = st[i] ^ st[i + 5] ^ st[i + 10] ^ st[i + 15] ^ st[i + 20]
		for i in 5:
			var t: int = bc[(i + 4) % 5] ^ _rotl(bc[(i + 1) % 5], 1)
			for j in range(0, 25, 5):
				st[j + i] = st[j + i] ^ t
		# rho + pi
		var t2: int = st[1]
		for i in 24:
			var j: int = piln[i]
			var tmp: int = st[j]
			st[j] = _rotl(t2, rotc[i])
			t2 = tmp
		# chi
		for j in range(0, 25, 5):
			for i in 5:
				bc[i] = st[j + i]
			for i in 5:
				st[j + i] = st[j + i] ^ ((~bc[(i + 1) % 5]) & bc[(i + 2) % 5])
		# iota
		st[0] = st[0] ^ rc[r]
