installPacketLogFilter();

const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const readline = require('node:readline');
const { DatabaseSync } = require('node:sqlite');
const mineflayer = require('mineflayer');
const {
  Movements,
  goals: { GoalBlock, GoalFollow, GoalNear, GoalGetToBlock },
  pathfinder,
} = require('mineflayer-pathfinder');

const DEFAULT_HOST = process.env.MC_HOST || 'play.akvarium228.ru';
const DEFAULT_PORT = Number(process.env.MC_PORT || 25565);
const DEFAULT_USERNAME = process.env.MC_USERNAME || 'lain';
const DEFAULT_AUTH = process.env.MC_AUTH || 'offline';
const DEFAULT_VERSION = process.env.MC_VERSION || false;
const DEFAULT_LOGIN_COMMAND = process.env.MC_LOGIN_COMMAND || '/l 4050303';
const DEFAULT_LOGIN_DELAY_MS = Number(process.env.MC_LOGIN_DELAY_MS || 1500);
const DEFAULT_OWNER = process.env.MC_CONTROL_OWNER || '';
const DEFAULT_ALLOW_PUBLIC_CONTROL = process.env.MC_ALLOW_PUBLIC_CONTROL === 'true';

const RECONNECT_DELAY_MS = Number(process.env.MC_RECONNECT_DELAY_MS || 10000);
const IDLE_INTERVAL_MS = Number(process.env.MC_IDLE_INTERVAL_MS || 6500);
const CHAT_CONTROL_PREFIX = process.env.MC_CONTROL_PREFIX || '!bot';
const VERBOSE_ERRORS = process.env.MC_VERBOSE_ERRORS === 'true';
const CHAT_DEBUG = process.env.MC_CHAT_DEBUG === 'true';
const ALLOW_DIG = process.env.MC_ALLOW_DIG === 'true';
const CHEST_SEARCH_RADIUS = Number(process.env.MC_CHEST_RADIUS || 32);
const DEPOSIT_TIMEOUT_MS = Number(process.env.MC_DEPOSIT_TIMEOUT_MS || 120000);
const DELIVERY_RANGE = Number(process.env.MC_DELIVERY_RANGE || 48);
const CHAT_QUEUE_DELAY_MS = Number(process.env.MC_CHAT_DELAY_MS || 1200);
const DB_PATH = process.env.MC_DB_PATH || path.join(__dirname, 'minecraft-bot.sqlite');
// Old JSON ledger; imported into SQLite once, then renamed to *.imported.
const LEDGER_PATH = process.env.MC_LEDGER_PATH || path.join(__dirname, 'minecraft-ledger.json');
const SERVERS_FILE = process.env.MC_SERVERS_FILE || path.join(__dirname, 'minecraft-servers.json');

// Commands any player may use in chat; everything else needs the owner.
const PUBLIC_COMMANDS = new Set(['help', 'deposit', 'done', 'cancel', 'balance', 'withdraw', 'stock', 'wallet']);

let shuttingDown = false;

function installPacketLogFilter() {
  if (process.env.MC_VERBOSE_ERRORS === 'true') return;

  const originalLog = console.log.bind(console);
  const originalError = console.error.bind(console);
  let hiddenPacketLogs = 0;
  let lastReportAt = 0;

  function shouldHide(args) {
    const message = args.map((arg) => {
      if (typeof arg === 'string') return arg;
      if (arg instanceof Error) return arg.stack || arg.message;
      return String(arg);
    }).join(' ');

    return (
      message.includes('partial packet') ||
      message.includes('buffer :') ||
      message.includes('PartialReadError') ||
      message.startsWith('Chunk size is ')
    );
  }

  function reportHiddenPacketLog() {
    hiddenPacketLogs += 1;

    const now = Date.now();
    if (now - lastReportAt < 30000) return;

    originalLog(`Hidden ${hiddenPacketLogs} packet parser log(s). Set MC_VERBOSE_ERRORS=true to print them.`);
    hiddenPacketLogs = 0;
    lastReportAt = now;
  }

  console.log = (...args) => {
    if (shouldHide(args)) {
      reportHiddenPacketLog();
      return;
    }

    originalLog(...args);
  };

  console.error = (...args) => {
    if (shouldHide(args)) {
      reportHiddenPacketLog();
      return;
    }

    originalError(...args);
  };
}

function loadServerConfigs() {
  let raw = null;

  if (fs.existsSync(SERVERS_FILE)) {
    raw = JSON.parse(fs.readFileSync(SERVERS_FILE, 'utf8'));
    console.log(`Loaded server list from ${SERVERS_FILE}`);
  } else if (process.env.MC_SERVERS) {
    raw = JSON.parse(process.env.MC_SERVERS);
    console.log('Loaded server list from MC_SERVERS env');
  }

  if (!raw) {
    raw = [{ host: DEFAULT_HOST, port: DEFAULT_PORT }];
    console.log('No server list found, using single legacy server from env');
  }

  if (!Array.isArray(raw) || raw.length === 0) {
    throw new Error('Server list must be a non-empty JSON array');
  }

  const seen = new Set();

  return raw.map((entry, index) => {
    const host = entry.host || DEFAULT_HOST;
    const port = Number(entry.port || DEFAULT_PORT);
    const id = String(entry.id || `${host}:${port}`);

    if (seen.has(id)) {
      throw new Error(`Duplicate server id "${id}" — ledger stock is keyed by id, ids must be unique`);
    }
    seen.add(id);

    return {
      id,
      host,
      port,
      username: entry.username || DEFAULT_USERNAME,
      auth: entry.auth || DEFAULT_AUTH,
      version: entry.version || DEFAULT_VERSION,
      loginCommand: entry.loginCommand !== undefined ? entry.loginCommand : (index === 0 ? DEFAULT_LOGIN_COMMAND : ''),
      loginDelayMs: Number(entry.loginDelayMs || DEFAULT_LOGIN_DELAY_MS),
      owner: entry.owner || DEFAULT_OWNER,
      allowPublicControl: entry.allowPublicControl !== undefined ? Boolean(entry.allowPublicControl) : DEFAULT_ALLOW_PUBLIC_CONTROL,
    };
  });
}

