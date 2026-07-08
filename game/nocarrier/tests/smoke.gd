extends SceneTree
## Headless smoke test: drives the whole simulation API end-to-end without
## the 3D scene. Run from the project directory:
##
##   godot4 --headless --path . -s tests/smoke.gd
##
## Exits 0 on success, 1 if any check failed.

var _fail := 0


func _check(cond: bool, name: String) -> void:
	if cond:
		print("ok - ", name)
	else:
		_fail += 1
		printerr("FAIL - ", name)


func _initialize() -> void:
	var game: Node = root.get_node("Game")
	var net: Node = root.get_node("Net")
	var events: Node = root.get_node("Events")

	game.reset_all()
	_check(net.carriers.size() >= 4, "carriers spawned on day 1")
	_check(game.mails.size() == 1, "intro mail delivered")

	# resolve a carrier by pinging its own cell, then tap it
	var c: Dictionary = net.carriers[0]
	var cell := Vector2i(Vector2(c["pos"]).floor())
	var res: Dictionary = net.ping(cell)
	_check(int(res["strength"]) > 0, "ping returns strength")
	_check(bool(c["found"]), "direct ping resolves the carrier")
	_check(net.found_at(cell) == c, "found_at sees it")
	_check(net.lock(c) == "", "tap locks")
	_check(net.active_tap_count() == 1, "one active tap")

	var files_before: int = net.files.size()
	game.advance_minutes(float(c["size"]) / net.download_rate() + 5.0)
	_check(net.files.size() == files_before + 1, "download completes")
	_check(net.active_tap_count() == 0, "tap slot freed")

	var f: Dictionary = net.files[net.files.size() - 1]
	var puz: Dictionary = net.make_puzzle(f)
	_check(puz["options"].size() == 4 and int(puz["correct"]) >= 0, "decode puzzle generated")
	var pat: String = puz["options"][int(puz["correct"])]
	var col: int = String(puz["rows"][0]).find(pat)
	var consistent := col >= 0
	for row in puz["rows"]:
		if String(row).substr(col, 3) != pat:
			consistent = false
	_check(consistent, "puzzle pattern repeats at one column in every row")

	net.finish_decode(f, true)
	_check(bool(f["decoded"]) and str(f["title"]) != "", "decode names the capture")
	if net.sellable(f):
		var v: int = net.value(f)
		var money0: int = game.money
		net.sell(f)
		_check(game.money == money0 + v, "sale pays the listed value")

	# shop order -> hatch delivery -> pickup
	var money1: int = game.money
	game.buy("noodles")
	_check(game.money == money1 - 15, "order charges credits")
	game.advance_minutes(125.0)
	_check(int(game.hatch.get("noodles", 0)) >= 1, "delivery reaches the hatch in ~2h")
	var noodles0: int = int(game.inventory["noodles"])
	game.collect_hatch()
	_check(int(game.inventory["noodles"]) == noodles0 + 1, "hatch pickup fills inventory")

	# power chain
	game.trip_breaker()
	_check(not game.power_on(), "tripped breaker kills power")
	game.reset_breaker()
	_check(game.power_on(), "breaker reset restores power")
	var fuel0: float = game.fuel
	game.advance_minutes(120.0)
	_check(game.fuel < fuel0, "generator burns fuel over time")

	# sleep saves and restores
	var energy0: float = game.energy
	game.sleep_hours(8.0)
	_check(game.energy > energy0, "sleep restores energy")
	_check(FileAccess.file_exists("user://nocarrier_save.json"), "sleep writes the save")
	var day0: int = game.day
	var money2: int = game.money
	game._load()
	_check(game.day == day0 and game.money == money2, "save round-trips")

	# event director must be safe without a registered Main
	for i in 300:
		events.advance(1.0)
	_check(true, "events tick without a world")

	# upgrades
	var disk0: float = net.disk_total
	net.apply_upgrade("disk")
	_check(net.disk_total == disk0 + 256.0, "disk upgrade applies")
	net.apply_upgrade("tapslot")
	_check(net.tap_slots == 3 and net.taps.size() == 3, "tap slot upgrade applies")

	# leave a clean slate for a real playthrough
	game.reset_all()

	print("---")
	print("failures: ", _fail)
	quit(1 if _fail > 0 else 0)
