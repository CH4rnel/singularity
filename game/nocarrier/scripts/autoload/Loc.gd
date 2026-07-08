extends Node
## Localization for NO CARRIER (en / ru). Every user-facing string lives here
## as a key; t(key, args) formats templates, and Array entries are indexed by
## args[0] so saved mail/lore render in whichever language is active.
## The chosen language persists in user://nocarrier_cfg.json (survives wipes).

signal lang_changed

const CFG_PATH := "user://nocarrier_cfg.json"

var lang := "en"

const S := {
	# --- apps / terminal chrome -------------------------------------------------
	"app.scan": {"en": "SCAN", "ru": "СКАН"},
	"app.taps": {"en": "TAPS", "ru": "ТАПЫ"},
	"app.files": {"en": "FILES", "ru": "ФАЙЛЫ"},
	"app.market": {"en": "MARKET", "ru": "РЫНОК"},
	"app.shop": {"en": "SHOP", "ru": "СНАБЖ"},
	"app.mail": {"en": "MAIL", "ru": "ПОЧТА"},
	"app.sys": {"en": "SYS", "ru": "СИСТ"},
	"app.uplink": {"en": "UPLINK", "ru": "АПЛИНК"},
	"term.title": {"en": "TERMINAL", "ru": "ТЕРМИНАЛ"},
	"term.crd": {"en": "%d crd", "ru": "%d кр"},

	# --- scan --------------------------------------------------------------------
	"scan.legend": {"en": "arrows: move   enter: ping (4 min)   L: lock tap on ◆",
		"ru": "стрелки: курсор   enter: пинг (4 мин)   L: тап на ◆"},
	"scan.ping": {"en": "ping (%d,%d): strength %d", "ru": "пинг (%d,%d): уровень %d"},
	"scan.resolved": {"en": "  — CARRIER RESOLVED #%d", "ru": "  — НЕСУЩАЯ ОПОЗНАНА #%d"},
	"scan.bearing": {"en": "  bearing %s", "ru": "  пеленг %s"},
	"scan.found_hdr": {"en": "resolved carriers:", "ru": "опознанные несущие:"},
	"scan.found_row": {"en": " ◆ #%d  sig %s  ~%d MB  @(%d,%d)", "ru": " ◆ #%d  сиг %s  ~%d МБ  @(%d,%d)"},
	"scan.night": {"en": "▲ 00:00–06:00: the noise floor is listening back",
		"ru": "▲ 00:00–06:00: шумовой фон слушает в ответ"},
	"scan.nolock": {"en": "no resolved carrier under the cursor", "ru": "под курсором нет опознанной несущей"},
	"scan.locked": {"en": "tap locked on carrier #%d", "ru": "тап закреплён за несущей #%d"},

	# --- taps ---------------------------------------------------------------------
	"taps.hdr": {"en": "tap slots (%d) — line rate %.1f MB/min", "ru": "слоты тапов (%d) — канал %.1f МБ/мин"},
	"taps.nopower": {"en": "— NO POWER, stalled", "ru": "— НЕТ ПИТАНИЯ, простой"},
	"taps.idle": {"en": "slot %d: idle", "ru": "слот %d: пусто"},
	"taps.row": {"en": "slot %d: #%d %s [%s] %d/%d MB  eta %dmin", "ru": "слот %d: #%d %s [%s] %d/%d МБ  ещё %dмин"},
	"taps.degrading": {"en": "DEGRADING", "ru": "ДЕГРАДАЦИЯ"},
	"taps.disk": {"en": "disk %d / %d MB   K: release selected tap", "ru": "диск %d / %d МБ   K: снять выбранный тап"},
	"taps.heat": {"en": "heat %d°  coolant %d%%  — heavy taps cook the racks",
		"ru": "нагрев %d°  хладагент %d%%  — тяжёлые тапы жарят стойки"},
	"taps.released": {"en": "tap %d released", "ru": "тап %d снят"},

	# --- files / decode -------------------------------------------------------------
	"files.hdr": {"en": "captures on disk (%d / %d MB)", "ru": "захваты на диске (%d / %d МБ)"},
	"files.empty": {"en": "  nothing. the disk hums to itself.", "ru": "  пусто. диск гудит сам себе."},
	"files.raw": {"en": "raw", "ru": "сырой"},
	"files.corrupt": {"en": "CORRUPT", "ru": "ПОВРЕЖДЁН"},
	"files.row": {"en": "%3d MB", "ru": "%3d МБ"},
	"files.legend": {"en": "D: decode (10 min)   P: purge   R: read (decoded echo)",
		"ru": "D: декод (10 мин)   P: стереть   R: слушать (эхо после декода)"},
	"files.already": {"en": "already decoded", "ru": "уже декодировано"},
	"files.purged": {"en": "%s purged", "ru": "%s стёрт"},
	"files.listen": {"en": "you listen: %s", "ru": "ты слушаешь: %s"},
	"files.noread": {"en": "nothing in there wants to be read", "ru": "там нет ничего, что хочет быть прочитанным"},
	"decode.hdr": {"en": "decoding %s — find the 3-glyph pattern that repeats at the\nsame position in EVERY row:",
		"ru": "декодирование %s — найди тройку глифов, повторяющуюся на одной\nи той же позиции в КАЖДОЙ строке:"},
	"decode.legend": {"en": "1-4: commit   esc: abort (capture stays raw)",
		"ru": "1-4: выбрать   esc: отмена (захват останется сырым)"},
	"decode.clean": {"en": "CLEAN DECODE: %s — %s", "ru": "ЧИСТЫЙ ДЕКОД: %s — %s"},
	"decode.partial": {"en": "framing error — partial decode of %s", "ru": "ошибка кадрирования — частичный декод %s"},
	"decode.abort": {"en": "decode aborted", "ru": "декод прерван"},

	# --- market ----------------------------------------------------------------------
	"market.hdr": {"en": "uplink to the OPERATOR — quota %d / %d crd, settles day %d",
		"ru": "канал к ОПЕРАТОРУ — квота %d / %d кр, расчёт в день %d"},
	"market.empty": {"en": "  nothing sellable. decode some captures first.",
		"ru": "  продавать нечего. сначала декодируй захваты."},
	"market.hot": {"en": "hot data — pays well; something will notice",
		"ru": "горячие данные — щедро платят; кое-что заметит"},
	"market.legend": {"en": "enter: sell   A: archive NONSTANDARD to cold tape (safer, 60 crd)",
		"ru": "enter: продать   A: НЕСТАНДАРТ на холодную ленту (безопаснее, 60 кр)"},
	"market.sold": {"en": "delivered %s for %d crd", "ru": "%s ушёл за %d кр"},
	"market.archneed": {"en": "archive needs a cold tape and a NONSTANDARD capture",
		"ru": "для архива нужна холодная лента и НЕСТАНДАРТНЫЙ захват"},
	"market.archdone": {"en": "%s archived to tape (+60 crd)", "ru": "%s записан на ленту (+60 кр)"},
	"market.scrap": {"en": "scrap bundle x%d — 4 crd each", "ru": "лом, %d шт — по 4 кр"},
	"market.scrap_sold": {"en": "scrap sold: +%d crd", "ru": "лом продан: +%d кр"},

	# --- shop -------------------------------------------------------------------------
	"shop.hdr": {"en": "procurement — deliveries reach the hatch in ~2h",
		"ru": "снабжение — доставка в люк ~2 часа"},
	"shop.transit": {"en": "in transit:", "ru": "в пути:"},
	"shop.eta": {"en": "  %s — %d min", "ru": "  %s — %d мин"},
	"shop.legend": {"en": "enter: order", "ru": "enter: заказать"},
	"shop.have": {"en": "  (have %d)", "ru": "  (есть %d)"},
	"shop.lv": {"en": "  (lv%d)", "ru": "  (ур%d)"},
	"shop.slots": {"en": "  (%d slots)", "ru": "  (слотов: %d)"},
	"shop.mb": {"en": "  (%d MB)", "ru": "  (%d МБ)"},
	"shop.noodles.n": {"en": "cup noodles", "ru": "лапша в стакане"},
	"shop.noodles.d": {"en": "+45 food, +1 trash", "ru": "+45 еды, +1 мусор"},
	"shop.coffee.n": {"en": "instant coffee", "ru": "растворимый кофе"},
	"shop.coffee.d": {"en": "+18 energy", "ru": "+18 энергии"},
	"shop.fuel.n": {"en": "fuel can", "ru": "канистра топлива"},
	"shop.fuel.d": {"en": "+25% generator tank", "ru": "+25% бака генератора"},
	"shop.coolant.n": {"en": "coolant can", "ru": "канистра хладагента"},
	"shop.coolant.d": {"en": "refills the server loop", "ru": "заправляет контур серверов"},
	"shop.tape.n": {"en": "cold tape", "ru": "холодная лента"},
	"shop.tape.d": {"en": "archive 1 nonstandard capture safely", "ru": "безопасный архив 1 нестандартного захвата"},
	"shop.disk.n": {"en": "disk shelf +256 MB", "ru": "дисковая полка +256 МБ"},
	"shop.disk.d": {"en": "more capture space", "ru": "больше места под захваты"},
	"shop.modem.n": {"en": "line amplifier", "ru": "усилитель линии"},
	"shop.modem.d": {"en": "taps download faster (max lv3)", "ru": "тапы качают быстрее (макс ур3)"},
	"shop.filter.n": {"en": "lattice filter", "ru": "решётчатый фильтр"},
	"shop.filter.d": {"en": "cleaner pings (max lv3)", "ru": "чище пинги (макс ур3)"},
	"shop.tapslot.n": {"en": "tap card", "ru": "плата тапа"},
	"shop.tapslot.d": {"en": "+1 concurrent tap (max 4)", "ru": "+1 одновременный тап (макс 4)"},

	# --- mail chrome ---------------------------------------------------------------------
	"mailbox.hdr": {"en": "mailbox", "ru": "почтовый ящик"},
	"mailbox.empty": {"en": "  empty. even the spam avoids this address.",
		"ru": "  пусто. даже спам обходит этот адрес стороной."},
	"mailbox.head": {"en": "from: %s\nsubj: %s   (day %d)", "ru": "от: %s\nтема: %s   (день %d)"},
	"mailbox.back": {"en": "esc: back", "ru": "esc: назад"},
	"mailbox.read": {"en": "enter: read", "ru": "enter: читать"},

	# --- sys --------------------------------------------------------------------------------
	"sys.node": {"en": "node        NODE-07 basement relay, ex-exchange 4F",
		"ru": "узел        NODE-07, подвальный ретранслятор, бывш. АТС 4Ф"},
	"sys.uptime": {"en": "uptime      day %d, shift ongoing", "ru": "аптайм      день %d, смена продолжается"},
	"sys.power": {"en": "power       %s  (generator %s, breakers %s, fuel %d%%, battery %d%%)",
		"ru": "питание     %s  (генератор %s, автоматы %s, топливо %d%%, батарея %d%%)"},
	"sys.on": {"en": "ON", "ru": "ЕСТЬ"},
	"sys.down": {"en": "DOWN", "ru": "НЕТ"},
	"sys.gen_on": {"en": "on", "ru": "вкл"},
	"sys.gen_off": {"en": "off", "ru": "выкл"},
	"sys.ok": {"en": "ok", "ru": "норм"},
	"sys.tripped": {"en": "TRIPPED", "ru": "ВЫБИТЫ"},
	"sys.thermals": {"en": "thermals    racks %d°C, coolant %d%%", "ru": "термика     стойки %d°C, хладагент %d%%"},
	"sys.storage": {"en": "storage     %d / %d MB", "ru": "диск        %d / %d МБ"},
	"sys.line": {"en": "line        amp lv%d, filter lv%d, %d tap slots",
		"ru": "линия       усилитель ур%d, фильтр ур%d, слотов: %d"},
	"sys.noise": {"en": "noise       %d%% and %s", "ru": "шум         %d%% и %s"},
	"sys.rising": {"en": "rising", "ru": "растёт"},
	"sys.tolerable": {"en": "tolerable", "ru": "терпимо"},
	"sys.ledger": {"en": "ledger      %d crd, %d/%d quota, strikes %d/3, debt %d crd",
		"ru": "счёт        %d кр, квота %d/%d, страйки %d/3, долг %d кр"},
	"sys.legend": {"en": "S: save    L: язык → русский    W W: wipe node and restart contract",
		"ru": "S: сохранить    L: language → english    W W: стереть узел и начать контракт заново"},
	"sys.saved": {"en": "state written to disk", "ru": "состояние записано на диск"},
	"sys.wipe_arm": {"en": "press W again to WIPE the node and restart",
		"ru": "нажми W ещё раз, чтобы СТЕРЕТЬ узел и начать заново"},
	"sys.ver": {"en": "nc-term 0.8 (1997-09) — property of the OPERATOR. do not unplug.",
		"ru": "nc-term 0.8 (1997-09) — собственность ОПЕРАТОРА. не выключать."},

	# --- uplink (web3) --------------------------------------------------------------------------
	"up.block": {"en": "backbone: Cyberia, block #%d", "ru": "магистраль: Cyberia, блок #%d"},
	"up.noblock": {"en": "backbone: reaching for Cyberia…", "ru": "магистраль: тянемся к Cyberia…"},
	"up.nowallet": {"en": "wallet bridge is browser-only. run the web build to connect.",
		"ru": "мост кошелька работает только в браузере. запусти web-сборку, чтобы подключиться."},
	"up.disconnected": {"en": "no wallet connected.   C: connect (MetaMask / any EIP-1193)",
		"ru": "кошелёк не подключен.   C: подключить (MetaMask / любой EIP-1193)"},
	"up.addr": {"en": "operator key   %s", "ru": "ключ оператора %s"},
	"up.balance": {"en": "balance        %.4f CYBER", "ru": "баланс         %.4f CYBER"},
	"up.artifacts": {"en": "sealed artifacts on chain: %d", "ru": "запечатанных артефактов в цепи: %d"},
	"up.eligible": {"en": "captures eligible for sealing (decoded NONSTANDARD / echo):",
		"ru": "захваты, готовые к запечатке (декодированные НЕСТАНДАРТ / эхо):"},
	"up.none": {"en": "  nothing worth sealing. decode something the network regrets.",
		"ru": "  нечего запечатывать. декодируй то, о чём сеть жалеет."},
	"up.legend": {"en": "enter: seal selected capture to the chain (mints an NFT, costs gas)",
		"ru": "enter: запечатать выбранный захват в цепь (минт NFT, нужен газ)"},
	"up.pending": {"en": "sealing in progress — confirm in your wallet…",
		"ru": "запечатывание идёт — подтверди в кошельке…"},
	"up.connect_first": {"en": "connect the wallet first (C)", "ru": "сначала подключи кошелёк (C)"},
	"up.txsent": {"en": "seal submitted: %s", "ru": "запечатка отправлена: %s"},
	"up.txfail": {"en": "the chain refused: %s", "ru": "цепь отказала: %s"},
	"up.sealed": {"en": "capture sealed to the chain. the noise settles a little.",
		"ru": "захват запечатан в цепи. шум немного оседает."},
	"up.minted_hdr": {"en": "sealed this shift:", "ru": "запечатано за смену:"},
	"t.wallet_only": {"en": "the wallet lives in the browser build", "ru": "кошелёк живёт только в браузерной сборке"},
	"t.wallet_conn": {"en": "wallet connected: %s", "ru": "кошелёк подключен: %s"},

	# --- HUD -------------------------------------------------------------------------------------
	"ui.day": {"en": "day %d", "ru": "день %d"},
	"ui.credits": {"en": "credits %d", "ru": "кредиты %d"},
	"ui.mail": {"en": "mail %s", "ru": "почта %s"},
	"ui.mail_n": {"en": "%d unread", "ru": "%d новых"},
	"ui.noise": {"en": "line noise", "ru": "шум линии"},
	"ui.energy": {"en": "energy", "ru": "энергия"},
	"ui.food": {"en": "food  ", "ru": "еда   "},
	"warn.nopower": {"en": "!! NO POWER", "ru": "!! НЕТ ПИТАНИЯ"},
	"warn.battery": {"en": "• on battery %d%%", "ru": "• на батарее %d%%"},
	"warn.fuel": {"en": "! fuel %d%%", "ru": "! топливо %d%%"},
	"warn.heat": {"en": "!! RACK OVERHEAT %d°", "ru": "!! ПЕРЕГРЕВ СТОЕК %d°"},
	"warn.coolant": {"en": "! coolant %d%%", "ru": "! хладагент %d%%"},
	"warn.disk": {"en": "! disk %d/%d MB", "ru": "! диск %d/%d МБ"},
	"warn.bin": {"en": "! the bin overflows", "ru": "! ведро переполнено"},
	"warn.bag": {"en": "• holding trash bag", "ru": "• в руках мешок с мусором"},
	"warn.quota": {"en": "! quota settles tomorrow: %d/%d", "ru": "! квота закрывается завтра: %d/%d"},
	"warn.strikes": {"en": "! strikes %d/3", "ru": "! страйки %d/3"},
	"warn.debt": {"en": "! debt %d crd", "ru": "! долг %d кр"},

	# --- game over ----------------------------------------------------------------------------------
	"over.terminated.title": {"en": "CONTRACT TERMINATED", "ru": "КОНТРАКТ РАСТОРГНУТ"},
	"over.terminated.body": {"en": "three missed settlements. the OPERATOR thanks you for your\nservice and reminds you that the door was never locked for you.",
		"ru": "три проваленных расчёта. ОПЕРАТОР благодарит за службу и\nнапоминает: дверь никогда не запиралась для тебя."},
	"over.lost.title": {"en": "NO CARRIER", "ru": "NO CARRIER"},
	"over.lost.body": {"en": "NODE-07 fell out of the mesh at %s, day %d.\nnobody logged the disconnect. nobody was left to.",
		"ru": "NODE-07 выпал из сети в %s, день %d.\nразрыв никто не записал. записывать было некому."},
	"over.stats": {"en": "days on shift     %d\ncredits earned    %d\ncaptures decoded  %d\nnonstandard held  %d\n\n[R] wipe the node, sign a new contract",
		"ru": "дней на смене     %d\nзаработано кр     %d\nдекодировано      %d\nнестандарта       %d\n\n[R] стереть узел, подписать новый контракт"},

	# --- world prompts / modals -----------------------------------------------------------------------
	"prompt.terminal": {"en": "use the terminal", "ru": "сесть за терминал"},
	"prompt.sleep": {"en": "sleep", "ru": "спать"},
	"prompt.locker": {"en": "the locker", "ru": "шкафчик"},
	"prompt.noodles": {"en": "cook noodles (have %d)", "ru": "приготовить лапшу (есть %d)"},
	"prompt.coffee": {"en": "coffee (have %d)", "ru": "кофе (есть %d)"},
	"prompt.bin": {"en": "bin %d/%d — take out the bag", "ru": "ведро %d/%d — забрать мешок"},
	"prompt.chute": {"en": "trash chute", "ru": "мусоропровод"},
	"prompt.gen": {"en": "generator — %s, fuel %d%%", "ru": "генератор — %s, топливо %d%%"},
	"gen.running": {"en": "running", "ru": "работает"},
	"gen.silent": {"en": "SILENT", "ru": "МОЛЧИТ"},
	"prompt.breaker_trip": {"en": "breakers — TRIPPED, reset", "ru": "автоматы — ВЫБИТЫ, взвести"},
	"prompt.breaker_ok": {"en": "breakers — nominal", "ru": "автоматы — в норме"},
	"prompt.cool": {"en": "cooling loop — racks %d°C, coolant %d%%", "ru": "контур охлаждения — стойки %d°C, хладагент %d%%"},
	"prompt.hatch_empty": {"en": "delivery hatch — empty", "ru": "люк доставки — пуст"},
	"prompt.hatch_n": {"en": "delivery hatch — collect (%d items)", "ru": "люк доставки — забрать (позиций: %d)"},
	"prompt.door": {"en": "the stairwell door", "ru": "дверь на лестницу"},
	"prompt.phone_ring": {"en": "ANSWER THE PHONE", "ru": "ВЗЯТЬ ТРУБКУ"},
	"prompt.phone": {"en": "the phone — the line is dead", "ru": "телефон — линия мертва"},
	"prompt.crank": {"en": "hand dynamo — battery %d%%", "ru": "ручное динамо — батарея %d%%"},
	"prompt.scrap": {"en": "pick up scrap", "ru": "подобрать лом"},
	"modal.bed": {"en": "the bunk smells of dust and warm electronics", "ru": "койка пахнет пылью и тёплой электроникой"},
	"modal.bed.until": {"en": "sleep until 08:00 (%dh%02dm)", "ru": "спать до 08:00 (%dч%02dм)"},
	"modal.bed.nap": {"en": "nap for 4 hours", "ru": "вздремнуть 4 часа"},
	"modal.gen": {"en": "diesel generator, older than you", "ru": "дизельный генератор старше тебя"},
	"modal.gen.refuel": {"en": "refuel +%d%% (cans: %d)", "ru": "заправить +%d%% (канистр: %d)"},
	"modal.gen.on": {"en": "switch generator on", "ru": "включить генератор"},
	"modal.gen.off": {"en": "switch generator off", "ru": "выключить генератор"},
	"modal.gen.burn": {"en": "burn scrap, fuel +4%% (have %d)", "ru": "сжечь лом, топливо +4%% (есть %d)"},
	"modal.gen.burnbag": {"en": "burn the trash bag, fuel +3%", "ru": "сжечь мешок мусора, топливо +3%"},
	"modal.cool": {"en": "the loop gurgles like a stomach", "ru": "контур бурчит, как желудок"},
	"modal.cool.top": {"en": "top up coolant (cans: %d)", "ru": "долить хладагент (канистр: %d)"},
	"modal.cool.clean": {"en": "clean the vents (5 min)", "ru": "почистить вентиляцию (5 мин)"},
	"modal.cancel": {"en": "[esc] never mind", "ru": "[esc] неважно"},

	# --- room names / signs -------------------------------------------------------------------------------
	"room.bunk": {"en": "bunk room", "ru": "кубрик"},
	"room.control": {"en": "control room", "ru": "пультовая"},
	"room.kitchen": {"en": "kitchen", "ru": "кухня"},
	"room.utility": {"en": "utility room", "ru": "техничка"},
	"room.server": {"en": "server hall", "ru": "серверная"},
	"room.storage": {"en": "storage", "ru": "склад"},
	"room.corridor": {"en": "corridor", "ru": "коридор"},
	"sign.control": {"en": "CONTROL", "ru": "ПУЛЬТ"},
	"sign.bunk": {"en": "BUNK", "ru": "КУБРИК"},
	"sign.kitchen": {"en": "KITCHEN", "ru": "КУХНЯ"},
	"sign.utility": {"en": "UTILITY", "ru": "ТЕХНИЧКА"},
	"sign.server": {"en": "RACKS", "ru": "СТОЙКИ"},
	"sign.storage": {"en": "STORAGE", "ru": "СКЛАД"},
	"sign.node": {"en": "NODE-07", "ru": "УЗЕЛ-07"},
	"sign.exit": {"en": "EXIT", "ru": "ВЫХОД"},

	# --- toasts: needs / trash / power --------------------------------------------------------------------
	"t.delivery": {"en": "something thumps into the delivery hatch", "ru": "что-то бухает в люк доставки"},
	"t.fuel_at": {"en": "generator fuel at %d%%", "ru": "топлива в генераторе %d%%"},
	"t.gen_dies": {"en": "the generator coughs and dies", "ru": "генератор кашляет и глохнет"},
	"t.on_battery": {"en": "the UPS takes the load. it will not hold long.", "ru": "ИБП принимает нагрузку. надолго его не хватит."},
	"t.overheat": {"en": "RACK OVERHEAT — captures are corrupting", "ru": "ПЕРЕГРЕВ СТОЕК — захваты повреждаются"},
	"t.gen_on": {"en": "generator switched on", "ru": "генератор включён"},
	"t.gen_off": {"en": "generator switched off", "ru": "генератор выключен"},
	"t.no_fuel_cans": {"en": "no fuel cans left — order more, burn scrap, or crank", "ru": "канистры кончились — закажи, жги лом или крути динамо"},
	"t.tank_full": {"en": "the tank is already full", "ru": "бак уже полон"},
	"t.fueled": {"en": "fuel topped up to %d%%", "ru": "бак долит до %d%%"},
	"t.breaker_hum": {"en": "the breakers hum steadily", "ru": "автоматы ровно гудят"},
	"t.breaker_reset": {"en": "breakers reset", "ru": "автоматы взведены"},
	"t.no_coolant": {"en": "no coolant cans left — order more", "ru": "хладагент кончился — закажи ещё"},
	"t.loop_full": {"en": "the loop is already full", "ru": "контур уже полон"},
	"t.coolant_ok": {"en": "coolant loop refilled", "ru": "контур охлаждения заправлен"},
	"t.dust": {"en": "you pull a felt of grey dust out of the vents", "ru": "ты вытаскиваешь из вентиляции войлок серой пыли"},
	"t.no_noodles": {"en": "no noodles left — order more", "ru": "лапша кончилась — закажи ещё"},
	"t.bin_full": {"en": "the bin is overflowing. it is starting to smell.", "ru": "ведро переполнено. начинает пахнуть."},
	"t.noodles": {"en": "hot noodles. life is briefly fine.", "ru": "горячая лапша. жизнь ненадолго в порядке."},
	"t.no_coffee": {"en": "no coffee left — order more", "ru": "кофе кончился — закажи ещё"},
	"t.coffee": {"en": "bitter, instant, perfect", "ru": "горький, растворимый, идеальный"},
	"t.have_bag": {"en": "you are already holding the bag", "ru": "мешок уже у тебя в руках"},
	"t.bin_empty": {"en": "the bin is empty", "ru": "ведро пустое"},
	"t.take_bag": {"en": "you tie up the bag. chute in the kitchen, or the generator burner.",
		"ru": "ты завязываешь мешок. мусоропровод на кухне — или топка генератора."},
	"t.nothing_dump": {"en": "nothing to dump", "ru": "выбрасывать нечего"},
	"t.bag_falls": {"en": "the bag rattles down into the dark for a long time", "ru": "мешок долго грохочет вниз, в темноту"},
	"t.saved": {"en": "day %d, %s. saved.", "ru": "день %d, %s. сохранено."},
	"t.collapse": {"en": "the floor comes up to meet you", "ru": "пол поднимается тебе навстречу"},
	"t.wake_floor": {"en": "you wake up on the concrete. something rearranged the hours.",
		"ru": "ты просыпаешься на бетоне. кто-то переставил часы местами."},
	"t.crank": {"en": "you crank the dynamo. battery at %d%%.", "ru": "ты крутишь динамо. батарея %d%%."},
	"t.crank_tired": {"en": "too tired to crank. eat something.", "ru": "слишком устал, чтобы крутить. поешь."},
	"t.battery_full": {"en": "the battery is full", "ru": "батарея заряжена"},
	"t.scrap": {"en": "scrap collected (%d)", "ru": "лом подобран (всего: %d)"},
	"t.burn_scrap": {"en": "scrap goes into the burner: fuel +4%", "ru": "лом уходит в топку: топливо +4%"},
	"t.burn_bag": {"en": "the bag burns fast and dirty: fuel +3%", "ru": "мешок горит быстро и грязно: топливо +3%"},
	"t.no_scrap": {"en": "no scrap to burn", "ru": "нечего жечь"},
	"t.debt_paid": {"en": "the OPERATOR collects the debt: -%d crd", "ru": "ОПЕРАТОР списывает долг: -%d кр"},

	# --- toasts: economy / net -----------------------------------------------------------------------------
	"t.no_credits": {"en": "not enough credits", "ru": "не хватает кредитов"},
	"t.no_slots": {"en": "no free backplane slots", "ru": "нет свободных слотов на плате"},
	"t.amp_max": {"en": "the line is already amplified to spec", "ru": "линия уже усилена до предела"},
	"t.filter_max": {"en": "the filter lattice is already maxed", "ru": "решётка фильтра уже на максимуме"},
	"t.ordered": {"en": "order placed — hatch delivery in ~2h", "ru": "заказ принят — доставка в люк ~2 часа"},
	"t.hatch_empty": {"en": "the hatch is empty", "ru": "люк пуст"},
	"t.collected": {"en": "collected: %s", "ru": "получено: %s"},
	"t.new_mail": {"en": "new mail: %s", "ru": "новая почта: %s"},
	"t.disk_full": {"en": "DISK FULL — taps stalled. purge or sell captures.",
		"ru": "ДИСК ПОЛОН — тапы стоят. сотри или продай захваты."},
	"t.dl_done": {"en": "download complete: %s (%d MB)", "ru": "загрузка завершена: %s (%d МБ)"},
	"t.thermal": {"en": "thermal fault — a running capture is degrading", "ru": "термосбой — активный захват деградирует"},
	"t.tape_warm": {"en": "capture written to cold tape. the tape is warm.",
		"ru": "захват записан на холодную ленту. лента тёплая."},
	"t.up_disk": {"en": "disk shelf mounted: %d MB total", "ru": "дисковая полка смонтирована: всего %d МБ"},
	"t.up_modem": {"en": "line amplifier installed: lv%d", "ru": "усилитель линии установлен: ур%d"},
	"t.up_filter": {"en": "lattice filter installed: lv%d", "ru": "решётчатый фильтр установлен: ур%d"},
	"t.up_tap": {"en": "tap card seated: %d slots", "ru": "плата тапа вставлена: слотов %d"},

	# --- toasts: events / world -------------------------------------------------------------------------------
	"t.ev_thud": {"en": "something shifts its weight in the server hall", "ru": "что-то переносит вес с ноги на ногу в серверной"},
	"t.ev_breaker": {"en": "the breakers slam open on their own", "ru": "автоматы выбивает сами по себе"},
	"t.ev_dark": {"en": "the whole node goes dark. the dark is not empty.", "ru": "весь узел гаснет. темнота не пустая."},
	"t.ev_ringstop": {"en": "the ringing stops. the silence is worse.", "ru": "звонок обрывается. тишина хуже."},
	"t.ev_pickup": {"en": "you pick up: %s", "ru": "ты снимаешь трубку: %s"},
	"t.ev_deadline": {"en": "the line is dead. it still smells faintly of ozone.",
		"ru": "линия мертва. всё ещё слабо пахнет озоном."},
	"t.light_out": {"en": "the light in the %s gives up", "ru": "свет в помещении «%s» сдаётся"},
	"t.knock": {"en": "three knocks on the stairwell door. evenly spaced. patient.",
		"ru": "три удара в дверь на лестницу. с ровными паузами. терпеливые."},
	"t.sil_gone": {"en": "the corridor is empty. it was always empty.", "ru": "коридор пуст. он всегда был пуст."},
	"t.steps": {"en": "footsteps in the corridor. you did not invite them.", "ru": "шаги в коридоре. ты их не приглашал."},
	"t.wake_bunk": {"en": "you are on the bunk. you don't remember lying down.",
		"ru": "ты на койке. ты не помнишь, как ложился."},
	"t.final": {"en": "every ping you ever sent returns at once", "ru": "все пинги, что ты когда-либо отправил, возвращаются разом"},
	"t.term_dies": {"en": "the terminal dies with the power", "ru": "терминал гаснет вместе с питанием"},
	"door.calm": {"en": "locked from the outside. contract clause 4: it stays that way.",
		"ru": "заперта снаружи. пункт 4 контракта: так и останется."},
	"door.warm": {"en": "locked from the outside. the handle is warm.", "ru": "заперта снаружи. ручка тёплая."},
	"door.breath": {"en": "locked. through the steel you hear slow, patient breathing.",
		"ru": "заперта. сквозь сталь слышно медленное терпеливое дыхание."},

	# --- arrays: flavor pools (indexed via t(key, [i])) ----------------------------------------------------------
	"crt.words": {
		"en": ["NO CARRIER", "STILL THERE?", "WHO IS AT THE CONSOLE", "▓▓░░▓▓░░", "SEGMENT 9 SAYS HI"],
		"ru": ["NO CARRIER", "ТЫ ЕЩЁ ТАМ?", "КТО ЗА ПУЛЬТОМ", "▓▓░░▓▓░░", "СЕГМЕНТ 9 ПЕРЕДАЁТ ПРИВЕТ"],
	},
	"locker.lines": {
		"en": ["a spare jumpsuit, name tape ripped off",
			"a photo of this room, taken from a corner you can't reach",
			"three left gloves",
			"a manual for the terminal, every page is page 41"],
		"ru": ["запасной комбинезон, нашивка с именем сорвана",
			"фотография этой комнаты, снятая из угла, до которого не добраться",
			"три левые перчатки",
			"руководство к терминалу: каждая страница — страница 41"],
	},
	"phone.lines": {
		"en": ["static. under the static, a kettle boiling. yours is cold.",
			"a voice reads six glyphs, slowly. they match your last capture.",
			"'is the seat warm yet?' click.",
			"nobody. but the nobody is definitely listening.",
			"your own voice: 'stop pinging segment nine.' you never said that.",
			"counting. it stops at the number of days you have worked here."],
		"ru": ["статика. под статикой закипает чайник. твой — холодный.",
			"голос медленно читает шесть глифов. они совпадают с твоим последним захватом.",
			"«кресло уже нагрелось?» щелчок.",
			"никого. но это никого определённо слушает.",
			"твой собственный голос: «перестань пинговать девятый сегмент». ты этого не говорил.",
			"счёт. он останавливается на числе дней, что ты здесь работаешь."],
	},
	"title.junk": {
		"en": ["mirror spam", "dead ad cache", "checksum confetti", "loop of hold music"],
		"ru": ["зеркальный спам", "мёртвый кэш рекламы", "конфетти контрольных сумм", "петля музыки ожидания"],
	},
	"title.data": {
		"en": ["ledger shard", "routing tables", "cold mail archive", "broken stream dump", "orphaned wallet log"],
		"ru": ["осколок леджера", "таблицы маршрутизации", "холодный почтовый архив", "дамп битого потока", "лог осиротевшего кошелька"],
	},
	"title.anom": {
		"en": ["NONSTANDARD CARRIER", "pattern that looks back", "recursive silence", "handshake with no peer"],
		"ru": ["НЕСТАНДАРТНАЯ НЕСУЩАЯ", "паттерн, который смотрит в ответ", "рекурсивная тишина", "рукопожатие без второй стороны"],
	},
	"title.echo": {
		"en": ["somebody's voice", "a room tone", "breathing on the line", "your own dial tone"],
		"ru": ["чей-то голос", "тон пустой комнаты", "дыхание на линии", "твой собственный гудок"],
	},
	"echo.lore": {
		"en": ["...a man reads a shopping list, slowly, like a prayer. milk. tape.\nfuses. more tape. the recording is 9 hours long.",
			"...hold music. after 20 minutes a voice says 'thank you for staying'.\nit is not addressed to a customer. it is addressed to you.",
			"...someone typing in the next room. this capture was made here,\non this node, on a night you do not remember working.",
			"...a phone rings inside the recording. your phone rings one second\nlater. the recording is from 1997.",
			"...the previous sysop, counting backwards from one hundred.\nthe capture cuts off at seven.",
			"...an empty channel. carrier present, nobody sending. 40 minutes.\nnear the end, very quietly: 'still there?'",
			"...a modem handshake that resolves into breathing, which resolves\ninto a modem handshake. it loops. it is very calm about it.",
			"...somebody reads out your quota numbers for next week.\nthey have not been assigned yet."],
		"ru": ["...мужчина медленно, как молитву, читает список покупок. молоко. лента.\nпредохранители. ещё лента. запись длится 9 часов.",
			"...музыка ожидания. через 20 минут голос говорит «спасибо, что остаётесь».\nэто адресовано не клиенту. это адресовано тебе.",
			"...кто-то печатает в соседней комнате. этот захват сделан здесь,\nна этом узле, в ночь, когда ты не помнишь, чтобы работал.",
			"...внутри записи звонит телефон. твой телефон звонит секундой позже.\nзапись — 1997 года.",
			"...предыдущий сисоп считает от ста в обратную сторону.\nзахват обрывается на семи.",
			"...пустой канал. несущая есть, никто не передаёт. 40 минут.\nближе к концу, очень тихо: «ещё здесь?»",
			"...хендшейк модема, переходящий в дыхание, переходящее в хендшейк\nмодема. это закольцовано. и совершенно спокойно.",
			"...кто-то зачитывает твои числа квоты на следующую неделю.\nих ещё не назначили."],
	},

	# --- mail: senders + scripted -----------------------------------------------------------------------------------
	"from.op": {"en": "OPERATOR", "ru": "ОПЕРАТОР"},
	"from.maint": {"en": "maintenance", "ru": "техслужба"},
	"from.unknown": {"en": "????", "ru": "????"},
	"from.line": {"en": "the line", "ru": "линия"},
	"mail.call.subj": {"en": "call transcript %s", "ru": "расшифровка звонка %s"},
	"mail.d1.subj": {"en": "shift 001", "ru": "смена 001"},
	"mail.d1.body": {"en": "sysop,\n\nwelcome to NODE-07. the relay is yours between 20:00 and forever.\n\nduties:\n- trace stray carriers in the local address space (terminal, SCAN)\n- tap them, decode the captures, deliver anything sellable (MARKET)\n- keep the generator fed and the racks cold. hardware is billed to you.\n\nweekly delivery quota applies. three missed quotas void the contract.\n\ndo not go upstairs. the door stays locked for your convenience.\n\n- O.",
		"ru": "сисоп,\n\nдобро пожаловать на NODE-07. ретранслятор твой с 20:00 и навсегда.\n\nобязанности:\n- трассируй блуждающие несущие в локальном адресном пространстве (терминал, СКАН)\n- ставь тапы, декодируй захваты, сдавай всё пригодное (РЫНОК)\n- корми генератор и держи стойки холодными. железо — за твой счёт.\n\nдействует недельная квота сдачи. три провала — контракт расторгнут.\n\nнаверх не подниматься. дверь заперта для твоего же удобства.\n\n- О."},
	"mail.d2.subj": {"en": "re: previous sysop", "ru": "re: предыдущий сисоп"},
	"mail.d2.body": {"en": "sysop,\n\npersonal effects of your predecessor may still be on site. dispose of\nthem via the kitchen chute. do not read anything addressed to him.\n\nhis last delivery was 41 days late. do better.\n\n- O.",
		"ru": "сисоп,\n\nличные вещи твоего предшественника могут всё ещё быть на объекте.\nутилизируй их через кухонный мусоропровод. не читай ничего,\nадресованного ему.\n\nего последняя сдача опоздала на 41 день. будь лучше.\n\n- О."},
	"mail.d3.subj": {"en": "line noise advisory", "ru": "предупреждение о шуме линии"},
	"mail.d3.body": {"en": "automated notice.\n\nelevated noise measured on segments 7-12 after 00:00.\nsignal filtering recommended. do not attempt to decode carriers\nthat exhibit NONSTANDARD framing.\n\nthis notice is informational. nothing is wrong.",
		"ru": "автоматическое уведомление.\n\nпосле 00:00 зафиксирован повышенный шум на сегментах 7–12.\nрекомендована фильтрация сигнала. не пытайтесь декодировать несущие\nс НЕСТАНДАРТНЫМ кадрированием.\n\nуведомление носит информационный характер. всё в порядке."},
	"mail.d5.subj": {"en": "inventory audit", "ru": "инвентаризация"},
	"mail.d5.body": {"en": "sysop,\n\naudit says the node burns more fuel than the racks can explain.\nif you are running unregistered taps, stop.\nif you are not, stop anyway.\n\n- O.",
		"ru": "сисоп,\n\nаудит говорит, что узел жжёт больше топлива, чем могут объяснить стойки.\nесли ты гоняешь незарегистрированные тапы — прекрати.\nесли нет — всё равно прекрати.\n\n- О."},
	"mail.d7.subj": {"en": "quota reminder", "ru": "напоминание о квоте"},
	"mail.d7.body": {"en": "sysop,\n\nfirst settlement is tomorrow 08:00. the ledger does not accept\nexcuses, apologies, or descriptions of sounds in the corridor.\n\n- O.",
		"ru": "сисоп,\n\nпервый расчёт завтра в 08:00. леджер не принимает оправданий,\nизвинений и описаний звуков в коридоре.\n\n- О."},
	"mail.d10.subj": {"en": "stairwell door", "ru": "дверь на лестницу"},
	"mail.d10.body": {"en": "automated notice.\n\nthe stairwell door reported 9 open/close cycles last night.\nthe stairwell door has no sensor. please disregard.\n\nthis notice is informational.",
		"ru": "автоматическое уведомление.\n\nдверь на лестницу сообщила о 9 циклах открытия/закрытия за ночь.\nна двери на лестницу нет датчика. просьба игнорировать.\n\nуведомление носит информационный характер."},
	"mail.a25.subj": {"en": "you hear it too", "ru": "ты тоже это слышишь"},
	"mail.a25.body": {"en": "the floor hums at 50Hz. the racks hum at 50Hz.\nyou hum at 50Hz. we noticed you noticing.\n\nkeep pulling the thread.",
		"ru": "пол гудит на 50 Гц. стойки гудят на 50 Гц.\nты гудишь на 50 Гц. мы заметили, что ты заметил.\n\nпродолжай тянуть за нить."},
	"mail.a50.subj": {"en": "inventory of you", "ru": "опись тебя"},
	"mail.a50.body": {"en": "1 bed. 1 kettle. 1 door you never opened.\n1 sysop, slightly worn.\n\nwe have started a ledger of our own.",
		"ru": "1 койка. 1 чайник. 1 дверь, которую ты не открывал.\n1 сисоп, слегка изношен.\n\nмы завели собственный леджер."},
	"mail.a75.subj": {"en": "NO CARRIER", "ru": "NO CARRIER"},
	"mail.a75.body": {"en": "when the line drops mid-word, where does the word go?\n\ncome find out. keep decoding. almost.",
		"ru": "когда линия обрывается на полуслове — куда девается слово?\n\nприходи узнать. продолжай декодировать. почти."},
	"mail.quota_ok.subj": {"en": "settlement week %d: OK", "ru": "расчёт за неделю %d: ЗАЧТЁН"},
	"mail.quota_ok.body": {"en": "quota met (%d/%d). a 100 crd retention bonus has been applied.\nthe OPERATOR values consistency.\n\n- O.",
		"ru": "квота выполнена (%d/%d). начислен удерживающий бонус 100 кр.\nОПЕРАТОР ценит постоянство.\n\n- О."},
	"mail.quota_short.subj": {"en": "settlement week %d: SHORT", "ru": "расчёт за неделю %d: НЕДОБОР"},
	"mail.quota_short.body": {"en": "quota missed (%d/%d). strike %d of 3 recorded.\nthe ledger forgives nothing.\n\n- O.",
		"ru": "квота провалена (%d/%d). записан страйк %d из 3.\nледжер не прощает ничего.\n\n- О."},
	"mail.bail.subj": {"en": "emergency provision", "ru": "аварийное снабжение"},
	"mail.bail.body": {"en": "the node reports total power loss and an empty ledger.\none fuel can has been dispatched to the hatch. +60 crd will be\ncollected at the next settlement.\n\ndo not make this a habit.\n\n- O.",
		"ru": "узел сообщает о полной потере питания и пустом счёте.\nв люк отправлена одна канистра топлива. +60 кр будет удержано\nпри следующем расчёте.\n\nне превращай это в привычку.\n\n- О."},
}