// Shared cross-server storage (SQLite). Balances are global per player (this
// is what gets tokenized later); stock tracks the physical items the bot holds
// on each server (ender chest or its own inventory), so withdrawals are only
// possible where items exist. Wallets map players to Cyberia addresses for
// future on-chain payouts.
class Ledger {
  constructor(dbPath, legacyJsonPath) {
    this.db = new DatabaseSync(dbPath);
    this.db.exec('PRAGMA journal_mode = WAL;');
    this.db.exec(`
      CREATE TABLE IF NOT EXISTS transactions (
        id TEXT PRIMARY KEY,
        at TEXT NOT NULL,
        type TEXT NOT NULL,
        server TEXT NOT NULL,
        player TEXT NOT NULL,
        player_key TEXT NOT NULL,
        item TEXT NOT NULL,
        count INTEGER NOT NULL
      );
      CREATE TABLE IF NOT EXISTS balances (
        player_key TEXT NOT NULL,
        item TEXT NOT NULL,
        count INTEGER NOT NULL,
        PRIMARY KEY (player_key, item)
      );
      CREATE TABLE IF NOT EXISTS stock (
        server TEXT NOT NULL,
        item TEXT NOT NULL,
        count INTEGER NOT NULL,
        PRIMARY KEY (server, item)
      );
      CREATE TABLE IF NOT EXISTS wallets (
        player_key TEXT PRIMARY KEY,
        player TEXT NOT NULL,
        address TEXT NOT NULL,
        server TEXT NOT NULL,
        linked_at TEXT NOT NULL
      );
    `);

    this.importLegacyJson(legacyJsonPath);
  }

  importLegacyJson(jsonPath) {
    if (!jsonPath || !fs.existsSync(jsonPath)) return;

    const hasData = this.db.prepare('SELECT COUNT(*) AS n FROM transactions').get().n > 0;
    if (hasData) return;

    let parsed;
    try {
      parsed = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
    } catch (err) {
      console.error(`Failed to parse legacy ledger ${jsonPath}: ${err.message}`);
      return;
    }

    this.db.exec('BEGIN');
    try {
      const insertTx = this.db.prepare(
        'INSERT OR IGNORE INTO transactions (id, at, type, server, player, player_key, item, count) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
      );
      for (const tx of parsed.transactions || []) {
        insertTx.run(tx.id, tx.at, tx.type, tx.server, tx.player, tx.player.toLowerCase(), tx.item, tx.count);
      }

      const insertBalance = this.db.prepare('INSERT INTO balances (player_key, item, count) VALUES (?, ?, ?)');
      for (const [playerKey, items] of Object.entries(parsed.balances || {})) {
        for (const [item, count] of Object.entries(items)) {
          if (count > 0) insertBalance.run(playerKey, item, count);
        }
      }

      const insertStock = this.db.prepare('INSERT INTO stock (server, item, count) VALUES (?, ?, ?)');
      for (const [server, items] of Object.entries(parsed.stock || {})) {
        for (const [item, count] of Object.entries(items)) {
          if (count > 0) insertStock.run(server, item, count);
        }
      }

      this.db.exec('COMMIT');
    } catch (err) {
      this.db.exec('ROLLBACK');
      console.error(`Legacy ledger import failed: ${err.message}`);
      return;
    }

    fs.renameSync(jsonPath, `${jsonPath}.imported`);
    console.log(`Imported legacy JSON ledger into SQLite; old file renamed to ${jsonPath}.imported`);
  }

  balance(player, itemName) {
    const row = this.db.prepare('SELECT count FROM balances WHERE player_key = ? AND item = ?')
      .get(player.toLowerCase(), itemName);
    return row?.count || 0;
  }

  balancesOf(player) {
    const rows = this.db.prepare('SELECT item, count FROM balances WHERE player_key = ? AND count > 0 ORDER BY count DESC')
      .all(player.toLowerCase());
    return Object.fromEntries(rows.map((row) => [row.item, row.count]));
  }

  stockOf(serverId) {
    const rows = this.db.prepare('SELECT item, count FROM stock WHERE server = ? AND count > 0 ORDER BY count DESC')
      .all(serverId);
    return Object.fromEntries(rows.map((row) => [row.item, row.count]));
  }

  stockOn(serverId, itemName) {
    const row = this.db.prepare('SELECT count FROM stock WHERE server = ? AND item = ?').get(serverId, itemName);
    return row?.count || 0;
  }

  serversWithStock(itemName, excludeServerId) {
    return this.db.prepare('SELECT server AS serverId, count FROM stock WHERE item = ? AND server != ? AND count > 0')
      .all(itemName, excludeServerId);
  }

  recordDeposit(serverId, player, itemName, count) {
    this.db.exec('BEGIN');
    try {
      this.db.prepare(`
        INSERT INTO balances (player_key, item, count) VALUES (?, ?, ?)
        ON CONFLICT(player_key, item) DO UPDATE SET count = count + excluded.count
      `).run(player.toLowerCase(), itemName, count);

      this.db.prepare(`
        INSERT INTO stock (server, item, count) VALUES (?, ?, ?)
        ON CONFLICT(server, item) DO UPDATE SET count = count + excluded.count
      `).run(serverId, itemName, count);

      const tx = this.insertTransaction('deposit', serverId, player, itemName, count);
      this.db.exec('COMMIT');
      return tx;
    } catch (err) {
      this.db.exec('ROLLBACK');
      throw err;
    }
  }

  recordWithdraw(serverId, player, itemName, count) {
    const key = player.toLowerCase();

    this.db.exec('BEGIN');
    try {
      this.db.prepare('UPDATE balances SET count = count - ? WHERE player_key = ? AND item = ?')
        .run(count, key, itemName);
      this.db.prepare('DELETE FROM balances WHERE player_key = ? AND item = ? AND count <= 0')
        .run(key, itemName);

      this.db.prepare('UPDATE stock SET count = count - ? WHERE server = ? AND item = ?')
        .run(count, serverId, itemName);
      this.db.prepare('DELETE FROM stock WHERE server = ? AND item = ? AND count <= 0')
        .run(serverId, itemName);

      const tx = this.insertTransaction('withdraw', serverId, player, itemName, count);
      this.db.exec('COMMIT');
      return tx;
    } catch (err) {
      this.db.exec('ROLLBACK');
      throw err;
    }
  }

