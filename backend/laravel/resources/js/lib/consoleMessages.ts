import type { Messages } from '@/composables/useLocale';

/**
 * Every word the operator console says, in both languages it is read in.
 *
 * The server sends keys and parameters, never sentences: a queue item is a
 * kind plus numbers, and the phrase around them lives here. That keeps one
 * copy of the wording per language instead of one per language per endpoint,
 * and it is why a row can say "молчит 34 дня" without PHP knowing any Russian.
 *
 * Russian is not a translation of the English here — it is the language the
 * console was designed in, and the English mirrors it.
 *
 * Plural forms are stored pipe-separated as `one|few|many` and picked by
 * `plural()` in `console.ts`; English simply repeats the last two.
 */
export const consoleMessages: Messages = {
    en: {
        /* chrome */
        'nav.now': 'Now',
        'nav.people': 'People',
        'nav.tasks': 'Tasks',
        'nav.numbers': 'Numbers',
        'nav.machines': 'Machines',
        'nav.mockup': 'Design',
        'group.chain': 'Chain',
        'group.web': 'Web',
        'group.infra': 'Infra',
        'group.daemon': 'Daemons',
        'group.onchain': 'On-chain',
        'group.product': 'Product',
        'top.fundedActive': 'Active with money',
        'top.installs': 'Installs 30d',
        'top.bridge': 'Bridge 30d',
        'top.sweep': 'swept {time}',
        'top.noSweep': 'never swept',
        'top.allGood': 'All present',
        'top.shift': 'shift: {name}',

        /* the queue */
        'feed.attention': 'Requires action',
        'feed.watch': 'Watching',
        'feed.watchNote': 'nothing to do, but worth having in view',
        'feed.background': 'Background',
        'feed.backgroundNote': 'thirty days',
        'feed.incident.down': '{service} is down',
        'feed.incident.degraded': '{service} is degraded',
        'feed.incident.body':
            '{reason} · incident opened after two failures in a row.',
        'feed.task.title': 'Task overdue',
        'feed.task.body': '«{task}» · {assignee} · {priority}',
        'feed.whale.title': 'A whale appeared',
        'feed.whale.body':
            '{name} · {cyber} CYBER and {cyberSol} CYBER.sol · first seen by the sync on this threshold.',
        'feed.gas.low': 'The gas station tank is running out',
        'feed.gas.empty': 'The gas station tank is empty',
        'feed.gas.body':
            '{tank} CYBER — about {drips} drips at a daily cap of {dailyCap}. An empty tank breaks nothing loudly; it quietly switches off a newcomer’s first payment.',
        'feed.retention.title': 'D7 retention fell from {before}% to {now}%',
        'feed.retention.body': 'Cohort {cohort}, {size} installations.',
        'feed.bridge.title': 'A bridge payout failed',
        'feed.bridge.body':
            '{count} request(s) in the last day · newest {amount} {token}, {direction}. A timeout on a slow chain can be a false failure — check the destination before retrying.',

        /* evidence and actions */
        'evidence.day': 'day',
        'evidence.dueWas': 'was due',
        'evidence.holdings': 'on both chains',
        'evidence.noPrice': 'price unreadable',
        'evidence.tank': 'tank left',
        'evidence.d7': 'D7, by cohort',
        'evidence.failedRequests': 'failed',
        'action.openMachine': 'Open machine',
        'action.openTask': 'Open task',
        'action.openPerson': 'Open dossier',
        'action.topUpTank': 'Top up',
        'action.investigate': 'Look into it',
        'action.openBridge': 'Open bridge',
        'action.snooze': 'Snooze',
        'action.wake': 'Bring back',
        'action.wholeFeed': 'Whole feed',
        'action.snoozedUntil': 'until {time}',

        /* watch list */
        'watch.bridge.title': 'Bridge: {count} request(s) waiting',
        'watch.bridge.body':
            '— longer than {minutes} minutes; the relayer is alive and paying, the queue is simply long',
        'watch.host.title': '{host} has been silent for {minutes} min',
        'watch.host.body':
            '— everything it reports on stands in "unknown": that is the reporter’s state, not the services’',
        'watch.campaign.title': 'Best source this week: {source}',
        'watch.campaign.body':
            '— {campaign} · {users} installs · {rate}% activated',
        'watch.snoozed.title': 'Snoozed: {count}',
        'watch.snoozed.body': '— {items}',

        /* silence */
        'quiet.title': 'Quiet',
        'quiet.body':
            'Nothing requires you. An empty screen here means exactly that and not a broken collector: the last sweep ran {ago} and {answered} of {registered} services answered it.',
        'quiet.duration': 'Quiet for',
        'quiet.sinceUnknown': 'no incident on record yet',

        /* background tiles */
        'tiles.funded_active': 'Active with money',
        'tiles.installs': 'New installs',
        'tiles.swaps': 'Swap volume',
        'tiles.bridge': 'Bridge transfers',
        'tiles.services': 'Services',
        'tiles.tasks': 'Open tasks',
        'tiles.fundedActive.note': 'funded, acted within {days} days',
        'tiles.installs.delta': '{delta}% against the previous window',
        'tiles.installs.note': 'first window',
        'tiles.swaps.chain': '{swaps} swaps on chain',
        'tiles.swaps.wallet': 'from the wallet’s own events',
        'tiles.bridge.note': 'completed · {failed} failed',
        'tiles.services.note':
            '{down} down · {degraded} degraded · {unknown} unknown',
        'tiles.tasks.note': '{overdue} overdue · {unassigned} unowned',

        /* people */
        'people.title': 'People',
        'people.segments': 'Segments',
        'people.happening': 'What is happening to them',
        'people.sortNote': 'sorted by how fresh the signal is',
        'people.segmentNote':
            'A segment is a saved question, not a set of checkboxes. Hover a segment to read its rule.',
        'people.shown': '{shown} of {total}',
        'people.rest': 'the rest had nothing change this month',
        'people.more': 'Show more',
        'people.search': 'name, address, telegram…',
        'people.sync': 'Sync',
        'people.export': 'Export',
        'people.add': '+ Person',
        'people.empty': 'Nobody matches this question right now.',
        'segment.all': 'Everyone',
        'segment.whales': 'Whales',
        'segment.new_whales': 'New whales this month',
        'segment.awaiting': 'Waiting on us',
        'segment.silent_customers': 'Customers gone quiet',
        'segment.one_and_done': 'Left after the first deal',
        'segment.cold_leads': 'Leads who never answered',
        'segment.solana_only': 'Solana only',
        'rule.all': 'every contact in the base',
        'rule.whales': 'type = whale (set by the sync from balances)',
        'rule.new_whales': 'type = whale, first seen in the last 30 days',
        'rule.awaiting': 'has an open task on our side',
        'rule.silent_customers':
            'customer with nothing on record for {days} days',
        'rule.one_and_done':
            'qualified or customer, no note ever, older than {days} days',
        'rule.cold_leads': 'lead, status new, no note ever',
        'rule.solana_only': 'has a Solana address and no EVM address',
        'crm.type.lead': 'lead',
        'crm.type.holder': 'holder',
        'crm.type.whale': 'whale',
        'crm.status.new': 'new',
        'crm.status.contacted': 'contacted',
        'crm.status.qualified': 'qualified',
        'crm.status.customer': 'customer',
        'crm.status.lost': 'lost',
        'crm.source.manual': 'added by hand',
        'crm.source.platform': 'the site',
        'crm.source.bridge': 'the bridge',
        'crm.source.whale_bot': 'the whale bot',

        /* people signals */
        'signal.note': 'We wrote: {body}',
        'signal.taskOpen': 'Open promise: {task}',
        'signal.taskOverdue': 'Overdue promise: {task} (was due {due})',
        'signal.moneyOut': 'Moved {amount} {token} out through the bridge',
        'signal.moneyIn': 'Brought {amount} {token} in through the bridge',
        'signal.becameWhale': 'Crossed the whale threshold',
        'signal.appeared': 'Appeared, from {source}',
        'signal.nothing': 'Nothing on record',
        'signal.silent': 'Silent for {days} days',
        'signal.ago': '{ago} ago',

        /* person */
        'person.back': 'People',
        'person.write': 'Write',
        'person.addTask': '+ Task',
        'person.delete': 'Delete',
        'person.money': 'Money',
        'person.activity': 'Activity, 12 weeks',
        'person.activityNote':
            'transfers per week — this app keeps no balance history',
        'person.noActivity': 'no transfers on record in twelve weeks',
        'person.who': 'Who this is',
        'person.next': 'What next',
        'person.overdueCount': '{count} overdue',
        'person.everything': 'Everything on record',
        'person.everythingNote':
            'visits, transfers, our messages and notes — one stream',
        'person.evm': 'EVM',
        'person.solana': 'Solana',
        'person.telegram': 'Telegram',
        'person.email': 'Email',
        'person.tags': 'Tags',
        'person.source': 'Came from',
        'person.since': 'Known since',
        'person.lastSync': 'Last sync',
        'person.none': 'not given',
        'person.noTasks': 'nothing promised',
        'person.noHistory': 'nothing on record yet',
        'person.summary.overdue':
            'Here since {since}, from {source}. We owe them {open} thing(s), {overdue} of which is past its date.',
        'person.summary.moved':
            'Here since {since}, from {source}. Last movement: {amount} {token} on {when}; {transfers} transfers in twelve weeks.',
        'person.summary.talked':
            'Here since {since}, from {source}. We last spoke on {lastNote}; nothing is promised.',
        'person.summary.quiet':
            'Here since {since}, from {source}. Nothing on record besides the sync.',
        'person.event.note': 'Note by {author}',
        'person.event.task': 'Task set for {assignee}',
        'person.event.taskDone': 'Task closed by {assignee}',
        'person.event.bridgeOut': 'Bridge out · {direction} · {status}',
        'person.event.bridgeIn': 'Bridge in · {direction} · {status}',
        'person.event.appeared': 'Appeared in the base, from {source}',
        'person.event.page_view': 'Opened {page}',
        'person.event.landing_view': 'Opened the landing page',
        'person.event.wallet_connected': 'Connected a wallet',
        'person.event.swap_completed': 'Swapped',
        'person.event.liquidity_added': 'Added liquidity',
        'person.addNote': 'Add a note…',
        'person.saveNote': 'Save',

        /* tasks */
        'tasks.title': 'Tasks',
        'tasks.stats':
            '{open} open · {overdue} overdue · {unowned} with no owner',
        'tasks.done': 'Closed',
        'tasks.quickAdd':
            'write to the whale about bridge limits @lain !tomorrow #Nakamoto',
        'tasks.quickAddHint':
            '@ assignee · ! due · # person — parsed as you type, no mouse needed',
        'tasks.unowned': 'With no owner',
        'tasks.unownedNote':
            'nobody picks these up by themselves — that is a state, not a line in a list',
        'tasks.claim': 'Take',
        'tasks.overdue': 'Overdue',
        'tasks.soon': 'Today and tomorrow',
        'tasks.later': 'Later',
        'tasks.noDue': 'no date',
        'tasks.today': 'today',
        'tasks.tomorrow': 'tomorrow',
        'tasks.nobody': 'unowned',
        'tasks.footer':
            '{closed} closed this week · median time from set to closed {median} days',
        'tasks.footerEmpty': 'nothing closed this week',
        'tasks.empty': 'empty',
        'tasks.done.action': 'Done',
        'tasks.journal.open': 'Open completed task journal',
        'tasks.journal.title': 'Completed tasks',
        'tasks.journal.empty': 'No completed tasks yet',
        'priority.low': 'low',
        'priority.normal': 'normal',
        'priority.high': 'high',

        /* numbers */
        'numbers.title': 'Numbers',
        'subject.installs': 'Wallet installations',
        'subject.sessions': 'Site sessions',
        'numbers.subjectNote':
            'Who is being counted is a switch, not two different pages: the confusion between an installation and a session cost us a quarter of arguing.',
        'numbers.growth.title': 'Are we growing?',
        'numbers.growth.suffix': 'new installations',
        'numbers.growth.suffixSessions': 'sessions',
        'numbers.growth.opened': 'opened',
        'numbers.growth.acted': 'did something confirmed',
        'numbers.growth.sessions': 'sessions',
        'numbers.growth.delta':
            '{delta}% against the previous window. Biggest source: {source} / {campaign} with {sourceUsers}; without it the window is {without}.',
        'numbers.growth.deltaSessions': '{delta}% against the previous window.',
        'numbers.growth.first':
            'First window on record — nothing to compare against yet.',
        'numbers.money.title': 'Do they reach money?',
        'numbers.money.suffix': '% reached the first transaction',
        'numbers.money.conclusion':
            '{drop} of {wallets} who created a wallet never funded it. This is the most expensive step and it is not about the interface: money has to arrive from outside.',
        'numbers.money.sessions':
            '{wallets} of {visitors} sessions connected a wallet at all.',
        'numbers.money.note':
            'On the right — share of the previous step: it says where we lose people, not how bad things are overall.',
        'numbers.caveat.internal':
            'Not counted: {count} of our own installations. We use this wallet more than anyone, and a rate that includes us describes our testing.',
        'numbers.caveat.internalSessions':
            'Not counted: {count} of our own sessions. Two operators produced most of the conversions ever recorded here.',
        'numbers.caveat.internalIncluded':
            'Our own installations and sessions are included in these numbers.',
        'numbers.caveat.notional':
            '{trades} trade(s) worth a nominal ${usd} left out of volume: their own price impact says the figure describes a pool being drained, not money changing hands.',
        'numbers.caveat.bridgeUnfiltered':
            'The bridge step still counts our own sessions — bridge events keep their own session ids, which no site session can be matched against.',
        'numbers.caveat.bridgeAndInternal':
            '{count} of our own sessions are out of the steps above — but not out of the bridge step, which is the number on the left: bridge events keep their own session ids, which no site session can be matched against.',
        'numbers.return.title': 'Do they come back?',
        'numbers.return.suffix': '% D7, was {before}',
        'numbers.return.drop': 'D7 fell from {before}% to {now}%.',
        'numbers.return.dropVersion':
            'D7 fell from {before}% to {now}%, and it sits on {worst} ({worstRate}%) while {best} holds {bestRate}%. That is a build regression, not the market — it goes to a developer, not to marketing.',
        'numbers.return.steady': 'D7 is holding at {now}%, was {before}%.',
        'numbers.return.young':
            'No two cohorts have matured yet — a rate read early only ever goes up.',
        'numbers.return.sessions':
            'Sessions, by week of first visit. {weeks} cohorts on record.',
        'numbers.return.immature': 'not matured',
        'numbers.sources.suffix': 'by D7',
        'numbers.sources.title': 'Where do the ones who stay come from?',
        'numbers.sources.conclusion':
            'Sorted by D7, not by installs. {source} gives {d7}% from only {users} installations.',
        'numbers.sources.empty':
            'No source has enough installations to rank yet.',
        'numbers.sources.unmeasured':
            'Not measured for sessions: the site does not record where a visit came from, and borrowing the installations’ answer here would be a different subject under this label.',
        'numbers.sources.unmeasuredNote':
            'no source recorded for site sessions',
        'numbers.breaks.title': 'What breaks?',
        'numbers.breaks.suffix': '% of transactions land',
        'numbers.breaks.conclusion':
            '{failures} failures out of {attempts} attempts. Half of a failure list is usually an empty tank and people changing their mind — those are not faults.',
        'numbers.breaks.sessions':
            '{failed} of {finished} finished bridge requests did not land.',
        'numbers.cost.title': 'What does an activated user cost?',
        'numbers.cost.suffix': 'per activation',
        'numbers.cost.conclusion':
            '{drips} drips to {addresses} addresses against {activated} activations. The limit here is not money, it is the tank.',
        'numbers.cost.idle': 'The station has not paid out in this window.',
        'numbers.cost.unmeasured':
            'Not measured for sessions: sponsorship is paid per installation, and per-session cost would be an invented number.',
        'numbers.cost.unmeasuredNote':
            'the ledger records installations, not sessions',
        'numbers.cost.drips': 'Drips',
        'numbers.cost.spent': 'Total spent',
        'numbers.cost.perAddress': 'Per address',
        'numbers.cost.perActivation': 'Per activation',
        'numbers.cost.refused': 'Refused',
        'numbers.cost.addresses': '{addresses} addresses',
        'numbers.cost.fromLedger': 'from the payout ledger',
        'numbers.cost.fixedDrip': 'fixed drip',
        'numbers.cost.refusedNote': 'refused by the station’s own bounds',
        'numbers.cost.ratio': '{drips} drips → {activated} activations',
        'numbers.cost.ledgerNote':
            'The sum comes from payout rows, never from browser events: an event can be replayed, a row cannot.',
        'numbers.empty': 'no data in this window',
        'numbers.nothingBroke': 'nothing failed in this window',
        'numbers.window': 'window',
        'numbers.cohort': 'Cohort',
        'numbers.week': 'Week',
        'numbers.source': 'Source',
        'numbers.campaign': 'Campaign',
        'numbers.installs': 'Installs',
        'numbers.activation': 'Activation',
        'numbers.quality': 'Quality',
        'numbers.people': 'people',
        'numbers.step': 'Step',
        'numbers.status': 'Status',
        'numbers.count': 'Count',
        'step.first_open': 'Opened the app',
        'step.wallet': 'Created a wallet',
        'step.funded': 'Funded it',
        'step.activated': 'First transaction',
        'step.retained': 'Came back after 7 days',
        'step.visitors': 'Visited',
        'step.wallet_connected': 'Connected a wallet',
        'step.swap': 'Swapped',
        'step.liquidity': 'Added liquidity',
        'step.bridge': 'Used the bridge',

        /* machines */
        'machines.title': 'Machines',
        'machines.registry':
            '{total} programs in the registry · {down} down · {degraded} degraded · {unknown} silent',
        'machines.registryNote':
            'The registry is a file, not a table: adding a service is an edit to config/monitoring.php',
        'machines.onlyProblems': 'Only problems',
        'machines.all': 'Everything',
        'machines.attention': 'Requires action',
        'machines.hosts': 'The machines holding all this up',
        'machines.hostsNote': 'a heartbeat a minute',
        'machines.load': 'Load',
        'machines.memory': 'Memory',
        'machines.swap': 'Swap',
        'machines.disk': 'Disk',
        'machines.uptime': 'Uptime',
        'machines.free': 'free',
        'machines.used': 'used',
        'machines.ofCpus': 'of {cpus}',
        'machines.noReboot': 'without a reboot',
        'machines.ago': '{ago} ago',
        'machines.silent': 'silent for {ago}',
        'machines.silentNote':
            'The tiles above stand hatched because of this. The reporter died, not the services: no incident is opened for them and they count in no uptime.',
        'machines.unregistered': 'Running, not in the registry: {list}',
        'machines.idle': 'Nobody uses it',
        'machines.idleNote':
            'Only what can be measured lands here. Another {count} programs are marked "not measured" — the RPC, the explorer and the DEX among them, and if they landed on this list it would be recommending we delete half the product.',
        'machines.idleMore': 'and {count} more',
        'machines.idleNever': 'never opened',
        'machines.idleDays': 'nobody for {days} days',
        'machines.incidents': 'What broke',
        'machines.incidentsNote':
            'opened after two failures in a row, closes itself',
        'machines.ongoing': 'ongoing',
        'machines.noIncidents': 'nothing has broken in the retention window',
        'machines.notMeasured': 'not measured',
        'machines.off': 'switched off deliberately',
        'status.up': 'up',
        'status.degraded': 'degraded',
        'status.down': 'down',
        'status.unknown': 'unknown',
        'status.off': 'off',
        'machines.critical': 'crit',

        /* install dossier */
        'install.back': 'Numbers',
        'install.internal.tag': 'ours',
        'install.internal.mark': 'This one is ours',
        'install.internal.unmark': 'Not ours after all',
        'install.title': 'Installation {short}',
        'install.whereFrom': 'Where it came from',
        'install.firstRun': 'First run',
        'install.lastSeen': 'Last seen',
        'install.platform': 'Platform',
        'install.version': 'Version',
        'install.language': 'Language',
        'install.source': 'Source',
        'install.campaign': 'Campaign',
        'install.referrer': 'Referrer',
        'install.landing': 'Entry point',
        'install.sessions': 'Sessions',
        'install.addresses': 'Linked addresses',
        'install.identityNote':
            'The identifier is anonymous and minted by the app on first run. A wallet address never lands here: one person holds several, and counting addresses would multiply every user.',
        'install.whereStuck': 'Where it stands',
        'install.milestone.opened': 'Opened the app',
        'install.milestone.wallet': 'Created a wallet',
        'install.milestone.funded': 'Funded it',
        'install.milestone.activated': 'First transaction',
        'install.milestone.returned': 'Came back after 7 days',
        'install.after': 'after {gap}',
        'install.waiting': 'waiting {gap}',
        'install.canDo': 'What can be done',
        'install.peers':
            '{count} installations are at the same step this month. Whatever is done for them is done for this one.',
        'install.timeline': 'Timeline',
        'install.timelineNote':
            'only what the allow list names is ever written down',
        'install.milestoneTag': 'milestone',
        'install.meaningfulNote':
            'Meaningful means settled on chain; broadcasting is not settlement. That is why an unfinished row is empty rather than optimistically filled.',
        'install.noEvents': 'nothing recorded yet',

        /* the design this console was built from */
        'mockup.title': 'Design',
        'mockup.lead': 'The nine artboards this console was drawn as.',
        'mockup.frozen':
            'Frozen as it was published. Where the running console differs, the console is the newer answer — a drawing has no data to be wrong about.',
        'mockup.russian':
            'The design is written in Russian, the language it was argued in.',
        'mockup.oldName':
            'The artboards still say “Мостик” — the name this console carried until it was pointed out that the site already has a bridge. A design is a record of a decision; a record kept current is not a record.',
        'mockup.screens': 'Artboards',
        'mockup.why': 'Why it is shaped this way',
        'mockup.canvas': 'Open the canvas',
        'mockup.separately': 'Open separately',
        'mockup.fit': 'Fit width',
        'mockup.actual': 'Actual size',
        'mockup.size': '{width}×{height}',
        'mockup.scale': '{percent}% of actual size',
        'mockup.empty': 'The artboards are not on this server.',

        /* units */
        'unit.minute': 'minute|minutes|minutes',
        'unit.hour': 'hour|hours|hours',
        'unit.day': 'day|days|days',
        'unit.week': 'week|weeks|weeks',
        'unit.second': 'second|seconds|seconds',
        'unit.now': 'just now',
        'unit.never': 'never',
        'unit.none': '—',
    },
    ru: {
        /* chrome */
        'nav.now': 'Сейчас',
        'nav.people': 'Люди',
        'nav.tasks': 'Задачи',
        'nav.numbers': 'Числа',
        'nav.machines': 'Машины',
        'nav.mockup': 'Макет',
        'group.chain': 'Цепь',
        'group.web': 'Веб',
        'group.infra': 'Инфра',
        'group.daemon': 'Демоны',
        'group.onchain': 'Он-чейн',
        'group.product': 'Продукт',
        'top.fundedActive': 'Активные с деньгами',
        'top.installs': 'Установки 30 д',
        'top.bridge': 'Мост 30 д',
        'top.sweep': 'обход {time}',
        'top.noSweep': 'обхода не было',
        'top.allGood': 'Всё на месте',
        'top.shift': 'смена: {name}',

        /* the queue */
        'feed.attention': 'Требует действия',
        'feed.watch': 'Наблюдение',
        'feed.watchNote': 'действий не требует, но пусть будет на глазах',
        'feed.background': 'Фон',
        'feed.backgroundNote': 'тридцать дней',
        'feed.incident.down': '{service} упал',
        'feed.incident.degraded': '{service} деградирует',
        'feed.incident.body':
            '{reason} · инцидент открыт после двух неудач подряд.',
        'feed.task.title': 'Задача просрочена',
        'feed.task.body': '«{task}» · {assignee} · {priority}',
        'feed.whale.title': 'Кит пришёл впервые',
        'feed.whale.body':
            '{name} · {cyber} CYBER и {cyberSol} CYBER.sol · синк впервые увидел его на этом пороге.',
        'feed.gas.low': 'Бак газовой станции подходит к концу',
        'feed.gas.empty': 'Бак газовой станции пуст',
        'feed.gas.body':
            '{tank} CYBER — примерно {drips} выдач при дневном лимите {dailyCap}. Пустой бак не ломает кошелёк, но тихо выключает первый платёж новичка.',
        'feed.retention.title': 'Удержание D7 упало с {before}% до {now}%',
        'feed.retention.body': 'Когорта {cohort}, {size} установок.',
        'feed.bridge.title': 'Выплата моста не прошла',
        'feed.bridge.body':
            'Заявок за сутки: {count} · последняя {amount} {token}, {direction}. Таймаут на медленной цепи бывает ложным отказом — проверьте цепь назначения до повтора.',

        /* evidence and actions */
        'evidence.day': 'сутки',
        'evidence.dueWas': 'срок был',
        'evidence.holdings': 'на двух цепях',
        'evidence.noPrice': 'цена не читается',
        'evidence.tank': 'остаток',
        'evidence.d7': 'D7 по когортам',
        'evidence.failedRequests': 'не прошли',
        'action.openMachine': 'Открыть машину',
        'action.openTask': 'Открыть задачу',
        'action.openPerson': 'В досье',
        'action.topUpTank': 'Пополнить',
        'action.investigate': 'Разобрать',
        'action.openBridge': 'Открыть мост',
        'action.snooze': 'Отложить',
        'action.wake': 'Вернуть',
        'action.wholeFeed': 'Вся лента',
        'action.snoozedUntil': 'до {time}',

        /* watch list */
        'watch.bridge.title': 'Мост: {count} заявок ждут',
        'watch.bridge.body':
            '— дольше {minutes} минут; релеер жив и платит, очередь просто длинная',
        'watch.host.title': '{host} молчит {minutes} мин',
        'watch.host.body':
            '— всё, о чём он отчитывался, встало в «неизвестно»: это состояние отчётчика, а не сервисов',
        'watch.campaign.title': 'Лучший источник недели: {source}',
        'watch.campaign.body':
            '— {campaign} · {users} установок · в активацию {rate}%',
        'watch.snoozed.title': 'Отложено: {count}',
        'watch.snoozed.body': '— {items}',

        /* silence */
        'quiet.title': 'Тихо',
        'quiet.body':
            'Ничего не требует вас. Пустой экран здесь означает именно это, а не сломанный сбор данных: обход прошёл {ago} и на него ответили {answered} сервисов из {registered}.',
        'quiet.duration': 'Тишина длится',
        'quiet.sinceUnknown': 'закрытых инцидентов ещё не было',

        /* background tiles */
        'tiles.funded_active': 'Активные с деньгами',
        'tiles.installs': 'Новые установки',
        'tiles.swaps': 'Объём свопов',
        'tiles.bridge': 'Переводов мостом',
        'tiles.services': 'Сервисы',
        'tiles.tasks': 'Открытых задач',
        'tiles.fundedActive.note': 'с деньгами и действием за {days} дней',
        'tiles.installs.delta': '{delta}% к прошлому окну',
        'tiles.installs.note': 'первое окно',
        'tiles.swaps.chain': '{swaps} свопов на цепи',
        'tiles.swaps.wallet': 'по событиям кошелька',
        'tiles.bridge.note': 'выполнено · {failed} не прошли',
        'tiles.services.note':
            '{down} упало · {degraded} деградируют · {unknown} молчат',
        'tiles.tasks.note': '{overdue} просрочены · {unassigned} ничьи',

        /* people */
        'people.title': 'Люди',
        'people.segments': 'Сегменты',
        'people.happening': 'Что с ними происходит',
        'people.sortNote': 'сортировка по свежести сигнала',
        'people.segmentNote':
            'Сегмент — сохранённый вопрос, а не набор галочек. Правило видно при наведении.',
        'people.shown': '{shown} из {total}',
        'people.rest': 'дальше те, у кого за месяц ничего не менялось',
        'people.more': 'Показать ещё',
        'people.search': 'имя, адрес, телеграм…',
        'people.sync': 'Синхронизировать',
        'people.export': 'Экспорт',
        'people.add': '+ Человек',
        'people.empty': 'На этот вопрос сейчас никто не отвечает.',
        'segment.all': 'Все люди',
        'segment.whales': 'Киты',
        'segment.new_whales': 'Новые киты за месяц',
        'segment.awaiting': 'Ждут нашего ответа',
        'segment.silent_customers': 'Замолчавшие клиенты',
        'segment.one_and_done': 'Ушли после первой сделки',
        'segment.cold_leads': 'Лиды без единого ответа',
        'segment.solana_only': 'Только Solana',
        'rule.all': 'все контакты в базе',
        'rule.whales': 'тип = кит (ставит синк по балансам)',
        'rule.new_whales': 'тип = кит, появился за последние 30 дней',
        'rule.awaiting': 'есть незакрытая задача с нашей стороны',
        'rule.silent_customers': 'клиент без единой записи {days} дней',
        'rule.one_and_done':
            'квалифицирован или клиент, ни одной заметки, старше {days} дней',
        'rule.cold_leads': 'лид со статусом «новый» и без единой заметки',
        'rule.solana_only': 'есть адрес Solana и нет адреса EVM',
        'crm.type.lead': 'лид',
        'crm.type.holder': 'держатель',
        'crm.type.whale': 'кит',
        'crm.status.new': 'новый',
        'crm.status.contacted': 'связались',
        'crm.status.qualified': 'квалифицирован',
        'crm.status.customer': 'клиент',
        'crm.status.lost': 'потерян',
        'crm.source.manual': 'добавлен руками',
        'crm.source.platform': 'сайта',
        'crm.source.bridge': 'моста',
        'crm.source.whale_bot': 'китобота',

        /* people signals */
        'signal.note': 'Написали: {body}',
        'signal.taskOpen': 'Открытое обещание: {task}',
        'signal.taskOverdue': 'Просроченное обещание: {task} (срок был {due})',
        'signal.moneyOut': 'Вывел {amount} {token} через мост',
        'signal.moneyIn': 'Ввёл {amount} {token} через мост',
        'signal.becameWhale': 'Перешёл порог кита',
        'signal.appeared': 'Появился, источник {source}',
        'signal.nothing': 'Ничего не записано',
        'signal.silent': 'Молчит {days} дней',
        'signal.ago': '{ago} назад',

        /* person */
        'person.back': 'Люди',
        'person.write': 'Написать',
        'person.addTask': '+ Задача',
        'person.delete': 'Удалить',
        'person.money': 'Деньги',
        'person.activity': 'Активность, 12 недель',
        'person.activityNote':
            'переводы по неделям — истории балансов это приложение не хранит',
        'person.noActivity': 'за двенадцать недель переводов не записано',
        'person.who': 'Кто это',
        'person.next': 'Что дальше',
        'person.overdueCount': '{count} просрочена',
        'person.everything': 'Всё, что было',
        'person.everythingNote':
            'визиты, переводы, наши письма и заметки — одной лентой',
        'person.evm': 'EVM',
        'person.solana': 'Solana',
        'person.telegram': 'Телеграм',
        'person.email': 'Почта',
        'person.tags': 'Метки',
        'person.source': 'Пришёл из',
        'person.since': 'С нами с',
        'person.lastSync': 'Последний синк',
        'person.none': 'не оставил',
        'person.noTasks': 'ничего не обещано',
        'person.noHistory': 'записей пока нет',
        'person.summary.overdue':
            'С нами с {since}, пришёл из {source}. За нами {open} обещаний, из них {overdue} просрочено.',
        'person.summary.moved':
            'С нами с {since}, пришёл из {source}. Последнее движение: {amount} {token}, {when}; за двенадцать недель переводов — {transfers}.',
        'person.summary.talked':
            'С нами с {since}, пришёл из {source}. Последний разговор {lastNote}, обещаний за нами нет.',
        'person.summary.quiet':
            'С нами с {since}, пришёл из {source}. Кроме синка о нём ничего не записано.',
        'person.event.note': 'Заметка, {author}',
        'person.event.task': 'Задача поставлена на {assignee}',
        'person.event.taskDone': 'Задача закрыта, {assignee}',
        'person.event.bridgeOut': 'Вывод через мост · {direction} · {status}',
        'person.event.bridgeIn': 'Ввод через мост · {direction} · {status}',
        'person.event.appeared': 'Появился в базе, источник {source}',
        'person.event.page_view': 'Открыл {page}',
        'person.event.landing_view': 'Открыл лендинг',
        'person.event.wallet_connected': 'Подключил кошелёк',
        'person.event.swap_completed': 'Своп',
        'person.event.liquidity_added': 'Добавил ликвидность',
        'person.addNote': 'Заметка…',
        'person.saveNote': 'Сохранить',

        /* tasks */
        'tasks.title': 'Задачи',
        'tasks.stats':
            '{open} открытых · {overdue} просрочены · {unowned} без исполнителя',
        'tasks.done': 'Сделанные',
        'tasks.quickAdd':
            'написать киту про лимиты моста @lain !завтра #Nakamoto',
        'tasks.quickAddHint':
            '@ исполнитель · ! срок · # человек — разбирается на лету, мышь не нужна',
        'tasks.unowned': 'Ничьи',
        'tasks.unownedNote':
            'никто не возьмёт сам — это состояние, а не строчка в списке',
        'tasks.claim': 'Взять',
        'tasks.overdue': 'Просрочено',
        'tasks.soon': 'Сегодня и завтра',
        'tasks.later': 'Дальше',
        'tasks.noDue': 'без срока',
        'tasks.today': 'сегодня',
        'tasks.tomorrow': 'завтра',
        'tasks.nobody': 'ничья',
        'tasks.footer':
            'За неделю закрыто {closed} · среднее время от постановки до закрытия {median} дня',
        'tasks.footerEmpty': 'за неделю ничего не закрыто',
        'tasks.empty': 'пусто',
        'tasks.done.action': 'Готово',
        'tasks.journal.open': 'Открыть журнал закрытых задач',
        'tasks.journal.title': 'Закрытые задачи',
        'tasks.journal.empty': 'Закрытых задач пока нет',
        'priority.low': 'низкий',
        'priority.normal': 'обычный',
        'priority.high': 'высокий',

        /* numbers */
        'numbers.title': 'Числа',
        'subject.installs': 'Установки кошелька',
        'subject.sessions': 'Сессии сайта',
        'numbers.subjectNote':
            'Кто считается — переключатель, а не две разные страницы: путаница между установкой и сессией стоила нам квартала споров.',
        'numbers.growth.title': 'Растём ли мы?',
        'numbers.growth.suffix': 'новых установки',
        'numbers.growth.suffixSessions': 'сессий',
        'numbers.growth.opened': 'открыли',
        'numbers.growth.acted': 'сделали подтверждённое действие',
        'numbers.growth.sessions': 'сессии',
        'numbers.growth.delta':
            '{delta}% к прошлому окну. Крупнейший источник: {source} / {campaign}, {sourceUsers} установок; без него окно — {without}.',
        'numbers.growth.deltaSessions': '{delta}% к прошлому окну.',
        'numbers.growth.first':
            'Первое окно в записи — сравнивать пока не с чем.',
        'numbers.money.title': 'Доходят ли до денег?',
        'numbers.money.suffix': '% дошли до первой транзакции',
        'numbers.money.conclusion':
            '{drop} из {wallets} создавших кошелёк не пополнили его. Это самый дорогой шаг, и он не про интерфейс: деньги должны прийти извне.',
        'numbers.money.sessions':
            'Кошелёк подключили {wallets} сессий из {visitors}.',
        'numbers.money.note':
            'Справа — доля от предыдущего шага: она показывает, где именно теряем, а не насколько всё плохо в целом.',
        'numbers.caveat.internal':
            'Не учтено: {count} наших собственных установок. Мы пользуемся этим кошельком больше всех, и доля, включающая нас, описывает наше тестирование.',
        'numbers.caveat.internalSessions':
            'Не учтено: {count} наших собственных сессий. Двое операторов дали большую часть всех записанных здесь конверсий.',
        'numbers.caveat.internalIncluded':
            'Наши собственные установки и сессии включены в эти числа.',
        'numbers.caveat.notional':
            'Сделок вне объёма: {trades} на номинальные ${usd}. Их собственное влияние на цену говорит, что это опустошение пула, а не оборот.',
        'numbers.caveat.bridgeUnfiltered':
            'Шаг моста всё ещё считает наши сессии: у событий моста свои идентификаторы сессий, сопоставить их с сайтовыми нельзя.',
        'numbers.caveat.bridgeAndInternal':
            'Наших сессий не учтено в шагах выше: {count}. Но они учтены в шаге моста — а это и есть число слева: у событий моста свои идентификаторы сессий, сопоставить их с сайтовыми нельзя.',
        'numbers.return.title': 'Возвращаются ли?',
        'numbers.return.suffix': '% D7, было {before}',
        'numbers.return.drop': 'D7 упал с {before}% до {now}%.',
        'numbers.return.dropVersion':
            'D7 упал с {before}% до {now}%, и падение целиком на {worst} ({worstRate}%) — на {best} держится {bestRate}%. Это регресс сборки, а не рынок: задача разработчику, а не маркетингу.',
        'numbers.return.steady': 'D7 держится на {now}%, было {before}%.',
        'numbers.return.young':
            'Две зрелые когорты ещё не набрались — рано прочитанное удержание умеет только расти.',
        'numbers.return.sessions':
            'Сессии по неделе первого визита. Когорт в записи: {weeks}.',
        'numbers.return.immature': 'не дозрела',
        'numbers.sources.suffix': 'по D7',
        'numbers.sources.title': 'Откуда приходят те, кто остаётся?',
        'numbers.sources.conclusion':
            'Сортировка по D7, а не по числу установок. {source} даёт {d7}% всего с {users} установок.',
        'numbers.sources.empty':
            'Ни у одного источника пока не хватает установок для сравнения.',
        'numbers.sources.unmeasured':
            'Для сессий не измеряется: сайт не записывает, откуда пришёл визит, а взять сюда ответ установок — значит подставить другой субъект под эту подпись.',
        'numbers.sources.unmeasuredNote':
            'источник визита на сайте не записывается',
        'numbers.breaks.title': 'Что ломается?',
        'numbers.breaks.suffix': '% транзакций доходят',
        'numbers.breaks.conclusion':
            '{failures} отказов из {attempts} попыток. Половина такого списка обычно — пустой газ и передумавшие люди, и это не сбои.',
        'numbers.breaks.sessions':
            'Не дошли {failed} заявок моста из {finished} завершённых.',
        'numbers.cost.title': 'Сколько стоит активированный?',
        'numbers.cost.suffix': 'за активацию',
        'numbers.cost.conclusion':
            '{drips} выдач на {addresses} адресов против {activated} активаций. Ограничитель здесь не деньги, а бак.',
        'numbers.cost.idle': 'В этом окне станция ничего не выдавала.',
        'numbers.cost.unmeasured':
            'Для сессий не измеряется: спонсирование платится за установку, и цена сессии была бы выдуманным числом.',
        'numbers.cost.unmeasuredNote':
            'в журнале выплат установки, а не сессии',
        'numbers.cost.drips': 'Выдач',
        'numbers.cost.spent': 'Всего потрачено',
        'numbers.cost.perAddress': 'На адрес',
        'numbers.cost.perActivation': 'На активацию',
        'numbers.cost.refused': 'Отказов',
        'numbers.cost.addresses': '{addresses} адресов',
        'numbers.cost.fromLedger': 'из журнала выплат',
        'numbers.cost.fixedDrip': 'фиксированная капля',
        'numbers.cost.refusedNote': 'отказано по границам самой станции',
        'numbers.cost.ratio': '{drips} выдач → {activated} активаций',
        'numbers.cost.ledgerNote':
            'Сумма берётся из строк выплат, а не из событий браузера: событие можно повторить, строку — нет.',
        'numbers.empty': 'за это окно данных нет',
        'numbers.nothingBroke': 'за это окно ничего не падало',
        'numbers.window': 'окно',
        'numbers.cohort': 'Когорта',
        'numbers.week': 'Неделя',
        'numbers.source': 'Источник',
        'numbers.campaign': 'Кампания',
        'numbers.installs': 'Установки',
        'numbers.activation': 'Активация',
        'numbers.quality': 'Качество',
        'numbers.people': 'чел.',
        'numbers.step': 'Шаг',
        'numbers.status': 'Статус',
        'numbers.count': 'Сколько',
        'step.first_open': 'Открыли приложение',
        'step.wallet': 'Создали кошелёк',
        'step.funded': 'Пополнили',
        'step.activated': 'Первая транзакция',
        'step.retained': 'Вернулись через 7 дней',
        'step.visitors': 'Зашли на сайт',
        'step.wallet_connected': 'Подключили кошелёк',
        'step.swap': 'Своп',
        'step.liquidity': 'Добавили ликвидность',
        'step.bridge': 'Мост',

        /* machines */
        'machines.title': 'Машины',
        'machines.registry':
            '{total} программ в реестре · {down} упало · {degraded} деградируют · {unknown} молчат',
        'machines.registryNote':
            'Реестр — файл, не база: добавить сервис = правка config/monitoring.php',
        'machines.onlyProblems': 'Только проблемы',
        'machines.all': 'Всё',
        'machines.attention': 'Требует действия',
        'machines.hosts': 'Машины, которые всё это держат',
        'machines.hostsNote': 'пульс раз в минуту',
        'machines.load': 'Нагрузка',
        'machines.memory': 'Память',
        'machines.swap': 'Swap',
        'machines.disk': 'Диск',
        'machines.uptime': 'Аптайм',
        'machines.free': 'свободно',
        'machines.used': 'занято',
        'machines.ofCpus': 'из {cpus}',
        'machines.noReboot': 'без перезагрузок',
        'machines.ago': '{ago} назад',
        'machines.silent': 'молчит {ago}',
        'machines.silentNote':
            'Плитки выше стоят в штриховке из-за этого. Умер отчётчик, а не сервисы: инцидент по ним не открывается и в аптайм они не идут.',
        'machines.unregistered': 'Крутится, но не в реестре: {list}',
        'machines.idle': 'Никто не пользуется',
        'machines.idleNote':
            'Сюда попадает только измеримое. Ещё {count} программ помечены «не измеряется» — среди них RPC, эксплорер и DEX, и попади они в этот список, он рекомендовал бы удалить половину продукта.',
        'machines.idleMore': 'и ещё {count}',
        'machines.idleNever': 'не открывали ни разу',
        'machines.idleDays': 'никто {days} дней',
        'machines.incidents': 'Что ломалось',
        'machines.incidentsNote':
            'открывается после двух неудач подряд, закрывается само',
        'machines.ongoing': 'идёт',
        'machines.noIncidents': 'за окно хранения ничего не ломалось',
        'machines.notMeasured': 'не измеряется',
        'machines.off': 'выключен намеренно',
        'status.up': 'работает',
        'status.degraded': 'деградирует',
        'status.down': 'упал',
        'status.unknown': 'неизвестно',
        'status.off': 'выключен',
        'machines.critical': 'крит',

        /* install dossier */
        'install.back': 'Числа',
        'install.internal.tag': 'наша',
        'install.internal.mark': 'Это наша установка',
        'install.internal.unmark': 'Всё-таки не наша',
        'install.title': 'Установка {short}',
        'install.whereFrom': 'Откуда пришла',
        'install.firstRun': 'Первый запуск',
        'install.lastSeen': 'Последний раз',
        'install.platform': 'Платформа',
        'install.version': 'Версия',
        'install.language': 'Язык',
        'install.source': 'Источник',
        'install.campaign': 'Кампания',
        'install.referrer': 'Реферер',
        'install.landing': 'Точка входа',
        'install.sessions': 'Сессий',
        'install.addresses': 'Связанных адресов',
        'install.identityNote':
            'Идентификатор — анонимный, его выдаёт само приложение при первом запуске. Адрес кошелька сюда не попадает никогда: у одного человека их несколько, и счёт по адресам умножил бы каждого.',
        'install.whereStuck': 'Где стоит',
        'install.milestone.opened': 'Открыла приложение',
        'install.milestone.wallet': 'Создала кошелёк',
        'install.milestone.funded': 'Пополнила',
        'install.milestone.activated': 'Первая транзакция',
        'install.milestone.returned': 'Вернулась через 7 дней',
        'install.after': 'через {gap}',
        'install.waiting': 'ждём {gap}',
        'install.canDo': 'Что можно сделать',
        'install.peers':
            'Таких установок за месяц — {count}. Что сделано для них, сделано и для этой.',
        'install.timeline': 'Хронология',
        'install.timelineNote':
            'пишется только то, что перечислено в разрешённом списке',
        'install.milestoneTag': 'веха',
        'install.meaningfulNote':
            'Значимое — это подтверждённое сетью, а отправка в сеть подтверждением не считается. Поэтому незавершённая строка пуста, а не заполнена оптимистично.',
        'install.noEvents': 'событий пока нет',

        /* макет, из которого вырос этот пульт */
        'mockup.title': 'Макет',
        'mockup.lead': 'Девять экранов, которыми этот пульт был нарисован.',
        'mockup.frozen':
            'Заморожен на момент публикации. Там, где живая консоль расходится с макетом, права консоль: у рисунка нет данных, в которых можно ошибиться.',
        'mockup.russian':
            'Макет написан по-русски — на языке, на котором его обсуждали.',
        'mockup.oldName':
            'На экранах ещё написано «Мостик» — так консоль называлась, пока не выяснилось, что мост на сайте уже есть. Макет — запись принятого решения, а запись, которую подправляют, записью быть перестаёт.',
        'mockup.screens': 'Экраны',
        'mockup.why': 'Почему так',
        'mockup.canvas': 'Открыть холст',
        'mockup.separately': 'Открыть отдельно',
        'mockup.fit': 'По ширине',
        'mockup.actual': 'В натуральную величину',
        'mockup.size': '{width}×{height}',
        'mockup.scale': '{percent}% натуральной величины',
        'mockup.empty': 'Экранов макета нет на этом сервере.',

        /* units */
        'unit.minute': 'минута|минуты|минут',
        'unit.hour': 'час|часа|часов',
        'unit.day': 'день|дня|дней',
        'unit.week': 'неделя|недели|недель',
        'unit.second': 'секунда|секунды|секунд',
        'unit.now': 'только что',
        'unit.never': 'никогда',
        'unit.none': '—',
    },
};
