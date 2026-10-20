# Ревью Singularity — 10 октября 2026

> Это снимок до исправлений. Первая итерация ремонта и актуальные незакрытые
> задачи описаны в [обновлённом плане](./refactoring-plan-2026-10-10.md).


Основной вывод: у проекта есть работающая основа и большое тестовое покрытие, но мост содержит три критических дефекта проверки и назначения выплат. Приоритет рефакторинга — безопасная, восстанавливаемая операция, затем понятный путь первого пополнения и согласованная навигация. Переписывание всех приложений одновременно не требуется.

План работ с зависимостями и приёмкой: [refactoring-plan-2026-10-10.md](./refactoring-plan-2026-10-10.md).

## Основание и границы

- Проверена рабочая копия на базе `083e99fad3f13459b948b0a46a685b23534b55df`, включая существующие незакоммиченные изменения. Они не приписываются этому ревью.
- Выполнены локальные тесты, проверки типов, чтение кода и конфигурации, проверки публичных URL и сценариев в Chromium. UX проверялся в новых профилях с `ru-RU`, на ширине 390 и 1440 px.
- PHP-тесты выполнены с SQLite `:memory:`. Игры проверялись в временных копиях с отдельным каталогом пользовательских данных. Rust и Jekyll выводили результаты сборки в `/tmp`.
- Подтверждённый дефект кода, воспроизведённый пользовательский сбой и ограничение окружения обозначены отдельно. HTTP 200 не считается доказательством работы финансовой операции.
- Production-выплаты, эксплуатация уязвимостей, перезапуски и деплой не выполнялись. Версия production backend, его БД, очереди и приватные настройки не установлены; локальные findings нельзя автоматически объявлять произошедшими production-инцидентами.
- Полноценные переводы, обмены, установка APK/XPI, работа на физических устройствах и авторизованный UX `/crm` требуют отдельной приёмки. Закрытый доступ к консоли не считался поломкой.

Документ лежит в `docs/operations/`, который уже исключён из публичной сборки VitePress через `srcExclude`.

## Что удалось проверить

Команды запускаются из соответствующей директории компонента, если не сказано иначе.

