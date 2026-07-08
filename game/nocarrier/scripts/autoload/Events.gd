extends Node
## Event director. Game calls advance(m) per simulated minute-chunk; rolls a
## weighted horror table gated by Game.anomaly. Real-time behaviour (the phone
## ring loop) runs in _process. World-side effects are delegated to Main,
## registered via register_main().

var main: Node = null
var phone_ringing := false
var final_started := false

var _cooldown := 10.0        # in-game minutes until the next roll may fire
var _entity_day := 0
var _ring_left := 0.0        # real seconds
var _ring_gap := 0.0


func register_main(m: Node) -> void:
	main = m


func reset() -> void:
	phone_ringing = false
	final_started = false
	_cooldown = 10.0
	_entity_day = 0
	_ring_left = 0.0


func advance(m: float) -> void:
	if Game.over or main == null:
		return
	if not final_started and Game.anomaly >= 100.0:
		final_started = true
		phone_ringing = false
		main.begin_final()
		return
	if final_started or Game.asleep:
		return
	_cooldown -= m
	if _cooldown > 0.0:
		return
	var chance := m * (0.006 + Game.anomaly * 0.00045 + (0.006 if Game.is_night() else 0.0))
	if randf() >= chance:
		return
	_cooldown = 8.0 + randf() * 10.0
	_fire()


func _fire() -> void:
	var a := Game.anomaly
	var pool: Array = ["flicker", "flicker", "thud"]
	if a >= 10.0:
		pool += ["crt", "phone"]
	if a >= 20.0:
		pool.append("knock")
	if a >= 25.0:
		pool.append("silhouette")
	if a >= 30.0:
		pool.append("breaker")
	if a >= 35.0:
		pool.append("lights_room")
	if a >= 50.0:
		pool.append("blackout")
	if a >= 60.0 and _entity_day != Game.day:
		pool += ["entity", "entity"]
	if a >= 70.0:
		pool.append("nocarrier")
	match pool[randi() % pool.size()]:
		"flicker":
			main.flicker_room(main.random_room())
		"thud":
			Sfx.play("thud")
			Game.toast(Loc.t("t.ev_thud"))
		"crt":
			main.crt_flash()
			Game.anomaly = minf(Game.anomaly + 0.5, 100.0)
		"phone":
			if not phone_ringing:
				phone_ringing = true
				_ring_left = 45.0
				_ring_gap = 0.0
		"knock":
			main.knock()
			Game.anomaly = minf(Game.anomaly + 1.0, 100.0)
		"silhouette":
			main.spawn_silhouette()
			Game.anomaly = minf(Game.anomaly + 1.0, 100.0)
		"breaker":
			Game.trip_breaker()
			Game.toast(Loc.t("t.ev_breaker"))
		"lights_room":
			main.lights_out(main.random_room(), 30.0)
		"blackout":
			Game.trip_breaker()
			Sfx.play("thud")
			Game.toast(Loc.t("t.ev_dark"))
			Game.anomaly = minf(Game.anomaly + 1.5, 100.0)
		"entity":
			_entity_day = Game.day
			main.entity_walk()
		"nocarrier":
			main.nocarrier_takeover()
			Game.anomaly = minf(Game.anomaly + 2.0, 100.0)


func _process(delta: float) -> void:
	if not phone_ringing or Game.over:
		return
	_ring_left -= delta
	_ring_gap -= delta
	if _ring_gap <= 0.0:
		_ring_gap = 2.4
		Sfx.play("ring")
	if _ring_left <= 0.0:
		phone_ringing = false
		Game.anomaly = minf(Game.anomaly + 4.0, 100.0)
		Game.toast(Loc.t("t.ev_ringstop"))


func answer_phone() -> void:
	if not phone_ringing:
		Game.toast(Loc.t("t.ev_deadline"))
		return
	phone_ringing = false
	Sfx.play("static")
	var idx := Loc.rand_i("phone.lines")
	Game.toast(Loc.t("t.ev_pickup", [Loc.t("phone.lines", [idx])]))
	Game.add_mail("from.line", "mail.call.subj", [Game.fmt_clock()], "phone.lines", [idx])
	Game.anomaly = minf(Game.anomaly + 2.0, 100.0)