func _ready() -> void:
	lang = "ru" if OS.get_locale_language() == "ru" else "en"
	if FileAccess.file_exists(CFG_PATH):
		var f := FileAccess.open(CFG_PATH, FileAccess.READ)
		if f:
			var data: Variant = JSON.parse_string(f.get_as_text())
			if typeof(data) == TYPE_DICTIONARY and data.has("lang"):
				lang = str(data["lang"])


## Translate. String entries are % -formatted with args (when given);
## Array entries are indexed by args[0], so persisted indices stay
## language-independent.
func t(key: String, args: Array = []) -> String:
	var entry: Variant = S.get(key)
	if entry == null:
		return key
	var v: Variant = entry.get(lang, entry.get("en", key))
	if v is Array:
		var i := 0 if args.is_empty() else int(args[0]) % v.size()
		return str(v[i])
	if args.is_empty():
		return str(v)
	return str(v) % args


## Same, but force a language (used for chain metadata, always en).
func t_in(language: String, key: String, args: Array = []) -> String:
	var prev := lang
	lang = language
	var out := t(key, args)
	lang = prev
	return out


func count(key: String) -> int:
	var entry: Variant = S.get(key)
	if entry == null:
		return 0
	var v: Variant = entry.get(lang, entry.get("en"))
	return v.size() if v is Array else 1


func rand_i(key: String) -> int:
	return randi() % maxi(count(key), 1)


func set_lang(l: String) -> void:
	if l == lang:
		return
	lang = l
	var f := FileAccess.open(CFG_PATH, FileAccess.WRITE)
	if f:
		f.store_string(JSON.stringify({"lang": lang}))
	lang_changed.emit()


func toggle() -> void:
	set_lang("en" if lang == "ru" else "ru")