  insertTransaction(type, serverId, player, itemName, count) {
    const tx = {
      id: crypto.randomUUID(),
      at: new Date().toISOString(),
      type,
      server: serverId,
      player,
      item: itemName,
      count,
    };

    this.db.prepare('INSERT INTO transactions (id, at, type, server, player, player_key, item, count) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
      .run(tx.id, tx.at, tx.type, tx.server, tx.player, player.toLowerCase(), tx.item, tx.count);
    return tx;
  }

  checkWithdraw(serverId, player, itemName, count) {
    const balance = this.balance(player, itemName);
    if (balance < count) {
      return { ok: false, reason: `На твоём балансе только ${balance} ${itemName}.` };
    }

    const stock = this.stockOn(serverId, itemName);
    if (stock < count) {
      const elsewhere = this.serversWithStock(itemName, serverId);
      const hint = elsewhere.length
        ? ` Есть на другом сервере: ${elsewhere.map((e) => `${e.serverId} (${e.count})`).join(', ')}.`
        : '';
      return { ok: false, reason: `На этом сервере в наличии только ${stock} ${itemName}.${hint}` };
    }

    return { ok: true };
  }

  walletOf(player) {
    return this.db.prepare('SELECT player, address, server, linked_at FROM wallets WHERE player_key = ?')
      .get(player.toLowerCase()) || null;
  }

  linkWallet(serverId, player, address) {
    this.db.prepare(`
      INSERT INTO wallets (player_key, player, address, server, linked_at) VALUES (?, ?, ?, ?, ?)
      ON CONFLICT(player_key) DO UPDATE SET
        player = excluded.player,
        address = excluded.address,
        server = excluded.server,
        linked_at = excluded.linked_at
    `).run(player.toLowerCase(), player, address, serverId, new Date().toISOString());
  }

  unlinkWallet(player) {
    const result = this.db.prepare('DELETE FROM wallets WHERE player_key = ?').run(player.toLowerCase());
    return result.changes > 0;
  }

  allWallets() {
    return this.db.prepare('SELECT player, address, server, linked_at FROM wallets ORDER BY player_key').all();
  }

  allBalances() {
    return this.db.prepare('SELECT player_key, item, count FROM balances WHERE count > 0 ORDER BY player_key, count DESC').all();
  }

  allStock() {
    return this.db.prepare('SELECT server, item, count FROM stock WHERE count > 0 ORDER BY server, count DESC').all();
  }

  recentTransactions(limit) {
    return this.db.prepare('SELECT * FROM transactions ORDER BY at DESC LIMIT ?').all(limit).reverse();
  }
}

function formatError(err) {
  const message = err?.message || String(err);

  if (VERBOSE_ERRORS) {
    return `Bot error: ${message}`;
  }

  if (message.includes('partial packet') || message.includes('buffer :')) {
    const summary = message.split(';')[0].trim();
    return `Bot packet parse error hidden: ${summary}. Set MC_VERBOSE_ERRORS=true to print full packet.`;
  }

  return `Bot error: ${message.split('\n')[0]}`;
}

function splitChatChunks(text, size = 240) {
  if (text.length <= size) return [text];

  const chunks = [];
  let rest = text;
  while (rest.length > size) {
    let cut = rest.lastIndexOf(' ', size);
    if (cut <= 0) cut = size;
    chunks.push(rest.slice(0, cut));
    rest = rest.slice(cut).trimStart();
  }
  if (rest) chunks.push(rest);
  return chunks;
}

function formatItemMap(items) {
  return Object.entries(items)
    .sort(([, a], [, b]) => b - a)
    .map(([name, count]) => `${name}: ${count}`)
    .join(', ');
}

function countInPlayerSection(window, itemId) {
  let total = 0;
  for (let slot = window.inventoryStart; slot < window.inventoryEnd; slot++) {
    const item = window.slots[slot];
    if (item && item.type === itemId) total += item.count;
  }
  return total;
}

class ServerBot {
  constructor(config, ledger) {
    this.config = config;
    this.id = config.id;
    this.ledger = ledger;

    this.bot = null;
    this.defaultMovements = null;
    this.idleTimer = null;
    this.reconnectTimer = null;
    this.mode = 'starting';

    // Deposit session: one player at a time hands items to the bot.
    this.session = null;
    // True while the bot is walking to / working with the ender chest.
    this.busy = false;

    this.chatQueue = [];
    this.chatTimer = null;

    // Dedupe between the vanilla 'chat' event and the raw-message fallback.
    this.recentCommands = new Map();
    // The bot's own replies mention the command prefix; skip their echo.
    this.recentOwnMessages = [];

    // Freeze detection: auth plugins cancel movement until /login succeeds,
    // which shows up as a stream of server position resets (forcedMove).
    this.forcedMoves = 0;
    this.lastLoginSentAt = 0;
    this.spawnedAt = 0;
    this.lastRawLine = '';
    this.lastRawLineAt = 0;
  }

  log(...args) {
    console.log(`[${this.id}]`, ...args);
  }

  connect() {
    if (shuttingDown) return;

    this.log(`Connecting ${this.config.username} to ${this.config.host}:${this.config.port}`);

    this.bot = mineflayer.createBot({
      host: this.config.host,
      port: this.config.port,
      username: this.config.username,
      auth: this.config.auth,
      version: this.config.version,
      hideErrors: !VERBOSE_ERRORS,
    });

    this.bot.loadPlugin(pathfinder);

    // Auth plugins can hold the player in a limbo world until /login,
    // so 'spawn' may never fire — send the login command right after the
    // connection is established instead.
    this.bot.once('login', () => {
      this.log(`Connected (version ${this.bot.version}), waiting to spawn`);

      if (this.config.loginCommand) {
        setTimeout(() => this.sendLoginCommand(), this.config.loginDelayMs);
      }
    });

    this.bot.once('spawn', () => {
      this.log(`Spawned as ${this.bot.username}`);
      this.spawnedAt = Date.now();
      this.defaultMovements = new Movements(this.bot);
      this.defaultMovements.canDig = ALLOW_DIG;
      this.bot.pathfinder.setMovements(this.defaultMovements);
      this.mode = 'idle';

      this.startIdleLoop();
    });

    this.bot.on('forcedMove', () => {
      this.forcedMoves += 1;
    });

    this.bot.on('chat', (username, message) => {
      if (username === this.bot?.username) return;
      this.log(`<${username}> ${message}`);
      this.handleChatMessage(username, message, 'chat');
    });

    this.bot.on('whisper', (username, message) => {
      if (username === this.bot?.username) return;
      this.log(`[whisper] <${username}> ${message}`);
      this.handleChatMessage(username, message, 'whisper');
    });

    // Custom chat plugins (ranks, prefixes) often don't match mineflayer's
    // vanilla chat patterns, so the 'chat' event never fires. Parse raw
    // server messages as a fallback.
    this.bot.on('messagestr', (text, position) => {
      // Log early server messages (auth plugin prompts and replies) even
      // without debug mode; action bars repeat, so dedupe identical lines.
      const joining = !this.spawnedAt || Date.now() - this.spawnedAt < 15000;
      if (CHAT_DEBUG || joining) {
        const now = Date.now();
        if (text !== this.lastRawLine || now - this.lastRawLineAt > 3000) {
          this.log(`[raw:${position}] ${text}`);
          this.lastRawLine = text;
          this.lastRawLineAt = now;
        }
      }
      if (position === 'game_info') return;
      this.handleRawMessage(text);
    });

    this.bot.on('playerCollect', (collector, collected) => {
      this.onPlayerCollect(collector, collected);
    });

    this.bot.on('kicked', (reason) => {
      this.log(`Kicked: ${reason}`);
    });

    this.bot.on('error', (err) => {
      console.error(`[${this.id}] ${formatError(err)}`);
    });

    this.bot.on('end', () => {
      this.stopIdleLoop();
      this.clearSession();
      this.recentCommands.clear();
      this.recentOwnMessages = [];
      this.forcedMoves = 0;
      this.spawnedAt = 0;
      this.chatQueue = [];
      if (this.chatTimer) {
        clearTimeout(this.chatTimer);
        this.chatTimer = null;
      }
      this.bot = null;
      this.defaultMovements = null;
      this.busy = false;
      this.mode = 'offline';
      this.log('Disconnected');

      if (shuttingDown) {
        onBotEnded();
        return;
      }

      this.reconnectTimer = setTimeout(() => {
        this.reconnectTimer = null;
        this.connect();
      }, RECONNECT_DELAY_MS);
    });
  }

