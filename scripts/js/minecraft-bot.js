installPacketLogFilter();

const mineflayer = require('mineflayer');
const readline = require('node:readline');
const {
  Movements,
  goals: { GoalBlock, GoalFollow },
  pathfinder,
} = require('mineflayer-pathfinder');

const SERVER_HOST = 'play.akvarium228.ru';
const SERVER_PORT = Number(process.env.MC_PORT || 25565);
const USERNAME = process.env.MC_USERNAME || 'lain';
const AUTH = process.env.MC_AUTH || 'offline';
const VERSION = process.env.MC_VERSION || false;
const RECONNECT_DELAY_MS = Number(process.env.MC_RECONNECT_DELAY_MS || 10000);
const LOGIN_COMMAND = process.env.MC_LOGIN_COMMAND || '/l 4050303';
const LOGIN_DELAY_MS = Number(process.env.MC_LOGIN_DELAY_MS || 1500);
const IDLE_INTERVAL_MS = Number(process.env.MC_IDLE_INTERVAL_MS || 6500);
const CHAT_CONTROL_PREFIX = process.env.MC_CONTROL_PREFIX || '!bot';
const CHAT_CONTROL_OWNER = process.env.MC_CONTROL_OWNER || '';
const ALLOW_PUBLIC_CHAT_CONTROL = process.env.MC_ALLOW_PUBLIC_CONTROL === 'true';
const VERBOSE_ERRORS = process.env.MC_VERBOSE_ERRORS === 'true';

let bot = null;
let defaultMovements = null;
let idleTimer = null;
let mode = 'starting';
let shuttingDown = false;
let reconnectTimer = null;

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

function createBot() {
  console.log(`Connecting ${USERNAME} to ${SERVER_HOST}:${SERVER_PORT}`);

  bot = mineflayer.createBot({
    host: SERVER_HOST,
    port: SERVER_PORT,
    username: USERNAME,
    auth: AUTH,
    version: VERSION,
    hideErrors: !VERBOSE_ERRORS,
  });

  bot.loadPlugin(pathfinder);

  bot.once('spawn', () => {
    console.log(`Spawned as ${bot.username}`);
    defaultMovements = new Movements(bot);
    bot.pathfinder.setMovements(defaultMovements);
    mode = 'idle';

    setTimeout(() => {
      if (!bot) return;
      bot.chat(LOGIN_COMMAND);
      console.log('Sent login command');
    }, LOGIN_DELAY_MS);

    startIdleLoop();
  });

  bot.on('chat', (username, message) => {
    if (username === bot.username) return;
    console.log(`<${username}> ${message}`);

    if (!isChatController(username)) return;
    if (!message.startsWith(CHAT_CONTROL_PREFIX)) return;

    runCommand(message.slice(CHAT_CONTROL_PREFIX.length).trim(), 'chat');
  });

  bot.on('kicked', (reason) => {
    console.log(`Kicked: ${reason}`);
  });

  bot.on('error', (err) => {
    console.error(formatError(err));
  });

  bot.on('end', () => {
    stopIdleLoop();
    bot = null;
    defaultMovements = null;
    mode = 'offline';
    console.log('Disconnected');

    if (shuttingDown) {
      process.exit(0);
    }

    reconnectTimer = setTimeout(() => {
      reconnectTimer = null;
      createBot();
    }, RECONNECT_DELAY_MS);
  });
}

