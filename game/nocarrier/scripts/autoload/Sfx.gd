extends Node
## Procedural audio: every sound is synthesized into an AudioStreamWAV at
## first use, so the game ships zero audio assets. play(name) fires a
## transient player; the room-tone hum is a persistent looped player.

const RATE := 22050

var enabled := true
var _cache := {}
var _hum_player: AudioStreamPlayer


func _ready() -> void:
	enabled = DisplayServer.get_name() != "headless"
	if not enabled:
		return
	_hum_player = AudioStreamPlayer.new()
	_hum_player.stream = _build("hum")
	_hum_player.volume_db = -22.0
	if AudioServer.get_bus_index("Amb") != -1:
		_hum_player.bus = "Amb"
	add_child(_hum_player)
	_hum_player.play()


func set_hum(on: bool) -> void:
	if not enabled or _hum_player == null:
		return
	if on and not _hum_player.playing:
		_hum_player.play()
	elif not on and _hum_player.playing:
		_hum_player.stop()


func play(name: String, vol_db := -8.0) -> void:
	# is_inside_tree also covers `godot4 -s` harness runs, where autoload
	# _ready never fires and playback would error out of tree
	if not enabled or not is_inside_tree():
		return
	var stream: AudioStreamWAV = _cache.get(name)
	if stream == null:
		stream = _build(name)
		_cache[name] = stream
	var p := AudioStreamPlayer.new()
	p.stream = stream
	p.volume_db = vol_db
	if AudioServer.get_bus_index("Sfx") != -1:
		p.bus = "Sfx"
	add_child(p)
	p.finished.connect(p.queue_free)
	p.play()


## --- synthesis ---------------------------------------------------------------

func _build(name: String) -> AudioStreamWAV:
	match name:
		"hum":
			var s := _silence(3.0)
			_add_tone(s, 48.0, 0.30)
			_add_tone(s, 96.0, 0.14)
			_add_tone(s, 144.0, 0.06)
			_add_noise(s, 0.015)
			var wav := _pcm(s)
			wav.loop_mode = AudioStreamWAV.LOOP_FORWARD
			wav.loop_begin = 0
			wav.loop_end = s.size()
			return wav
		"beep":
			return _pcm(_tone(880.0, 0.12, 0.5, 14.0))
		"blip":
			return _pcm(_tone(440.0, 0.07, 0.4, 20.0))
		"key":
			return _pcm(_tone(2200.0, 0.02, 0.15, 60.0))
		"deny":
			return _pcm(_tone(120.0, 0.22, 0.5, 8.0, 1))
		"alarm":
			var s := _tone(680.0, 0.16, 0.45, 4.0, 1)
			s.append_array(_silence_f(0.08))
			s.append_array(_tone(880.0, 0.16, 0.45, 4.0, 1))
			return _pcm(s)
		"thud":
			var s := _tone(52.0, 0.55, 0.9, 7.0)
			var n := _noise(0.12, 0.35, 26.0)
			for i in mini(s.size(), n.size()):
				s[i] += n[i]
			return _pcm(s)
		"knock":
			var s := PackedFloat32Array()
			for k in 3:
				var hit := _tone(74.0, 0.16, 0.85, 16.0)
				var n2 := _noise(0.05, 0.3, 50.0)
				for i in mini(hit.size(), n2.size()):
					hit[i] += n2[i]
				s.append_array(hit)
				s.append_array(_silence_f(0.22))
			return _pcm(s)
		"static":
			return _pcm(_noise(1.1, 0.5, 2.2))
		"ring":
			var s := _silence(1.1)
			for i in s.size():
				var t := float(i) / RATE
				if t < 0.9:
					var trem := 0.5 + 0.5 * sin(TAU * 22.0 * t)
					s[i] = 0.35 * trem * sin(TAU * 760.0 * t)
			return _pcm(s)
		"power_down":
			var s := _silence(0.9)
			var phase := 0.0
			for i in s.size():
				var t := float(i) / RATE
				var f := lerpf(180.0, 34.0, t / 0.9)
				phase += TAU * f / RATE
				s[i] = 0.4 * (1.0 - t / 0.9) * sin(phase)
			return _pcm(s)
		"power_up":
			var s := _silence(0.7)
			var phase := 0.0
			for i in s.size():
				var t := float(i) / RATE
				var f := lerpf(40.0, 160.0, t / 0.7)
				phase += TAU * f / RATE
				s[i] = 0.35 * minf(t * 6.0, 1.0) * sin(phase)
			return _pcm(s)
		"print":
			# dot-matrix chatter: rapid square-wave bursts with paper-feed gaps
			var s := PackedFloat32Array()
			for k in 10:
				s.append_array(_tone(150.0 + 30.0 * (k % 3), 0.06, 0.35, 6.0, 1))
				s.append_array(_silence_f(0.03 if k % 4 != 3 else 0.1))
			return _pcm(s)
		"whir":
			# tape transport: motor ramp under soft hiss
			var s := _silence(0.9)
			var phase := 0.0
			for i in s.size():
				var t := float(i) / RATE
				var f := lerpf(70.0, 210.0, minf(t / 0.5, 1.0))
				phase += TAU * f / RATE
				s[i] = 0.22 * sin(phase) + 0.06 * randf_range(-1.0, 1.0)
			return _pcm(s)
		"degauss":
			# the coil: a heavy mains hum that swells and dies
			var s := _silence(1.4)
			for i in s.size():
				var t := float(i) / RATE
				var env := minf(t * 4.0, 1.0) * (1.0 - t / 1.4)
				s[i] = env * (0.5 * sin(TAU * 50.0 * t) + 0.2 * sin(TAU * 100.0 * t))
			return _pcm(s)
	return _pcm(_tone(440.0, 0.1, 0.3, 20.0))


func _silence(dur: float) -> PackedFloat32Array:
	var s := PackedFloat32Array()
	s.resize(int(dur * RATE))
	return s


func _silence_f(dur: float) -> PackedFloat32Array:
	return _silence(dur)


func _tone(freq: float, dur: float, vol: float, decay: float, kind := 0) -> PackedFloat32Array:
	var s := _silence(dur)
	for i in s.size():
		var t := float(i) / RATE
		var v := sin(TAU * freq * t)
		if kind == 1:
			v = signf(v)
		s[i] = vol * v * exp(-decay * t)
	return s


func _add_tone(s: PackedFloat32Array, freq: float, vol: float) -> void:
	for i in s.size():
		s[i] += vol * sin(TAU * freq * float(i) / RATE)


func _noise(dur: float, vol: float, decay: float) -> PackedFloat32Array:
	var s := _silence(dur)
	for i in s.size():
		var t := float(i) / RATE
		s[i] = vol * randf_range(-1.0, 1.0) * exp(-decay * t)
	return s


func _add_noise(s: PackedFloat32Array, vol: float) -> void:
	for i in s.size():
		s[i] += vol * randf_range(-1.0, 1.0)


func _pcm(samples: PackedFloat32Array) -> AudioStreamWAV:
	var bytes := PackedByteArray()
	bytes.resize(samples.size() * 2)
	for i in samples.size():
		bytes.encode_s16(i * 2, int(clampf(samples[i], -1.0, 1.0) * 32000.0))
	var wav := AudioStreamWAV.new()
	wav.format = AudioStreamWAV.FORMAT_16_BITS
	wav.mix_rate = RATE
	wav.stereo = false
	wav.data = bytes
	return wav