  handleRawMessage(text) {
    if (!this.bot) return;

    const idx = text.indexOf(CHAT_CONTROL_PREFIX);
    if (idx === -1) return;

    const now = Date.now();
    if (this.recentOwnMessages.some((own) => now - own.at < 10000 && text.includes(own.text))) return;

    const username = this.guessUsername(text.slice(0, idx));
    if (!username) {
      this.log(`Saw "${CHAT_CONTROL_PREFIX}" but no online player name before it: ${text}`);
      return;
    }

    this.handleChatMessage(username, text.slice(idx).trim(), 'chat');
  }

  // Extract the sender from an arbitrary chat plugin format like
  // "[VIP] Vasya » message" by matching tokens against online players.
  guessUsername(beforeText) {
    if (!this.bot) return null;

    const tokens = beforeText.split(/[^0-9A-Za-z_]+/).filter(Boolean);
    for (let i = tokens.length - 1; i >= 0; i--) {
      const token = tokens[i].toLowerCase();
      if (token === this.bot.username.toLowerCase()) continue;

      const match = Object.keys(this.bot.players).find((name) => name.toLowerCase() === token);
      if (match) return match;
    }
    return null;
  }

  handleChatMessage(username, message, source) {
    if (!message.startsWith(CHAT_CONTROL_PREFIX)) return;

    const line = message.slice(CHAT_CONTROL_PREFIX.length).trim();

    const dedupeKey = `${username.toLowerCase()}|${line}`;
    const now = Date.now();
    if (now - (this.recentCommands.get(dedupeKey) || 0) < 1500) return;
    this.recentCommands.set(dedupeKey, now);
    if (this.recentCommands.size > 50) {
      for (const [key, at] of this.recentCommands) {
        if (now - at > 5000) this.recentCommands.delete(key);
      }
    }

    const command = line.split(/\s+/)[0]?.toLowerCase() || '';
    const isOwner = this.isChatController(username);

    if (!isOwner && command && !PUBLIC_COMMANDS.has(command)) return;

    const reply = source === 'whisper'
      ? (text) => this.say(text, { whisperTo: username })
      : (text) => this.say(text);

    // Bare "!bot" shows help.
    this.runCommand(line || 'help', { source, requester: username, isOwner, reply });
  }

  isChatController(username) {
    if (this.config.allowPublicControl) return true;
    if (!this.config.owner) return false;

    return username.toLowerCase() === this.config.owner.toLowerCase();
  }

  sendLoginCommand() {
    if (!this.bot || !this.config.loginCommand) return;

    this.lastLoginSentAt = Date.now();
    this.bot.chat(this.config.loginCommand);
    this.log('Sent login command');
  }

  say(text, { whisperTo = null } = {}) {
    if (!this.bot) return;

    for (const chunk of splitChatChunks(text)) {
      this.chatQueue.push({ text: chunk, whisperTo });
    }
    this.drainChatQueue();
  }

  drainChatQueue() {
    if (this.chatTimer || !this.bot) return;

    const next = this.chatQueue.shift();
    if (!next) return;

    this.recordOwnMessage(next.text);
    if (next.whisperTo) {
      this.bot.whisper(next.whisperTo, next.text);
    } else {
      this.bot.chat(next.text);
    }

    this.chatTimer = setTimeout(() => {
      this.chatTimer = null;
      this.drainChatQueue();
    }, CHAT_QUEUE_DELAY_MS);
  }

  recordOwnMessage(text) {
    this.recentOwnMessages.push({ text, at: Date.now() });
    if (this.recentOwnMessages.length > 20) this.recentOwnMessages.shift();
  }

  startIdleLoop() {
    this.stopIdleLoop();

    this.idleTimer = setInterval(() => {
      if (!this.bot?.entity || this.mode !== 'idle' || this.busy || this.session) return;

      // A burst of server position resets means our movement is being
      // cancelled — the usual cause is a pending /login or captcha.
      const forcedMoves = this.forcedMoves;
      this.forcedMoves = 0;
      if (forcedMoves >= 8) {
        this.log(`Server reset bot position ${forcedMoves} time(s) — movement is blocked (login/captcha/anticheat?)`);
        if (this.config.loginCommand && Date.now() - this.lastLoginSentAt > 30000) {
          this.sendLoginCommand();
        }
        return;
      }

      if (this.bot.pathfinder.isMoving()) return;

      const nearbyPlayer = this.bot.nearestEntity((entity) => {
        return entity.type === 'player' && entity.username !== this.bot.username;
      });

      if (nearbyPlayer && this.bot.entity.position.distanceTo(nearbyPlayer.position) < 12) {
        this.lookAtEntity(nearbyPlayer);
        return;
      }

      this.randomIdleMove();
    }, IDLE_INTERVAL_MS);
  }

  stopIdleLoop() {
    if (!this.idleTimer) return;

    clearInterval(this.idleTimer);
    this.idleTimer = null;
  }

  randomIdleMove() {
    const controls = ['forward', 'back', 'left', 'right'];
    const control = controls[Math.floor(Math.random() * controls.length)];
    const duration = 600 + Math.floor(Math.random() * 900);

    this.stopControls();
    this.bot.setControlState(control, true);
    this.bot.setControlState('jump', Math.random() > 0.65);

    setTimeout(() => {
      if (!this.bot) return;
      this.bot.setControlState(control, false);
      this.bot.setControlState('jump', false);
    }, duration);
  }

