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


func _hexb(h: String) -> PackedByteArray:
	if h.begins_with("0x"):
		h = h.substr(2)
	var b := PackedByteArray()
	for i in range(0, h.length(), 2):
		b.append(("0x" + h.substr(i, 2)).hex_to_int())
	return b


func _initialize() -> void:
	var game: Node = root.get_node("Game")
	var net: Node = root.get_node("Net")
	var media: Node = root.get_node("Media")
	var events: Node = root.get_node("Events")
	var loc: Node = root.get_node("Loc")
	var ledger: Node = root.get_node("Ledger")

	game.reset_all()
	game.paused = false
	_check(net.carriers.size() >= 4, "carriers spawned on day 1")
	_check(game.mails.size() == 1, "intro mail delivered")

	# localization
	loc.set_lang("en")
	var en := str(loc.t("t.noodles"))
	var subj_en := str(game.mail_subj(game.mails[0]))
	loc.set_lang("ru")
	var ru := str(loc.t("t.noodles"))
	var subj_ru := str(game.mail_subj(game.mails[0]))
	_check(en != ru and ru != "t.noodles", "strings translate en<->ru")
	_check(subj_en != subj_ru, "saved mail re-renders in the active language")
	_check(loc.t("phone.lines", [2]) != loc.t("phone.lines", [3]), "array entries index correctly")
	loc.set_lang("en")

	# inject a single, isolated carrier so pinging its cell resolves it
	# unambiguously (with several random carriers two can share a neighbourhood)
	net.carriers = [{
		"id": 999, "cls": "data", "size": 40.0, "sig": "######",
		"pos": Vector2(3.5, 3.5), "found": false, "locked": false,
	}]
	net.scan_marks.clear()
	var c: Dictionary = net.carriers[0]
	var cell := Vector2i(3, 3)
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
	_check(media.kind_of(int(f["media"])) == "hdd", "the capture lands on a working disk")
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
	_check(bool(f["decoded"]) and str(net.title_of(f)) != "", "decode names the capture")
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

	# power chain: breaker
	game.trip_breaker()
	_check(not game.power_on(), "tripped breaker kills power")
	game.reset_breaker()
	_check(game.power_on(), "breaker reset restores power")
	var fuel0: float = game.fuel
	game.advance_minutes(120.0)
	_check(game.fuel < fuel0, "generator burns fuel over time")

	# power chain: battery keeps the node alive when the generator is off
	game.generator_on = false
	game.battery = 20.0
	_check(game.power_on() and game.on_battery(), "UPS carries the node without the generator")
	var bat0: float = game.battery
	game.advance_minutes(60.0)
	_check(game.battery < bat0, "battery drains under load")
	game.energy = 80.0
	var bat1: float = game.battery
	game.crank_dynamo()
	_check(game.battery > bat1, "cranking the dynamo charges the battery")
	game.generator_on = true

	# scrap: pick up, burn for fuel, sell for credits
	game.pick_scrap()
	game.pick_scrap()
	_check(int(game.inventory["scrap"]) == 2, "scrap collects")
	var fuel1: float = game.fuel
	game.burn_scrap()
	_check(game.fuel > fuel1 and int(game.inventory["scrap"]) == 1, "scrap burns into fuel")
	var money2: int = game.money
	game.sell_scrap()
	_check(game.money == money2 + game.SCRAP_PRICE, "scrap sells at the market")

	# bailout: total blackout + empty pockets summons a fuel can on credit
	game.generator_on = true
	game.fuel = 0.0
	game.battery = 0.0
	game.inventory["fuel"] = 0
	game.money = 10
	game.hatch = {}
	var debt0: int = game.debt
	game.advance_minutes(3.0)
	_check(int(game.hatch.get("fuel", 0)) >= 1, "OPERATOR bailout ships a fuel can")
	_check(game.debt == debt0 + game.BAILOUT_DEBT, "bailout is booked as debt")
	game.collect_hatch()
	_check(game.refuel_generator(), "bailout can refuels the generator")
	_check(game.power_on(), "the node comes back to life")

	# sleep saves and restores
	game.energy = 30.0
	var energy0: float = game.energy
	game.sleep_hours(8.0)
	_check(game.energy > energy0, "sleep restores energy")
	_check(FileAccess.file_exists("user://nocarrier_save.json"), "sleep writes the save")
	var day0: int = game.day
	var money3: int = game.money
	game._load()
	_check(game.day == day0 and game.money == money3, "save round-trips")

	# event director must be safe without a registered Main
	for i in 300:
		events.advance(1.0)
	_check(true, "events tick without a world")

	# upgrades
	var disk0: float = media.hdd_total()
	net.apply_upgrade("disk")
	_check(media.hdd_total() == disk0 + 256.0, "disk upgrade mounts a drive")
	net.apply_upgrade("tapslot")
	_check(net.tap_slots == 3 and net.taps.size() == 3, "tap slot upgrade applies")

	# on-chain calldata encoding (no network needed)
	var uri := "nocarrier://day1/cap_001/test"
	var calldata: String = "0x" + ledger.SEL_MINT + ledger._encode_string_arg(uri)
	_check(calldata.begins_with("0xd85d3d27"), "mint(string) selector is correct")
	var payload := calldata.substr(10)
	_check(payload.length() % 64 == 0, "calldata is word-aligned")
	var strlen := ("0x" + payload.substr(64, 64)).hex_to_int()
	_check(strlen == uri.to_utf8_buffer().size(), "encoded string length matches")
	_check(net.sealable({"decoded": true, "cls": "anom"}), "decoded anomalies are sealable")
	_check(not net.sealable({"decoded": false, "cls": "anom"}), "raw captures are not sealable")

	# native signer (Godot signs without MetaMask). In the -s harness autoload
	# _ready may not have fired yet, so ensure the key the same way the game does.
	var signer: Node = root.get_node("Signer")
	if not signer.has_key():
		signer.regenerate()
	_check(signer.has_key(), "native wallet has a key")
	_check(signer.address().begins_with("0x") and signer.address().length() == 42, "native address looks valid")
	# reproduce the EIP-155 spec vector through the autoload's static signer
	var priv := _hexb("4646464646464646464646464646464646464646464646464646464646464646")
	var raw: String = signer.sign_transaction(priv, 9, 20000000000, 21000,
		"3535353535353535353535353535353535353535", 1000000000000000000, "", 1)
	_check(raw == "0xf86c098504a817c800825208943535353535353535353535353535353535353535880de0b6b3a76400008025a028ef61340bd939bc2195fe537567866003e1a15d3c71ff63e1590620aa636276a067cbe9d8997f761aecb703304b3800ccf555c9f3dc64214b297fb1966a3b6d83",
		"Signer.sign_transaction reproduces the EIP-155 vector")
	# a real Cyberia mint tx builds and stays well-formed
	var mint_raw: String = signer.build_raw(0, 1000000000, 300000, ledger.NFT_ADDRESS, 0, calldata, 49406)
	_check(mint_raw.begins_with("0x") and mint_raw.length() > 200, "mint tx signs and RLP-encodes")
	# routing: headless has no MetaMask, so sealing uses the native signer
	_check(not ledger.using_metamask(), "headless routes sealing to the native signer")
	_check(ledger.active_address() == signer.address(), "active address is the native wallet")

	# import a user-supplied private key (priv = 1 -> known address)
	_check(signer.import_hex("0000000000000000000000000000000000000000000000000000000000000001"),
		"import_hex accepts a valid key")
	_check(signer.address() == "0x7e5f4552091a69125d5dfcb7b8c2659029395bdf", "imported key derives the right address")
	_check(not signer.import_hex("0x1234"), "import_hex rejects a short key")
	_check(not signer.import_hex("zz" + "00".repeat(31)), "import_hex rejects non-hex")
	_check(not signer.import_hex("00".repeat(32)), "import_hex rejects zero key")
	# key survives a reload from disk
	signer._load()
	_check(signer.address() == "0x7e5f4552091a69125d5dfcb7b8c2659029395bdf", "imported key persists to disk")
	# export round-trips (never printing the key itself)
	var exported: String = signer.export_hex()
	_check(exported.length() == 66 and exported.begins_with("0x"), "export_hex returns a 0x key")
	signer.regenerate()
	var new_addr: String = signer.address()
	_check(new_addr != "0x7e5f4552091a69125d5dfcb7b8c2659029395bdf", "regenerate makes a different wallet")
	_check(signer.import_hex(exported), "exported key re-imports")
	_check(signer.address() == "0x7e5f4552091a69125d5dfcb7b8c2659029395bdf", "export→import round-trips the address")

	# settings: clamping, toggles, and shared-config no-clobber
	var settings: Node = root.get_node("Settings")
	settings.master = 0
	settings.adjust("master", -1)
	_check(settings.master == 0, "volume clamps at floor")
	settings.master = 100
	settings.adjust("master", 1)
	_check(settings.master == 100, "volume clamps at ceiling")
	settings.brightness = 100
	settings.adjust("brightness", 1)
	_check(settings.brightness == 110, "brightness steps by 10")
	var fs0: bool = settings.fullscreen
	settings.flip("fullscreen")
	_check(settings.fullscreen != fs0, "toggle flips a display option")
	settings.flip("fullscreen")
	# language and settings must coexist in the shared config file
	loc.set_lang("ru")
	settings.adjust("sfx", -1)
	var cf := FileAccess.open("user://nocarrier_cfg.json", FileAccess.READ)
	var cfg: Variant = JSON.parse_string(cf.get_as_text())
	_check(typeof(cfg) == TYPE_DICTIONARY and cfg.has("lang") and cfg.has("sfx"),
		"language and settings share the config without clobbering")
	loc.set_lang("en")

	# --- DEAD MEDIA: the physical storage layer -------------------------------
	game.reset_all()
	game.paused = false
	_check(media.hdds().size() == 1 and media.hdd_total() == 512.0, "fresh node has one 512 MB disk")

	# a decoded anomaly on the working disk leaks line noise
	net.files.append({"id": net.next_id, "name": "cap_t01", "ti": 0, "cls": "anom",
		"size": 50.0, "decoded": true, "quality": 1.0, "corrupt": false,
		"read": false, "media": media.pick_hdd()})
	net.next_id += 1
	var mf: Dictionary = net.files[net.files.size() - 1]
	_check(net.hdd_files().size() == 1, "capture sits on the working disk")
	var noise0: float = game.anomaly
	media.advance(60.0)
	_check(game.anomaly > noise0, "anomaly on hdd leaks noise")

	# hdd -> tape: sequential, slow, dormant
	media.add_tape()
	_check(str(media.start_tape_in(mf)) == "", "streamer accepts hdd->tape")
	game.advance_minutes(50.0 / media.TAPE_RATE + 3.0)
	_check(media.kind_of(int(mf["media"])) == "tape", "capture moved to tape")
	_check(net.hdd_files().is_empty(), "working disk is free again")
	net.corrupt_random_file()
	_check(not bool(mf["corrupt"]), "dormant tape captures resist corruption")
	var leak0: float = game.anomaly
	media.advance(60.0)
	_check(game.anomaly <= leak0, "no noise leak from tape")

	# tape -> hdd
	_check(str(media.start_tape_out(mf)) == "", "streamer accepts tape->hdd")
	game.advance_minutes(50.0 / media.TAPE_RATE + 3.0)
	_check(media.kind_of(int(mf["media"])) == "hdd", "capture restored to the disk")

	# burn to CD-R: consumes the blank, collector pays a premium, sale is quiet
	game.inventory["cdr"] = 1
	_check(str(media.start_burn(mf)) == "", "burner accepts the job")
	_check(int(game.inventory["cdr"]) == 0, "burn consumes the blank")
	game.advance_minutes(50.0 / media.BURN_RATE + 3.0)
	_check(media.kind_of(int(mf["media"])) == "cd", "capture sealed on a disc")
	_check(media.cds().size() == 1, "the disc exists")
	var cd: Dictionary = media.cds()[0]
	_check(media.cd_value(cd) > net.value(mf), "the collector pays a premium")
	var money_cd: int = game.money
	var noise_cd: float = game.anomaly
	var cdv: int = media.sell_cd(cd)
	_check(game.money == money_cd + cdv, "the disc sells")
	_check(game.anomaly <= noise_cd + 3.01, "a hatch sale is quieter than an upload")
	_check(media.cds().is_empty() and net.files.is_empty(), "the disc and its capture are gone")

	# a burn dies with the power, the capture survives on the disk
	net.files.append({"id": net.next_id, "name": "cap_t02", "ti": 0, "cls": "data",
		"size": 40.0, "decoded": true, "quality": 1.0, "corrupt": false,
		"read": false, "media": media.pick_hdd()})
	net.next_id += 1
	var mf2: Dictionary = net.files[net.files.size() - 1]
	game.inventory["cdr"] = 1
	_check(str(media.start_burn(mf2)) == "", "second burn starts")
	game.trip_breaker()
	media.advance(5.0)
	_check(media.job_of("burner").is_empty(), "power loss ruins the burn")
	_check(int(game.inventory["cdr"]) == 0, "the blank is wasted")
	_check(media.kind_of(int(mf2["media"])) == "hdd", "the capture survives on the disk")
	game.reset_breaker()

	# hardcopy: a quiet lossy copy on paper
	game.inventory["paper"] = 2
	var papers0: int = media.papers().size()
	_check(str(media.print_file(mf2)) == "", "printer prints a decoded capture")
	_check(int(game.inventory["paper"]) == 1, "printing consumes a sheet")
	_check(media.papers().size() == papers0 + 1, "hardcopy exists")
	var hp: Dictionary = media.papers()[media.papers().size() - 1]
	_check(int(hp["val"]) > 0, "hardcopy has a market value")
	var money_p: int = game.money
	var noise_p: float = game.anomaly
	var pv: int = media.sell_paper(hp)
	_check(game.money == money_p + pv, "hardcopy sells")
	_check(absf(game.anomaly - noise_p) < 0.001, "a paper sale adds no noise")

	# degauss: bulk-erase everything on the medium, anomalies included
	net.files.append({"id": net.next_id, "name": "cap_t03", "ti": 0, "cls": "anom",
		"size": 30.0, "decoded": true, "quality": 1.0, "corrupt": false,
		"read": false, "media": media.pick_hdd()})
	net.next_id += 1
	game.anomaly = 20.0
	media.degauss(media.pick_hdd())
	_check(net.hdd_files().is_empty(), "degauss wipes the medium")
	_check(game.anomaly < 20.0, "wiping an anomaly settles the noise")
	game.reset_breaker()  # the coil sometimes trips the breakers

	# heat kills disks; the OPERATOR ships a refurb drive on credit
	var hd: Dictionary = media.hdds()[0]
	hd["health"] = 1.0
	media.heat_stress(10.0)
	_check(media.hdds().is_empty(), "a cooked disk dies")
	game.money = 10
	game.hatch = {}
	var debt_d: int = game.debt
	game.advance_minutes(3.0)
	_check(int(game.hatch.get("disk", 0)) >= 1, "OPERATOR ships a refurb drive")
	_check(game.debt == debt_d + game.DISK_BAILOUT_DEBT, "the drive is booked as debt")
	game.collect_hatch()
	_check(media.hdds().size() == 1, "the refurb drive mounts")

	# found tapes carry unknown captures
	var tapes0: int = media.tapes().size()
	var files0: int = net.files.size()
	media.add_found_tape()
	_check(media.tapes().size() == tapes0 + 1, "found tape lands on the shelf")
	_check(net.files.size() > files0, "the found tape is not blank")

	# media survive the save round-trip
	game.save()
	var tapes1: int = media.tapes().size()
	var files1: int = net.files.size()
	media.reset()
	game._load()
	_check(media.tapes().size() == tapes1, "media round-trip through the save")
	_check(net.files.size() == files1, "captures keep their homes")

	# legacy v2 saves migrate onto physical media
	media.set_state({}, 768.0)
	_check(media.hdd_total() == 768.0, "legacy disk_total becomes a real drive")
	net.set_state({"carriers": [], "taps": [null, null], "files": [
		{"id": 9001, "name": "cap_901", "ti": 0, "cls": "data", "size": 10.0,
			"decoded": true, "quality": 1.0, "corrupt": false, "read": false}]})
	_check(media.kind_of(int(net.files[0]["media"])) == "hdd", "stray captures adopt the first disk")

	# leave a clean slate for a real playthrough
	game.reset_all()

	print("---")
	print("failures: ", _fail)
	quit(1 if _fail > 0 else 0)