function isChatController(username) {
  if (ALLOW_PUBLIC_CHAT_CONTROL) return true;
  if (!CHAT_CONTROL_OWNER) return false;

  return username.toLowerCase() === CHAT_CONTROL_OWNER.toLowerCase();
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

function startIdleLoop() {
  stopIdleLoop();

  idleTimer = setInterval(() => {
    if (!bot?.entity || mode !== 'idle') return;
    if (bot.pathfinder.isMoving()) return;

    const nearbyPlayer = bot.nearestEntity((entity) => {
      return entity.type === 'player' && entity.username !== bot.username;
    });

    if (nearbyPlayer && bot.entity.position.distanceTo(nearbyPlayer.position) < 12) {
      lookAtEntity(nearbyPlayer);
      return;
    }

    randomIdleMove();
  }, IDLE_INTERVAL_MS);
}

function stopIdleLoop() {
  if (!idleTimer) return;

  clearInterval(idleTimer);
  idleTimer = null;
}

function randomIdleMove() {
  const controls = ['forward', 'back', 'left', 'right'];
  const control = controls[Math.floor(Math.random() * controls.length)];
  const duration = 600 + Math.floor(Math.random() * 900);

  stopControls();
  bot.setControlState(control, true);
  bot.setControlState('jump', Math.random() > 0.65);

  setTimeout(() => {
    if (!bot) return;
    bot.setControlState(control, false);
    bot.setControlState('jump', false);
  }, duration);
}

function stopControls() {
  if (!bot) return;

  for (const control of ['forward', 'back', 'left', 'right', 'jump', 'sprint', 'sneak']) {
    bot.setControlState(control, false);
  }
}

function lookAtEntity(entity) {
  if (!bot || !entity) return;

  const height = entity.height || 1.6;
  bot.lookAt(entity.position.offset(0, height, 0), true);
}

function requireBot() {
  if (bot?.entity) return true;

  console.log('Bot is not spawned yet');
  return false;
}

function getPlayerEntity(name) {
  if (!bot) return null;

  const player = bot.players[name];
  return player?.entity || null;
}

function runCommand(input, source = 'stdin') {
  const line = input.trim();
  if (!line) return;

  const [commandName, ...args] = line.split(/\s+/);
  const command = commandName.toLowerCase();

  try {
    switch (command) {
      case 'help':
        printHelp(source);
        break;

      case 'status':
        printStatus();
        break;

      case 'players':
        printPlayers();
        break;

      case 'say':
        if (!requireBot()) return;
        bot.chat(args.join(' '));
        break;

      case 'login':
        if (!requireBot()) return;
        bot.chat(LOGIN_COMMAND);
        console.log('Sent login command');
        break;

      case 'follow':
      case 'come':
        followPlayer(args[0], Number(args[1] || 2));
        break;

      case 'goto':
        goToBlock(args);
        break;

      case 'look':
        lookAtPlayer(args[0]);
        break;

      case 'jump':
        jump();
        break;

      case 'move':
        move(args[0], Number(args[1] || 1000));
        break;

      case 'wander':
        setWander(args[0]);
        break;

      case 'stop':
        stopBot();
        break;

      case 'quit':
      case 'exit':
        shutdown();
        break;

      default:
        console.log(`Unknown command: ${command}. Type "help".`);
    }
  } catch (err) {
    console.error(`Command failed: ${err.message}`);
  }
}

function printHelp(source) {
  const prefix = source === 'chat' ? CHAT_CONTROL_PREFIX : '';
  const commands = [
    `${prefix} help`,
    `${prefix} status`,
    `${prefix} players`,
    `${prefix} say <message>`,
    `${prefix} login`,
    `${prefix} follow <player> [range]`,
    `${prefix} come <player> [range]`,
    `${prefix} goto <x> <y> <z>`,
    `${prefix} look [player]`,
    `${prefix} jump`,
    `${prefix} move <forward|back|left|right> [ms]`,
    `${prefix} wander <on|off>`,
    `${prefix} stop`,
    `${prefix} quit`,
  ];

  console.log(`Commands:\n${commands.join('\n')}`);
}

function printStatus() {
  if (!requireBot()) return;

  const position = bot.entity.position;
  console.log(
    `Status: mode=${mode}, health=${bot.health}, food=${bot.food}, position=${position.x.toFixed(1)} ${position.y.toFixed(1)} ${position.z.toFixed(1)}`,
  );
}

function printPlayers() {
  if (!bot) {
    console.log('Bot is offline');
    return;
  }

  const names = Object.keys(bot.players).sort();
  console.log(`Players: ${names.length ? names.join(', ') : 'none'}`);
}

function followPlayer(name, range) {
  if (!requireBot()) return;
  if (!name) {
    console.log('Usage: follow <player> [range]');
    return;
  }

  const player = getPlayerEntity(name);
  if (!player) {
    console.log(`Player not found or too far away: ${name}`);
    return;
  }

  stopControls();
  mode = `follow:${name}`;
  bot.pathfinder.setGoal(new GoalFollow(player, Number.isFinite(range) ? range : 2), true);
  console.log(`Following ${name}`);
}

function goToBlock(args) {
  if (!requireBot()) return;
  if (args.length < 3) {
    console.log('Usage: goto <x> <y> <z>');
    return;
  }

  const coords = args.slice(0, 3).map(Number);
  if (coords.some((value) => !Number.isFinite(value))) {
    console.log('Coordinates must be numbers');
    return;
  }

  stopControls();
  mode = `goto:${coords.join(',')}`;
  bot.pathfinder.setGoal(new GoalBlock(Math.floor(coords[0]), Math.floor(coords[1]), Math.floor(coords[2])));
  console.log(`Going to ${coords.join(' ')}`);
}

function lookAtPlayer(name) {
  if (!requireBot()) return;

  const entity = name ? getPlayerEntity(name) : bot.nearestEntity();
  if (!entity) {
    console.log(name ? `Player not found: ${name}` : 'No entity nearby');
    return;
  }

  lookAtEntity(entity);
}

function jump() {
  if (!requireBot()) return;

  bot.setControlState('jump', true);
  setTimeout(() => {
    if (!bot) return;
    bot.setControlState('jump', false);
  }, 350);
}

function move(direction, duration) {
  if (!requireBot()) return;

  const validDirections = new Set(['forward', 'back', 'left', 'right']);
  if (!validDirections.has(direction)) {
    console.log('Usage: move <forward|back|left|right> [ms]');
    return;
  }

  const safeDuration = Number.isFinite(duration) ? Math.max(100, Math.min(duration, 10000)) : 1000;

  stopBot();
  mode = `move:${direction}`;
  bot.setControlState(direction, true);
  setTimeout(() => {
    if (!bot) return;
    bot.setControlState(direction, false);
    mode = 'manual';
  }, safeDuration);
}

function setWander(value) {
  if (!requireBot()) return;

  if (value === 'on') {
    mode = 'idle';
    console.log('Wander enabled');
    return;
  }

  if (value === 'off') {
    stopBot();
    console.log('Wander disabled');
    return;
  }

  console.log('Usage: wander <on|off>');
}

function stopBot() {
  if (!requireBot()) return;

  bot.pathfinder.setGoal(null);
  stopControls();
  mode = 'manual';
  console.log('Stopped');
}

function shutdown() {
  shuttingDown = true;

  if (reconnectTimer) {
    clearTimeout(reconnectTimer);
    reconnectTimer = null;
  }

  if (bot) {
    bot.quit('Stopping bot');
  } else {
    process.exit(0);
  }
}

function setupConsole() {
  const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout,
    prompt: 'mc> ',
  });

  printHelp('stdin');
  rl.prompt();

  rl.on('line', (line) => {
    runCommand(line, 'stdin');
    rl.prompt();
  });

  rl.on('close', () => {
    if (!shuttingDown) shutdown();
  });
}

process.once('SIGINT', shutdown);
process.once('SIGTERM', shutdown);

setupConsole();
createBot();