  stopControls() {
    if (!this.bot) return;

    for (const control of ['forward', 'back', 'left', 'right', 'jump', 'sprint', 'sneak']) {
      this.bot.setControlState(control, false);
    }
  }

  lookAtEntity(entity) {
    if (!this.bot || !entity) return;

    const height = entity.height || 1.6;
    this.bot.lookAt(entity.position.offset(0, height, 0), true);
  }

  requireBot(reply = null) {
    if (this.bot?.entity) return true;

    (reply || ((text) => this.log(text)))('Bot is not spawned yet');
    return false;
  }

  getPlayerEntity(name) {
    if (!this.bot) return null;

    const player = Object.values(this.bot.players).find(
      (p) => p.username.toLowerCase() === name.toLowerCase(),
    );
    return player?.entity || null;
  }

  resolveItem(rawName) {
    if (!this.bot || !rawName) return null;

    const clean = rawName.toLowerCase().replace(/^minecraft:/, '');
    return this.bot.registry.itemsByName[clean] || null;
  }

  findEnderChest() {
    if (!this.bot) return null;

    const enderChestId = this.bot.registry.blocksByName.ender_chest?.id;
    if (enderChestId === undefined) return null;

    return this.bot.findBlock({ matching: enderChestId, maxDistance: CHEST_SEARCH_RADIUS });
  }

  async gotoBlock(position) {
    await this.bot.pathfinder.goto(new GoalGetToBlock(position.x, position.y, position.z));
  }

  async openEnderChest() {
    const chestBlock = this.findEnderChest();
    if (!chestBlock) return null;

    await this.gotoBlock(chestBlock.position);

    const freshBlock = this.bot.blockAt(chestBlock.position);
    if (!freshBlock || freshBlock.name !== 'ender_chest') return null;

    return this.bot.openContainer(freshBlock);
  }

  // ---------- Deposit flow ----------

  onPlayerCollect(collector, collected) {
    if (!this.session || !this.bot?.entity) return;
    if (collector.id !== this.bot.entity.id) return;

    let item = null;
    try {
      item = collected.getDroppedItem();
    } catch {
      return;
    }
    if (!item) return;

    const previous = this.session.items.get(item.name) || 0;
    this.session.items.set(item.name, previous + item.count);
    this.log(`Collected ${item.count} ${item.name} for ${this.session.player}`);
  }

  startDeposit(username, reply) {
    if (!this.requireBot(reply)) return;

    if (this.busy) {
      reply('Я занят другой операцией, подожди немного.');
      return;
    }

    if (this.session) {
      if (this.session.playerKey === username.toLowerCase()) {
        reply('Депозит уже открыт — бросай предметы, потом напиши "' + CHAT_CONTROL_PREFIX + ' done".');
      } else {
        reply(`Сейчас сдаёт ${this.session.player}, подожди своей очереди.`);
      }
      return;
    }

    const timer = setTimeout(() => {
      this.finishDeposit(null, { auto: true }).catch((err) => {
        this.log(`Auto deposit finish failed: ${err.message}`);
      });
    }, DEPOSIT_TIMEOUT_MS);

    this.session = {
      player: username,
      playerKey: username.toLowerCase(),
      items: new Map(),
      reply,
      timer,
    };

    this.mode = 'deposit';
    this.stopControls();

    const entity = this.getPlayerEntity(username);
    if (entity) {
      this.bot.pathfinder.setGoal(new GoalFollow(entity, 1.5), true);
    }

    reply(
      `Бросай мне предметы, ${username}. Когда закончишь — напиши "${CHAT_CONTROL_PREFIX} done" ` +
      `(отмена: "${CHAT_CONTROL_PREFIX} cancel", таймаут ${Math.round(DEPOSIT_TIMEOUT_MS / 1000)}с).`,
    );
  }

  async finishDeposit(requester, { auto = false } = {}) {
    const session = this.session;
    if (!session) return;

    if (requester && session.playerKey !== requester.toLowerCase()) return;

    clearTimeout(session.timer);
    this.session = null;
    this.bot?.pathfinder.setGoal(null);

    if (session.items.size === 0) {
      session.reply(auto ? 'Время вышло, я ничего не получил — депозит отменён.' : 'Я ничего не получил — депозит отменён.');
      this.mode = 'idle';
      return;
    }

    if (!this.requireBot(session.reply)) return;

    this.busy = true;
    this.mode = 'banking';

    try {
      // Ender chest is optional: when none is around (or it is full), the
      // bot keeps custody of the items in its own inventory. The ledger
      // only tracks per-server stock, not where it physically sits.
      const container = await this.openEnderChest();
      const stored = [];

      try {
        for (const [itemName, wanted] of session.items) {
          const item = this.bot.registry.itemsByName[itemName];
          if (!item) continue;

          const have = container
            ? countInPlayerSection(container, item.id)
            : this.bot.inventory.count(item.id, null);
          const amount = Math.min(wanted, have);
          if (amount <= 0) continue;

          if (container) {
            try {
              await container.deposit(item.id, null, amount);
            } catch (err) {
              this.log(`Deposit of ${amount} ${itemName} into chest incomplete (keeping in inventory): ${err.message}`);
            }
          }

          this.ledger.recordDeposit(this.id, session.player, itemName, amount);
          stored.push(`${amount} ${itemName}`);
        }
      } finally {
        if (container) container.close();
      }

      if (stored.length) {
        const where = container ? '' : ' (сундука рядом нет — храню при себе)';
        session.reply(`Принято от ${session.player}: ${stored.join(', ')}. Записал в реестр${where}.`);
      } else {
        session.reply('Не нашёл полученных предметов в инвентаре — депозит не записан.');
      }
    } catch (err) {
      this.log(`Deposit failed: ${err.message}`);
      session.reply('Что-то пошло не так при депозите — возвращаю предметы.');
      await this.returnItems(session.player, session.items).catch((returnErr) => {
        this.log(`Failed to return items: ${returnErr.message}`);
      });
    } finally {
      this.busy = false;
      this.mode = 'idle';
    }
  }

  async cancelDeposit(requester, reply) {
    const session = this.session;
    if (!session) {
      reply('Сейчас нет открытого депозита.');
      return;
    }

    if (requester && session.playerKey !== requester.toLowerCase() && !this.isChatController(requester)) {
      reply(`Депозит открыл ${session.player}, отменить может только он или владелец.`);
      return;
    }

    clearTimeout(session.timer);
    this.session = null;
    this.bot?.pathfinder.setGoal(null);
    this.mode = 'idle';

    if (session.items.size === 0) {
      reply('Депозит отменён.');
      return;
    }

    this.busy = true;
    try {
      reply('Депозит отменён, возвращаю предметы.');
      await this.returnItems(session.player, session.items);
    } catch (err) {
      this.log(`Failed to return items on cancel: ${err.message}`);
    } finally {
      this.busy = false;
    }
  }