Полная команда изолированного PHP-прогона из `backend/laravel`:

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M vendor/bin/pest --compact
```

| Компонент | Проверка и результат | Вывод |
| --- | --- | --- |
| Laravel backend | `php -d memory_limit=512M vendor/bin/pest --compact`: 1349 passed, 6529 assertions; 428,24 s | Большая существующая suite проходит; B01–B07 показывают пробелы в проверяемых сценариях |
| Браузерный кошелёк | `npm run test:frontend`: 408/408; `npm run types:check`: exit 0 | Базовая логика и типы работают |
| Laravel lint | `npm run lint:check`: 103 errors, exit 1 | Gate не зелёный; в основном `curly`, отступы между statements и порядок import; это не 103 runtime-поломки |
| Публичный wallet | Новый профиль, портфель, токены, получение, bridge/buy, offline reload | Первый экран работает без JS exceptions; переполнения ширины на 390/1440 px не обнаружено; U01/U03 воспроизведены |
| Публичный bridge | GET страницы, первый шаг wizard | Страница открывается; язык остаётся английским; финансовое завершение не проверялось |
| Покупка с карты | GET `/api/wallet/onramp` | `enabled=true`, но Transak, MoonPay, Ramp Network и Onramper: `available=false`, `reason=unconfigured` |
| Ritual DEX | `CI=true npm test -- --watchAll=false --runInBand`: 11 failed / 14 passed suites; 65 failed / 145 passed tests | Проверки неработоспособны; это ещё не доказательство, что каждый соответствующий runtime-сценарий сломан |
| Ritual types | `node_modules/.bin/tsc --noEmit --incremental false`: exit 2, 4044 diagnostics | TypeScript 4.9.5 не разбирает новые типы зависимостей; нужна согласованная миграция инструментов |
| Browser extension | `npm test`: 43/43 | Правила и helper-функции проходят; реальные Chromium/Firefox install, grants и signing window не проверены |
| Desktop | `npm test`: 46/46 | Правила оболочки проходят; установщик и реальная работа native torrent не проверены |
| Mobile | `npm test`: 10/10 | Конфигурационные проверки проходят; есть дефект release pipeline T03 |
| LainOS | `npm run typecheck`, `npm test`: exit 0; включая keys/markdown/tasks/press/crm/tui smoke | Проверена локальная логика; доступность daemon/provider на операторской машине не установлена |
| Telegram bot | `.venv/bin/python -m unittest discover -s tests`: 153 tests, 1 skipped, OK | Использовать его venv; запуск системным Python упирается в отсутствующие `httpx`/`web3`, а не доказывает поломку бота |
| Cyberia DNS | `npm test`: 21/21 | Локальная логика работает; реальные UDP/TCP 53 и DoH не проверены |
| EVM contracts | С одноразовым тестовым `DEPLOYER_PK`: `npx hardhat test --no-compile`: 165 passing, 48 Solidity + 117 Node | Использовались имеющиеся artifacts; это не новая компиляция и не сверка deployed bytecode |
| Anchor | `cargo test --offline --locked --target-dir /tmp/singularity-review-anchor-target`: 2/2 | Это `test_id` и `test_program_compiles`; реальное lock/release/replay-поведение не покрыто этими двумя тестами |
| UTXO / TON / Yenten relayers | `npm test`: соответственно 12/4/9 passed; `npm run typecheck`: exit 0 во всех трёх | Локальные helpers проходят; реальные payout не выполнялись |
| NO CARRIER | Во временной копии: import, `godot --headless --path … -s tests/smoke.gd` и `tests/crypto_test.gd` | 105 smoke checks и 6 crypto checks, failures 0; полный визуальный/gameplay UX не проверен |
| Wired | В временной копии: import и `godot --headless --path … --quit-after 2` | Запуск без script errors; mint, browser wallet и NPC dialogue end-to-end не проверены |
| Jekyll | `BUNDLE_FROZEN=true bundle check`; `bundle exec jekyll build --destination /tmp/singularity-review-jekyll-site` с тем же env | Проходят; есть Sass deprecation warnings. Обычный `bundle check` пытался переписать lock и завершился ошибкой записи |
| Blockscout compose | `docker compose config --quiet`: exit 1 | Локальный blocker: production-путь `/root/singularity/backend/laravel/.env` недоступен. Не доказательство остановленного explorer |
| Lisp price helper | `sbcl --noinform --non-interactive --load services/lisp/modules/price.lisp`: exit 1 | `READ error during LOAD: end of file`, форма начинается на строке 11 — helper действительно не загружается |
| Host scripts | `bash -n scripts/deploy-prod.sh scripts/ops/heartbeat.sh services/cyberia-node/setup.sh`: exit 0 | Только синтаксис; deploy/heartbeat не исполнялись |
| Второй chain node | README и monitoring registry | Заявлен как prepared, not deployed; отсутствие рабочего узла здесь не новый дефект |
| Landing, IRC, IPFS, Minecraft, Linux | Статический обзор структуры/config; без эксплуатационной приёмки | Работоспособность runtime не установлена |
| `game/SDK` | Workspace перечисляет `frontend/backend/sdk/packages/*`; найден только root `package.json` | Каркас с контрактами и tests, но дочерние workspace packages не оформлены; recursive scripts не доказывают сборку SDK |

В публичной проверке home, wallet, swap, download, bridge, explorer и docs отвечали 200. `bridge.cyberia.church` перенаправлял на `cyberia.church/bridge`. Read-only RPC подтвердил chain ID 49406 и свежую голову: блок 20531261, возраст 0 s на `2026-10-10T04:32:09Z`. Это снимок одной проверки, а не SLA. Explorer indexing lag отдельно не измерялся.

## Критические дефекты и ремонт моста

P0 — возможная неправильная выплата или выпуск актива. P1 — блокировка основного сценария, нарушение гарантии или отсутствие восстановления. P2 — UX/поддерживаемость без доказанной потери средств. Все B01–B03 подтверждены по локальным code paths; возможность эксплуатации текущего deployment не проверялась.

### B01 · P0 · EVM → Solana: источник помечается проверенным без проверки транзакции

[BridgeService.php](../../backend/laravel/app/Services/BridgeService.php), строка 2116: `processEvmToSol()` на строках 2133–2136 сразу вызывает `markSourceVerified()`. Это setter timestamp, не verifier. Сумма берётся из `request.amount` на строке 2144; `relay-release-native.ts` получает лишь recipient и amount на строке 2194. Публичный [BridgeController::submit()](../../backend/laravel/app/Http/Controllers/Api/BridgeController.php) принимает hash, nonce, sender и amount из запроса; job направляет native CYBER.sol сюда.

Следствие: обработчик не доказывает, что предъявленная заявка соответствует реальному burn. Capacity ограничивает наличие денег, но не удостоверяет право на них.

Ремонт: проверять успешный receipt правильной Cyberia chain, адрес CyberBridge и `RedeemCyberSol` event; сверять sender, nonce и Solana recipient. Выплату вычислять из net amount события с точным масштабированием 18 → 6 decimals. Event уже несёт net amount: `crypto/hardhat/contracts/CyberBridge.sol:62` и `:145`; повторно удерживать ту же комиссию нельзя.

Приёмка: выдуманный hash, failed receipt, чужой контракт, неверные recipient/nonce и завышенный amount не вызывают payout. Запись о предъявленном переводе можно сохранить для расследования; до доказательства источник остаётся непроверенным.

### B02 · P0 · Solana → EVM: проверяется authority, но не CYBER.sol mint, получатель и фактическая оплачиваемая сумма

[BridgeService.php](../../backend/laravel/app/Services/BridgeService.php), строки 98 и 168: legacy `verifySolanaDeposit()` принимает первую SPL transfer instruction с нужной authority. Mint и destination token account не проверяются. `processSolToEvm()` на строке 1976 вызывает именно его; полученный amount используется для null-check и лога. Mint wrapper рассчитывается из `request.amount` на строке 2002.

Следствие: код не доказывает, что нужное количество нужного токена поступило мосту.

Ремонт: проверять конкретный mint, владельца destination ATA, успешность и требуемую финальность; брать delta token balance hot wallet из подтверждённой транзакции. В сервисе уже есть более строгий token verifier — проверить и переиспользовать его вместо второго legacy-пути. Хранить исходную raw-сумму и decimals, а пользовательский amount использовать как ожидание для сравнения.

Приёмка: wrong mint, wrong destination, нулевой delta, failed transaction, amount inflation и повтор одного source event не приводят к mint.

### B03 · P0 · Direct deposit не привязан к разрешённому получателю выплаты

[BridgeController.php](../../backend/laravel/app/Http/Controllers/Api/BridgeController.php), строки 151–159: sender принимается как строка, проверяется формат recipient, reservation необязательная. [BridgeAdmissionService.php](../../backend/laravel/app/Services/BridgeAdmissionService.php), строка 272: создаётся committed claim даже при отсутствующей reservation. `BridgeService.php:430` проверяет публичный ERC-20 `Transfer(sender → hot wallet)`, но не отдельное право заявителя назначить destination recipient.

Следствие: настоящий публичный hash/from не удостоверяет первого предъявителя. Unique `source_tx_hash` защищает от второй записи, но не от назначения чужого депозита первым заявителем.

Ремонт: неизменяемый bridge intent, криптографически связанный с источником и получателем. Для source wallet — подпись отдельного bridge challenge над chain, asset, recipient, request ID и nonce/expiry; для депозитов без возможности подписи — подготовленный отдельный deposit address либо on-chain memo/event. Логин на сайте не заменяет эту связь. Подпись login нельзя использовать для bridge intent.

Неатрибутированный перевод сохраняется как обязательство с незаданным получателем; восстановление требует ownership proof. Идентичность проверенного source event — chain + canonical tx hash + event index, с защитой от повторного потребления.

Приёмка: предъявление чужого публичного депозита не меняет recipient; подпись другого домена/action/recipient отклоняется; одинаковый корректный intent получает тот же request.

### B04 · P1 · Блокировка reserve освобождается раньше payout

`BridgeService.php:684` и `:2161`: `block(..., fn () => true)` завершается до capacity check и выплаты. Установленный Laravel освобождает lock после callback — `Illuminate/Cache/Lock.php:129`. Локальная проверка через `ArrayStore` подтвердила: второй lock доступен сразу после callback. Такое поведение также описано в [документации Laravel](https://laravel.com/framework/docs/cache/usage#atomic-locks).

Ремонт: помещать всю критическую секцию внутрь callback либо получать lock без callback и освобождать в `finally`. Отдельно обрабатывать `LockTimeoutException`: `block()` выбрасывает исключение, а не возвращает false при истечении ожидания. Проверить срок lock и сериализацию nonce/UTXO общего signing wallet между asset pools.

Приёмка: конкурентный worker не получает тот же lock во время payout; exception освобождает lock; contention оставляет восстановимое обязательство. Факт уже произошедшей двойной выплаты не установлен.

### B05 · P1 · WalletBridge пропускает reserve перед переводом

`resources/js/components/wallet/WalletBridge.vue:530` вызывает `wallet.bridgeDeposit()` до серверной reservation. `lib/wallet/bridge.ts:249` вообще не содержит reservation в `BridgeSubmission`. Основной BridgeWizard её передаёт, поэтому два интерфейса дают разные гарантии.

Ремонт: общий reserve-first flow для wallet и wizard: capacity → серверный claim → review → подпись → регистрация уже совершённого перевода. После broadcast отсутствие/истечение claim не должно терять обязательство или заставлять отправлять средства ещё раз.

Приёмка: `unmeasured/unavailable` и недостаточная capacity не вызывают signer; expired claim до подписи запрашивается заново; равенство суммы и capacity допустимо, расчёты остаются integer.

### B06 · P1 · Submit долго ждёт payout; потерянный ответ не восстанавливается повтором

`BridgeController.php:276` делает `dispatchSync()`. Запись уже создана, но duplicate на строке 245 возвращает 422 без ID. Wizard начинает tracking только после успешного ответа; контекст `useBridgeFlow` остаётся в памяти.

Ремонт: атомарно записывать intent/obligation и планировать job после commit; быстро возвращать ID. Идемпотентный повтор с проверенным тем же intent возвращает существующий request/status. Сохранять локально pending intent/hash/ID до broadcast, восстанавливать после reload. Если нужна гарантия против сбоя между commit и queue dispatch, использовать durable outbox или обязательный recovery sweep.

Приёмка: потеря ответа до/после payout продолжает один перевод; reload не создаёт новый source transfer; UI различает «источник отправлен» и «получатель получил».

### B07 · P1 · Broadcast + timeout оставляет `paying_out` без штатного reconcile

`ProcessBridgeRequest.php:155` не превращает false результата service в повтор job. Scheduler планирует deposit sweep/reservation cleanup, но не полноценный reconcile всех обязательств. `BridgeRelayCommand.php:72` отказывает строке с `hasPayout()`, кроме `burn_pending`. Поэтому имеющийся `resumeAfterPayout()` не достигается обычным CLI retry для `paying_out`.

Ремонт: durable reconcile для существующего payout hash, `awaiting_liquidity` и подтверждённых незавершённых обязательств. Retry определяется состоянием; наличие hash разрешает только чтение receipt и завершение учёта, не новый send. Native-ветки также не должны объявлять completed лишь по наличию hash.

Приёмка: восстановление идёт без открытой вкладки; RPC timeout → повтор проверки того же hash → подтверждение; burn retry не повторяет payout. На UI: «Выплата отправлена — проверяем подтверждение», время последнего обновления и ссылка на запись.

## UX: что мешает пользователю

| ID / приоритет | Доказательство и эффект | Ремонт и критерий |
| --- | --- | --- |
| U01 / P1 | Портфель → Токены: title меняется, URL и history length остаются прежними; browser Back вывел в `about:blank`. `Wallet.vue` хранит section/overlay в ref, читающий deep link код не синхронизирует обычную навигацию | Единый history adapter для browser/native/Telegram; Back закрывает overlay или возвращает на предыдущий экран, сохраняет draft; допустимые экраны получают восстанавливаемый URL |
| U02 / P1 | `public/sw.js:127` сравнивает только pathname и вызывает `focus()`. Локальный stub для `/wallet?section=feed` при открытом portfolio зарегистрировал только focus, без navigate | Навигировать к полному safe same-origin URL либо передавать явное событие wallet navigation; сохранить Inertia history state. Приёмка: push открывает нужный раздел даже при уже открытом кошельке; [WindowClient.navigate](https://developer.mozilla.org/en-US/docs/Web/API/WindowClient/navigate) поддерживает переход существующего окна |
| U03 / P1 | При контролирующем service worker offline reload `/wallet` показал английскую заглушку. `sw.js:76` получает HTML только по сети; local vault остался, но интерфейс получения/backup недоступен | Минимальный публичный local wallet shell; cache только versioned static code и безопасный bootstrap, без authenticated HTML/API/секретов. Offline: доступ к своим адресам и локальным ключам через обычные защиты, операции и свежие балансы честно недоступны. Shells требуют отдельной упаковки этого fallback |
| U04 / P1 | Все четыре on-ramp провайдера публично `unconfigured`; новый wallet имеет только Cyberia, buy по умолчанию предлагает выключенную Base. Купить с карты сейчас нельзя | Подключить одного провайдера и пройти sandbox→release путь; UX предлагает только доступные способы, объясняет сеть доставки, включение сети и отдельный перевод в Cyberia. Пока провайдера нет — работающее получение по адресу и ясная причина, без ложного обещания покупки |
| U05 / P2 | При `ru-RU` wallet/download русские, публичные bridge/swap и их header английские; причины закрытых маршрутов внутри wallet тоже приходят на английском | Общий browser-side locale, словари и reason codes для публичных финансовых экранов; выбор языка сохраняется при переходах; `{placeholders}` и English fallback остаются проверяемыми |
| U06 / P2 | Новый Ritual показывает обязательный QuickSwap disclaimer и QuickPerps banner прежде своего продукта. `TermsWrapper.tsx:17` при пустом TOS version снова показывает его на следующих посещениях | Убрать чужую рекламу; заменить продуктовую copy на корректную для Ritual; versioned acceptance с тестом двух визитов. Юридические формулировки требуют владельца продукта; принятие условий за пользователя при ревью не выполнялось |
| U07 / P2, гипотеза usability | На первом мобильном portfolio одновременно 15 shortcuts и 6 нижних destinations, включая advanced-функции. Видимый risk backup полезен, но главный экран конкурирует с каталогом функций | Сохранить Cyberia-стиль; главные действия «Получить/Отправить/Обмен», активные операции, следующий шаг первого пополнения; advanced-функции через «Ещё», избранное/недавнее. Подтвердить улучшение задачами с пользователями, а не считать уменьшение числа кнопок самоцелью |
| U08 / P2, зависимость внешней сети | На `/swap` воспроизведён `HTTPTransportError: Failed to fetch` внутри DEX Screener iframe; failed requests идут к `io.dexscreener.com`. Это не доказательство неисправного signer/router | Разделить доступность графика и возможность обмена; fallback с понятной причиной и внешней ссылкой, без бесконечного loader. Протестировать заблокированный chart origin и работающий собственный quote |

Сохранять: layoutless wallet, предупреждение о backup, честные `null/—`, per-chain capabilities, сеть на receive, HoldButton с клавиатурой, reduced-motion, browser-side custody и отсутствие второго XP ledger. Auto-create и отсутствие начальной формы — осознанная текущая модель; улучшать постепенное сохранение доступа, не возвращать длинный onboarding без проверки пользы.

## Инженерные причины и дополнительный ремонт

- **T01 / P1 — Ritual toolchain и tests.** `package.json` допускает TypeScript `^4.1.2`, установлен 4.9.5; новые зависимости используют `const` type parameters из [TypeScript 5.0](https://www.typescriptlang.org/docs/handbook/release-notes/typescript-5-0.html#const-type-parameters). Поэтому `skipLibCheck` не исправит parser errors. Jest не разрешает существующий export `@web3modal/ethers5/react`; часть reducer tests объявляет store без инициализации (`state/swap/reducer.test.ts:6`); v3 tests ожидают calldata другой ABI. Ремонт разделить на настройку runner/store, ABI vectors против реального контракта и миграцию CRA→Vite. [React объявил CRA deprecated](https://react.dev/blog/2025/02/14/sunsetting-create-react-app); это основание планировать замену, не менять одновременно router, ethers и AMM-математику. Production build здесь не запускался, новый артефакт не подтверждён.
- **T02 / P1 — отсутствующие CI gates.** `.github/workflows/ci.yml` запускает PHP style/build/Pest, но не Laravel frontend tests/types/lint, Ritual tests, daemon suites, relay suites или browser journeys. Apps tests запускаются в отдельном release workflow. Добавить checks по изменённым поддеревьям и отдельный critical-journeys job; сначала устранить текущие failures. Deploy сейчас не зависит от hardhat job — изменения контрактного интеграционного кода требуют подходящего дополнительного gate.
- **T03 / P1 — Android debug может попасть в обычный release.** `.github/workflows/apps.yml:147`, `:193` при отсутствии keystore собирает debug APK, именует его `Cyberia.apk` и передаёт в публикацию. `AppDownloadService.php:63` различает assets по имени, не по signing/debuggable. Это противоречит правилу AGENTS о непубликации debug wallet. Исправить: debug только workflow artifact с отдельным именем; в release/download — release APK с постоянной подписью и `debuggable=false`. Проверить обновление с сохранением vault. Текущий опубликованный APK по этим признакам не исследован; GitHub API при попытке прочитать release metadata вернул rate-limit 403.
- **T04 / P2 — аналитика bridge считает регистрацию завершением.** `WalletBridge.vue:583` отправляет `bridge_completed`, когда submit accepted; комментарий прямо говорит, что payout неизвестен. `EventTaxonomy.php:115` считает это meaningful и `first_transaction` milestone. Ремонт: completed только после destination settlement; submitted — отдельная стадия, changes в обеих allowlists/mirrors. Иначе измерение UX-улучшения моста опирается на ложную конверсию. Исторические milestones исправлять лишь с ground-truth mapping, не по предположению.
- **T05 / P2 — Lisp helper не загружается.** `services/lisp/modules/price.lisp:11`: незакрытая форма; дополнительно needle строится как `"priceUsd:"`, не JSON key/value boundary. В `run-server.lisp` отдельный parser, поэтому этот finding не доказывает остановку всего сервиса. Решить, нужен ли helper; если нужен — корректный JSON parsing, fixture test, timeout сетевого чтения; иначе убрать неиспользуемую ветку после проверки ссылок.
- **T06 / P2 — воспроизводимость ops и scaffolds.** Compose завязан на production absolute paths; `game/SDK` не имеет заявленных дочерних manifests. Отдельный local env/config fixture для `compose config --quiet`, явный runnable contract SDK и проверка manifest completeness. Не подставлять production secrets в локальные проверки.

Кандидаты для постепенного разделения: `Wallet.vue` — 2339 строк, `useMultiWallet.ts` — 1857, `WalletSwap.vue` — 2824, `Swap.vue` — 3019, `Launchpad.vue` — 3336. Размер не доказывает баг; это границы ответственности, где уже обнаружены разный flow и навигационные ошибки. Сначала закрепить поведение, затем извлекать orchestration, navigation и presentation небольшими PR.

## Что требует следующего обследования

Сверить release/commit на production, pending bridge obligations и реальные worker/scheduler конфигурации; проверить service states через существующий мониторинг и heartbeat. Измерить explorer head lag, доступность DNS/IRC/IPFS/Monero и signer inventory без выплат. Для console — с операторской сессией проверить live updates, drafts, quiet/unreadable states и мобильную работу. Для расширения/оболочек — реальные install/restart/update, Firefox persistence, deep links, WebView storage и доступность backup. Для Anchor — local-validator/LiteSVM интеграционные lock/release/replay/authority tests. Для сайтов/игр — link checks, keyboard/touch, loading, save/restore и полный gameplay.

Такие компоненты до этой проверки имеют статус «не установлено», а не «сломано» или «работает». Задачи и условия выхода из этих неопределённостей внесены в связанный план.