  async returnItems(username, itemsMap) {
    if (!this.bot) return;

    const entity = this.getPlayerEntity(username);
    if (entity) {
      try {
        await this.bot.pathfinder.goto(new GoalNear(entity.position.x, entity.position.y, entity.position.z, 2));
      } catch (err) {
        this.log(`Could not reach ${username} to return items: ${err.message}`);
      }
      await this.bot.lookAt(entity.position.offset(0, 1.2, 0), true);
    }

    for (const [itemName, count] of itemsMap) {
      const item = this.bot.registry.itemsByName[itemName];
      if (!item) continue;

      const have = this.bot.inventory.count(item.id, null);
      const amount = Math.min(count, have);
      if (amount <= 0) continue;

      try {
        await this.bot.toss(item.id, null, amount);
      } catch (err) {
        this.log(`Failed to toss back ${amount} ${itemName}: ${err.message}`);
      }
    }
  }

  clearSession() {
    if (!this.session) return;

    clearTimeout(this.session.timer);
    this.session = null;
  }

  // ---------- Withdraw flow ----------

  async withdraw(username, rawItem, rawCount, reply) {
    if (!this.requireBot(reply)) return;

    if (this.busy || this.session) {
      reply('Я занят другой операцией, подожди немного.');
      return;
    }

    if (!rawItem) {
      reply(`Формат: ${CHAT_CONTROL_PREFIX} withdraw <item> [count|all], например "${CHAT_CONTROL_PREFIX} withdraw diamond 5".`);
      return;
    }

    const item = this.resolveItem(rawItem);
    if (!item) {
      reply(`Не знаю предмет "${rawItem}" — используй английский id, например diamond или iron_ingot.`);
      return;
    }

    let count;
    if (rawCount === 'all') {
      count = Math.min(this.ledger.balance(username, item.name), this.ledger.stockOn(this.id, item.name));
      if (count <= 0) {
        const check = this.ledger.checkWithdraw(this.id, username, item.name, 1);
        reply(check.ok ? `Нечего выдавать: баланс ${item.name} пуст.` : check.reason);
        return;
      }
    } else {
      count = rawCount === undefined ? 1 : Number.parseInt(rawCount, 10);
      if (!Number.isFinite(count) || count <= 0) {
        reply('Количество должно быть положительным числом или "all".');
        return;
      }
    }

    const check = this.ledger.checkWithdraw(this.id, username, item.name, count);
    if (!check.ok) {
      reply(check.reason);
      return;
    }

    const playerEntity = this.getPlayerEntity(username);
    if (!playerEntity || this.bot.entity.position.distanceTo(playerEntity.position) > DELIVERY_RANGE) {
      reply('Подойди ближе — мне нужно тебя видеть, чтобы выдать предметы.');
      return;
    }

    this.busy = true;
    this.mode = 'banking';

    try {
      // Whatever the bot already carries counts as custody; only the
      // shortfall is taken from the ender chest (if one is around).
      const carried = this.bot.inventory.count(item.id, null);
      if (carried < count) {
        const container = await this.openEnderChest();
        if (container) {
          try {
            await container.withdraw(item.id, null, count - carried);
          } catch (err) {
            this.log(`Withdraw of ${count - carried} ${item.name} incomplete: ${err.message}`);
          } finally {
            container.close();
          }
        }
      }

      const give = Math.min(count, this.bot.inventory.count(item.id, null));
      if (give <= 0) {
        reply('Не нашёл предметы ни при себе, ни в сундуке — реестр разошёлся с фактом, сообщи владельцу.');
        return;
      }

      const delivered = await this.deliverItems(username, item, give);
      if (!delivered) {
        reply('Не смог тебя найти для выдачи — предметы остаются у меня, баланс не списан.');
        return;
      }

      this.ledger.recordWithdraw(this.id, username, item.name, give);
      const note = give < count ? ` (нашёл только ${give} из ${count})` : '';
      reply(`Выдал ${give} ${item.name}${note}. Остаток на балансе: ${this.ledger.balance(username, item.name)}.`);
    } catch (err) {
      this.log(`Withdraw failed: ${err.message}`);
      reply('Что-то пошло не так при выдаче, попробуй ещё раз.');
    } finally {
      this.busy = false;
      this.mode = 'idle';
    }
  }

  async deliverItems(username, item, count) {
    const entity = this.getPlayerEntity(username);
    if (!entity) return false;

    try {
      await this.bot.pathfinder.goto(new GoalNear(entity.position.x, entity.position.y, entity.position.z, 2));
    } catch (err) {
      this.log(`Could not reach ${username}: ${err.message}`);
    }

    const freshEntity = this.getPlayerEntity(username);
    if (!freshEntity) return false;

    await this.bot.lookAt(freshEntity.position.offset(0, 1.2, 0), true);
    await this.bot.toss(item.id, null, count);
    return true;
  }

  // ---------- Wallet binding ----------

  handleWallet(player, arg, reply) {
    if (!arg) {
      const wallet = this.ledger.walletOf(player);
      reply(wallet
        ? `Кошелёк ${player}: ${wallet.address}`
        : `Кошелёк не привязан. Напиши "${CHAT_CONTROL_PREFIX} wallet 0x..." — адрес в сети Cyberia.`);
      return;
    }

    if (arg.toLowerCase() === 'clear') {
      const removed = this.ledger.unlinkWallet(player);
      reply(removed ? 'Привязка кошелька удалена.' : 'Кошелёк и так не привязан.');
      return;
    }

    const address = arg.trim();
    if (!/^0x[0-9a-fA-F]{40}$/.test(address)) {
      reply('Неверный адрес — нужен EVM-адрес вида 0x + 40 hex-символов.');
      return;
    }

    this.ledger.linkWallet(this.id, player, address);
    reply(`Кошелёк привязан к ${player}: ${address}. Сюда придут токены за твои депозиты.`);
  }

  // ---------- Commands ----------

  runCommand(input, ctx) {
    const line = input.trim();
    if (!line) return;

    const [commandName, ...args] = line.split(/\s+/);
    const command = commandName.toLowerCase();
    const { reply } = ctx;

    try {
      switch (command) {
        case 'help':
          this.printHelp(ctx);
          break;

        case 'status':
          this.printStatus(reply);
          break;

        case 'players':
          this.printPlayers(reply);
          break;

        case 'say':
          if (!this.requireBot(reply)) return;
          this.recordOwnMessage(args.join(' '));
          this.bot.chat(args.join(' '));
          break;

        case 'login':
          if (!this.requireBot(reply)) return;
          if (!this.config.loginCommand) {
            reply('No login command configured for this server');
            return;
          }
          this.bot.chat(this.config.loginCommand);
          this.log('Sent login command');
          break;

        case 'follow':
        case 'come':
          this.followPlayer(args[0], Number(args[1] || 2), reply);
          break;

        case 'goto':
          this.goToCoords(args, reply);
          break;

        case 'look':
          this.lookAtPlayer(args[0], reply);
          break;

        case 'jump':
          this.jump(reply);
          break;

        case 'move':
          this.move(args[0], Number(args[1] || 1000), reply);
          break;

        case 'wander':
          this.setWander(args[0], reply);
          break;

        case 'stop':
          this.stopBot(reply);
          break;

        case 'deposit': {
          const player = ctx.requester || args[0];
          if (!player) {
            reply('Usage: deposit <player> (console needs an explicit player name)');
            return;
          }
          this.startDeposit(player, reply);
          break;
        }

        case 'done': {
          if (!this.session) {
            reply('Сейчас нет открытого депозита.');
            return;
          }
          const closer = ctx.requester && !ctx.isOwner ? ctx.requester : null;
          this.finishDeposit(closer).catch((err) => {
            this.log(`Deposit finish failed: ${err.message}`);
          });
          break;
        }

        case 'cancel':
          this.cancelDeposit(ctx.requester, reply).catch((err) => {
            this.log(`Deposit cancel failed: ${err.message}`);
          });
          break;

        case 'balance': {
          const player = ctx.source === 'stdin' ? args[0] : ctx.requester;
          if (!player) {
            reply('Usage: balance <player>');
            return;
          }
          const balances = this.ledger.balancesOf(player);
          if (Object.keys(balances).length === 0) {
            reply(`Баланс ${player} пуст. Напиши "${CHAT_CONTROL_PREFIX} deposit", чтобы сдать предметы.`);
          } else {
            reply(`Баланс ${player}: ${formatItemMap(balances)}`);
          }
          break;
        }

        case 'withdraw': {
          let player;
          let itemArgs;
          if (ctx.source === 'stdin') {
            player = args[0];
            itemArgs = args.slice(1);
            if (!player || !itemArgs[0]) {
              reply('Usage: withdraw <player> <item> [count|all]');
              return;
            }
          } else {
            player = ctx.requester;
            itemArgs = args;
          }
          this.withdraw(player, itemArgs[0], itemArgs[1], reply).catch((err) => {
            this.log(`Withdraw failed: ${err.message}`);
          });
          break;
        }

        case 'stock': {
          const stock = this.ledger.stockOf(this.id);
          if (Object.keys(stock).length === 0) {
            reply('Хранилище на этом сервере пусто.');
          } else {
            reply(`Хранилище [${this.id}]: ${formatItemMap(stock)}`);
          }
          break;
        }

        case 'wallet': {
          let player;
          let walletArg;
          if (ctx.source === 'stdin') {
            player = args[0];
            walletArg = args[1];
            if (!player) {
              reply('Usage: wallet <player> [address|clear]');
              return;
            }
          } else {
            player = ctx.requester;
            walletArg = args[0];
          }
          this.handleWallet(player, walletArg, reply);
          break;
        }

        case 'quit':
        case 'exit':
          shutdown();
          break;

        default:
          reply(`Unknown command: ${command}. Type "help".`);
      }
    } catch (err) {
      console.error(`[${this.id}] Command failed: ${err.message}`);
    }
  }

  printHelp(ctx) {
    const prefix = ctx.source === 'stdin' ? '' : `${CHAT_CONTROL_PREFIX} `;
    const publicCommands = [
      `${prefix}deposit — сдать предметы боту`,
      `${prefix}done — закончить депозит`,
      `${prefix}cancel — отменить депозит`,
      `${prefix}balance — твой баланс`,
      `${prefix}withdraw <item> [count|all] — получить предметы`,
      `${prefix}stock — что в наличии на этом сервере`,
      `${prefix}wallet [0x...|clear] — привязать кошелёк Cyberia`,
    ];
    const ownerCommands = [
      `${prefix}status`, `${prefix}players`, `${prefix}say <message>`, `${prefix}login`,
      `${prefix}follow <player> [range]`, `${prefix}come <player> [range]`, `${prefix}goto <x> <y> <z>`,
      `${prefix}look [player]`, `${prefix}jump`, `${prefix}move <forward|back|left|right> [ms]`,
      `${prefix}wander <on|off>`, `${prefix}stop`, `${prefix}quit`,
    ];

    if (ctx.source === 'stdin') {
      ctx.reply(`Commands:\n${[...publicCommands, ...ownerCommands].join('\n')}\nConsole extras: balance <player>, withdraw <player> <item> [count], deposit <player>, wallet <player> [address|clear]`);
      return;
    }

    ctx.reply(publicCommands.join('; '));
    if (ctx.isOwner) {
      ctx.reply(ownerCommands.join('; '));
    }
  }

  printStatus(reply) {
    if (!this.requireBot(reply)) return;

    const position = this.bot.entity.position;
    reply(
      `Status [${this.id}]: mode=${this.mode}, health=${this.bot.health}, food=${this.bot.food}, position=${position.x.toFixed(1)} ${position.y.toFixed(1)} ${position.z.toFixed(1)}`,
    );
  }

  printPlayers(reply) {
    if (!this.bot) {
      reply('Bot is offline');
      return;
    }

    const names = Object.keys(this.bot.players).sort();
    reply(`Players [${this.id}]: ${names.length ? names.join(', ') : 'none'}`);
  }

  followPlayer(name, range, reply) {
    if (!this.requireBot(reply)) return;
    if (!name) {
      reply('Usage: follow <player> [range]');
      return;
    }

    const player = this.getPlayerEntity(name);
    if (!player) {
      reply(`Player not found or too far away: ${name}`);
      return;
    }

    this.stopControls();
    this.mode = `follow:${name}`;
    this.bot.pathfinder.setGoal(new GoalFollow(player, Number.isFinite(range) ? range : 2), true);
    reply(`Following ${name}`);
  }

  goToCoords(args, reply) {
    if (!this.requireBot(reply)) return;
    if (args.length < 3) {
      reply('Usage: goto <x> <y> <z>');
      return;
    }

    const coords = args.slice(0, 3).map(Number);
    if (coords.some((value) => !Number.isFinite(value))) {
      reply('Coordinates must be numbers');
      return;
    }

    this.stopControls();
    this.mode = `goto:${coords.join(',')}`;
    this.bot.pathfinder.setGoal(new GoalBlock(Math.floor(coords[0]), Math.floor(coords[1]), Math.floor(coords[2])));
    reply(`Going to ${coords.join(' ')}`);
  }

  lookAtPlayer(name, reply) {
    if (!this.requireBot(reply)) return;

    const entity = name ? this.getPlayerEntity(name) : this.bot.nearestEntity();
    if (!entity) {
      reply(name ? `Player not found: ${name}` : 'No entity nearby');
      return;
    }

    this.lookAtEntity(entity);
  }

  jump(reply) {
    if (!this.requireBot(reply)) return;

    this.bot.setControlState('jump', true);
    setTimeout(() => {
      if (!this.bot) return;
      this.bot.setControlState('jump', false);
    }, 350);
  }

  move(direction, duration, reply) {
    if (!this.requireBot(reply)) return;

    const validDirections = new Set(['forward', 'back', 'left', 'right']);
    if (!validDirections.has(direction)) {
      reply('Usage: move <forward|back|left|right> [ms]');
      return;
    }

    const safeDuration = Number.isFinite(duration) ? Math.max(100, Math.min(duration, 10000)) : 1000;

    this.stopBot(reply);
    this.mode = `move:${direction}`;
    this.bot.setControlState(direction, true);
    setTimeout(() => {
      if (!this.bot) return;
      this.bot.setControlState(direction, false);
      this.mode = 'manual';
    }, safeDuration);
  }

  setWander(value, reply) {
    if (!this.requireBot(reply)) return;

    if (value === 'on') {
      this.mode = 'idle';
      reply('Wander enabled');
      return;
    }

    if (value === 'off') {
      this.stopBot(reply);
      reply('Wander disabled');
      return;
    }

    reply('Usage: wander <on|off>');
  }

  stopBot(reply) {
    if (!this.requireBot(reply)) return;

    this.bot.pathfinder.setGoal(null);
    this.stopControls();
    this.mode = 'manual';
    reply('Stopped');
  }

  quit() {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }

    if (this.bot) {
      this.bot.quit('Stopping bot');
    } else {
      onBotEnded();
    }
  }
}

// ---------- Multi-server console ----------

const ledger = new Ledger(DB_PATH, LEDGER_PATH);
const serverBots = loadServerConfigs().map((config) => new ServerBot(config, ledger));
let activeBot = serverBots[0];
let rl = null;

function groupRows(rows, keyField) {
  const grouped = new Map();
  for (const row of rows) {
    if (!grouped.has(row[keyField])) grouped.set(row[keyField], []);
    grouped.get(row[keyField]).push(`${row.item}: ${row.count}`);
  }
  return grouped;
}

function printLedgerSummary(args) {
  const player = args[0];

  if (player) {
    const balances = ledger.balancesOf(player);
    console.log(`Balance of ${player}: ${Object.keys(balances).length ? formatItemMap(balances) : 'empty'}`);
    const wallet = ledger.walletOf(player);
    console.log(`Wallet: ${wallet ? `${wallet.address} (linked ${wallet.linked_at} on ${wallet.server})` : 'not linked'}`);
    return;
  }

  console.log('Balances:');
  const balancesByPlayer = groupRows(ledger.allBalances(), 'player_key');
  if (!balancesByPlayer.size) console.log('  (empty)');
  for (const [name, items] of balancesByPlayer) {
    console.log(`  ${name}: ${items.join(', ')}`);
  }

  console.log('Stock per server:');
  const stockByServer = groupRows(ledger.allStock(), 'server');
  if (!stockByServer.size) console.log('  (empty)');
  for (const [serverId, items] of stockByServer) {
    console.log(`  ${serverId}: ${items.join(', ')}`);
  }

  console.log('Wallets:');
  const wallets = ledger.allWallets();
  if (!wallets.length) console.log('  (none)');
  for (const wallet of wallets) {
    console.log(`  ${wallet.player}: ${wallet.address}`);
  }

  const recent = ledger.recentTransactions(10);
  console.log(`Last ${recent.length} transaction(s):`);
  for (const tx of recent) {
    console.log(`  ${tx.at} [${tx.server}] ${tx.type} ${tx.count} ${tx.item} — ${tx.player}`);
  }
}

function runConsoleCommand(line) {
  const trimmed = line.trim();
  if (!trimmed) return;

  const [commandName, ...args] = trimmed.split(/\s+/);
  const command = commandName.toLowerCase();

  switch (command) {
    case 'servers':
      for (const serverBot of serverBots) {
        const marker = serverBot === activeBot ? '*' : ' ';
        console.log(`${marker} ${serverBot.id} — ${serverBot.config.host}:${serverBot.config.port} (${serverBot.mode})`);
      }
      return;

    case 'use': {
      const target = serverBots.find((serverBot) => serverBot.id === args[0]);
      if (!target) {
        console.log(`Unknown server id: ${args[0]}. Type "servers".`);
        return;
      }
      activeBot = target;
      rl?.setPrompt(`mc:${activeBot.id}> `);
      console.log(`Active server: ${activeBot.id}`);
      return;
    }

    case 'ledger':
      printLedgerSummary(args);
      return;

    default:
      activeBot.runCommand(trimmed, {
        source: 'stdin',
        requester: null,
        isOwner: true,
        reply: (text) => console.log(text),
      });
  }
}

let endedBots = 0;

function onBotEnded() {
  endedBots += 1;
  if (endedBots >= serverBots.length) {
    process.exit(0);
  }
}

function shutdown() {
  if (shuttingDown) return;
  shuttingDown = true;

  for (const serverBot of serverBots) {
    serverBot.quit();
  }

  setTimeout(() => process.exit(0), 5000).unref();
}

function setupConsole() {
  rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout,
    prompt: `mc:${activeBot.id}> `,
  });

  console.log('Multi-server console. Global commands: servers, use <id>, ledger [player], quit.');
  console.log('Other commands go to the active server. Type "help" for the full list.');
  rl.prompt();

  rl.on('line', (line) => {
    runConsoleCommand(line);
    rl.prompt();
  });

  rl.on('close', () => {
    if (!shuttingDown) shutdown();
  });
}

process.once('SIGINT', shutdown);
process.once('SIGTERM', shutdown);

setupConsole();
for (const serverBot of serverBots) {
  serverBot.connect();
}
