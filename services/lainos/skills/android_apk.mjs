// Build the Cyberia Android app (frontend/mobile, Capacitor) and hand the APK
// to the operator in Telegram as a document.
//
// Two things this skill exists for, both of which run_shell/send_telegram
// cannot do: the build has to happen in the monorepo (run_shell is confined to
// the workspace, deliberately — this skill only ever runs the project's own
// npm/gradle commands in frontend/mobile, it is not a shell escape), and the
// delivery has to be a file (send_telegram is text-only). The bot token is read
// from the host settings and never returned; the only path ever uploaded is the
// artifact this skill just produced or downloaded.
//
// When the host has no Android SDK/JDK the skill says exactly what is missing
// and can fall back to the APK built by .github/workflows/apps.yml.

import { execFile } from 'node:child_process';
import { mkdir, readdir, readFile, stat, writeFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { inflateRawSync } from 'node:zlib';
import { File } from 'node:buffer';
// undici's fetch only understands undici's own FormData — the global one is
// serialised as text/plain and Telegram rejects the request.
import { FormData, fetch as undiciFetch, ProxyAgent } from 'undici';

const execFileAsync = promisify(execFile);

const SKILL_DIR = dirname(fileURLToPath(import.meta.url));
const MOBILE_REL = join('frontend', 'mobile');
const TELEGRAM_MAX_UPLOAD = 50 * 1024 * 1024; // Bot API limit for sendDocument.
const CI_WORKFLOW = 'apps.yml';
const CI_ARTIFACT = 'cyberia-android';

const mb = (bytes) => `${(bytes / 1024 / 1024).toFixed(1)} MB`;

async function exists(path) {
  try {
    await stat(path);
    return true;
  } catch {
    return false;
  }
}

// ------------------------------------------------------------------ repo

/**
 * Find the singularity checkout. The forge already knows it, and this file
 * normally lives inside it; both are checked before falling back to cwd.
 */
async function findRepo(runtime) {
  const forgeRepo = runtime.getService?.('forge')?.repoPath;
  const candidates = [
    runtime.getSetting?.('LAINOS_APK_REPO'),
    runtime.getSetting?.('LAINOS_FORGE_REPO'),
    forgeRepo,
    resolve(SKILL_DIR, '..', '..', '..'), // services/lainos/skills -> repo root
    process.cwd(),
    resolve(process.cwd(), '..', '..'),
  ];
  for (const candidate of candidates) {
    if (!candidate) continue;
    const root = resolve(String(candidate).trim());
    if (await exists(join(root, MOBILE_REL, 'package.json'))) return root;
  }
  throw new Error(
    `не нашла monorepo с ${MOBILE_REL}/package.json (искала от ${SKILL_DIR}); задай LAINOS_APK_REPO`
  );
}

async function repoSlug(repo, runtime) {
  const configured = runtime.getSetting?.('LAINOS_APK_GITHUB_REPO')?.trim();
  if (configured) return configured.replace(/\.git$/, '');
  const { stdout } = await execFileAsync('git', ['config', '--get', 'remote.origin.url'], {
    cwd: repo,
  });
  const m = stdout.trim().replace(/\.git$/, '').match(/github\.com[:/]([^/]+)\/([^/]+)$/i);
  if (!m) throw new Error('remote.origin.url не похож на GitHub-репозиторий');
  return `${m[1]}/${m[2]}`;
}

// ------------------------------------------------------------------ toolchain

/** Locate an Android SDK: env first, then android/local.properties, then the usual paths. */
async function findAndroidSdk(mobileDir) {
  for (const key of ['ANDROID_HOME', 'ANDROID_SDK_ROOT']) {
    const value = process.env[key]?.trim();
    if (value && (await exists(value))) return { path: value, from: key };
  }
  try {
    const props = await readFile(join(mobileDir, 'android', 'local.properties'), 'utf8');
    const dir = props.match(/^\s*sdk\.dir\s*=\s*(.+)$/m)?.[1]?.trim().replace(/\\:/g, ':');
    if (dir && (await exists(dir))) return { path: dir, from: 'android/local.properties' };
  } catch {
    // No local.properties — normal for a fresh checkout.
  }
  const guesses = [
    join(homedir(), 'Android', 'Sdk'),
    join(homedir(), 'android-sdk'),
    '/usr/lib/android-sdk',
    '/opt/android-sdk',
  ];
  for (const guess of guesses) {
    if (await exists(guess)) return { path: guess, from: 'default path' };
  }
  return null;
}

/** Everything gradle needs, checked before a 20-minute build starts. */
async function preflight(mobileDir) {
  const missing = [];
  let java = null;
  try {
    const { stderr, stdout } = await execFileAsync('java', ['-version']);
    java = `${stderr || stdout}`.split('\n')[0].trim();
  } catch {
    missing.push('JDK 21 (java не в PATH — apt install openjdk-21-jdk)');
  }
  const sdk = await findAndroidSdk(mobileDir);
  if (!sdk) {
    missing.push(
      'Android SDK (нет ANDROID_HOME/ANDROID_SDK_ROOT и android/local.properties; ' +
        'нужны platform-tools, platforms;android-36, build-tools;36.0.0)'
    );
  }
  return { missing, java, sdk };
}

// ------------------------------------------------------------------ build

async function run(command, cwd, timeout, env) {
  try {
    const { stdout, stderr } = await execFileAsync(command, {
      cwd,
      timeout,
      maxBuffer: 32 * 1024 * 1024,
      shell: '/bin/bash',
      env,
    });
    return { ok: true, out: `${stdout}${stderr}` };
  } catch (err) {
    const out = [err.stdout, err.stderr, err.message].filter(Boolean).join('\n');
    return { ok: false, out, killed: Boolean(err.killed) };
  }
}

/** Last ~20 lines of a build log — enough to see the gradle failure, small enough for chat. */
function tail(text, lines = 20) {
  return text.trim().split('\n').slice(-lines).join('\n');
}

/** Newest *.apk under android/app/build/outputs/apk/<variant>/. */
async function findApk(mobileDir, variant) {
  const dir = join(mobileDir, 'android', 'app', 'build', 'outputs', 'apk', variant);
  let entries;
  try {
    entries = (await readdir(dir)).filter((f) => f.endsWith('.apk'));
  } catch {
    return null;
  }
  let best = null;
  for (const name of entries) {
    const path = join(dir, name);
    const info = await stat(path);
    if (!best || info.mtimeMs > best.mtimeMs) {
      best = { path, name, size: info.size, mtimeMs: info.mtimeMs };
    }
  }
  return best;
}

async function buildApk(mobileDir, variant, runtime) {
  const steps = [];
  const timeout = Number(runtime.getSetting?.('LAINOS_APK_TIMEOUT_MS') ?? 1_500_000); // 25 min
  const { missing, java, sdk } = await preflight(mobileDir);
  if (missing.length) return { blocked: `на хосте нет: ${missing.join('; ')}`, steps };
  steps.push(`toolchain: ${java}, SDK ${sdk.path} (${sdk.from})`);

  const env = { ...process.env, ANDROID_HOME: sdk.path, ANDROID_SDK_ROOT: sdk.path, CI: '1' };

  if (!(await exists(join(mobileDir, 'node_modules')))) {
    const install = await run('npm ci --no-audit --no-fund', mobileDir, timeout, env);
    if (!install.ok) return { blocked: `npm ci упал:\n${tail(install.out)}`, steps };
    steps.push('npm ci');
  }

  // The project's own commands: android:apk = sync:android + assembleDebug.
  // Release has no APK script (android:release makes an .aab for Play), so it
  // uses the same gradle task CI runs.
  const command =
    variant === 'release'
      ? 'npm run sync:android && cd android && ./gradlew assembleRelease --no-daemon'
      : 'npm run android:apk';
  const build = await run(command, mobileDir, timeout, env);
  steps.push(`build: ${command}`);
  if (!build.ok) {
    return {
      blocked: build.killed
        ? `сборка не уложилась в ${Math.round(timeout / 60000)} мин (LAINOS_APK_TIMEOUT_MS)`
        : `сборка упала:\n${tail(build.out)}`,
      steps,
    };
  }

  const apk = await findApk(mobileDir, variant);
  if (!apk) return { blocked: 'gradle отработал, но APK в outputs/apk не появился', steps };
  return { apk, steps };
}

// ------------------------------------------------------------------ CI fallback

/** Minimal zip reader: pull the first *.apk out of a GitHub artifact archive. */
function apkFromZip(buf) {
  let eocd = -1;
  for (let i = buf.length - 22; i >= 0 && i >= buf.length - 22 - 0xffff; i--) {
    if (buf.readUInt32LE(i) === 0x06054b50) {
      eocd = i;
      break;
    }
  }
  if (eocd < 0) throw new Error('это не zip-архив');
  const count = buf.readUInt16LE(eocd + 10);
  let off = buf.readUInt32LE(eocd + 16);
  for (let i = 0; i < count; i += 1) {
    if (buf.readUInt32LE(off) !== 0x02014b50) throw new Error('битая центральная директория zip');
    const method = buf.readUInt16LE(off + 10);
    const compSize = buf.readUInt32LE(off + 20);
    const nameLen = buf.readUInt16LE(off + 28);
    const extraLen = buf.readUInt16LE(off + 30);
    const commentLen = buf.readUInt16LE(off + 32);
    const localOff = buf.readUInt32LE(off + 42);
    const name = buf.toString('utf8', off + 46, off + 46 + nameLen);
    if (name.toLowerCase().endsWith('.apk')) {
      if (compSize === 0xffffffff || localOff === 0xffffffff) {
        throw new Error('zip64-артефакт — скачай его вручную со страницы run-а');
      }
      const start = localOff + 30 + buf.readUInt16LE(localOff + 26) + buf.readUInt16LE(localOff + 28);
      const data = buf.subarray(start, start + compSize);
      return { name: name.split('/').pop(), bytes: method === 0 ? Buffer.from(data) : inflateRawSync(data) };
    }
    off += 46 + nameLen + extraLen + commentLen;
  }
  throw new Error('в артефакте нет .apk');
}

async function githubJson(url, token, dispatcher) {
  const res = await undiciFetch(url, {
    headers: {
      accept: 'application/vnd.github+json',
      'user-agent': 'LainOS-android-apk',
      'x-github-api-version': '2022-11-28',
      ...(token ? { authorization: `Bearer ${token}` } : {}),
    },
    dispatcher,
    signal: AbortSignal.timeout(60_000),
  });
  if (res.status >= 400) throw new Error(`GitHub API вернул ${res.status}`);
  return res.json();
}

/** Download the newest cyberia-android artifact from GitHub Actions into data/apk/. */
async function fetchCiApk(repo, runtime, proxy) {
  const steps = [];
  const slug = await repoSlug(repo, runtime);
  const runsUrl = `https://github.com/${slug}/actions/workflows/${CI_WORKFLOW}`;
  const token = runtime.getSetting?.('GITHUB_TOKEN')?.trim();
  if (!token) {
    return {
      blocked:
        'скачать артефакт CI нельзя без токена: GitHub отдаёт actions/artifacts только ' +
        `авторизованным. Поставь GITHUB_TOKEN (scope actions:read) или забери APK руками: ${runsUrl}`,
      steps,
    };
  }
  const dispatcher = proxy ? new ProxyAgent(proxy) : undefined;
  try {
    const list = await githubJson(
      `https://api.github.com/repos/${slug}/actions/artifacts?per_page=100`,
      token,
      dispatcher
    );
    const artifact = (list.artifacts ?? [])
      .filter((a) => !a.expired && a.name.startsWith(CI_ARTIFACT))
      .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))[0];
    if (!artifact) {
      return { blocked: `в ${slug} нет живого артефакта ${CI_ARTIFACT}; запусти ${CI_WORKFLOW}`, steps };
    }
    steps.push(`CI artifact ${artifact.name} #${artifact.id} от ${artifact.created_at}`);

    const zipRes = await undiciFetch(artifact.archive_download_url, {
      headers: {
        accept: 'application/vnd.github+json',
        'user-agent': 'LainOS-android-apk',
        authorization: `Bearer ${token}`,
      },
      dispatcher,
      signal: AbortSignal.timeout(300_000),
    });
    if (zipRes.status >= 400) return { blocked: `скачивание артефакта: HTTP ${zipRes.status}`, steps };
    const zip = Buffer.from(await zipRes.arrayBuffer());
    const { name, bytes } = apkFromZip(zip);

    const dir = join(resolve(runtime.getSetting?.('LAINOS_DATA_DIR') ?? './data'), 'apk');
    await mkdir(dir, { recursive: true });
    const path = join(dir, name);
    await writeFile(path, bytes);
    steps.push(`распаковала ${name} -> ${path}`);
    return { apk: { path, name, size: bytes.length }, steps, source: `CI ${slug}` };
  } finally {
    await dispatcher?.close();
  }
}

// ------------------------------------------------------------------ delivery

/** Same order the telegram plugin uses: explicit id, first allowed chat, single known private chat. */
async function operatorChatId(runtime) {
  const explicit = runtime.getSetting?.('TELEGRAM_OPERATOR_CHAT_ID')?.trim();
  if (explicit) return explicit;
  const allowed = runtime
    .getSetting?.('TELEGRAM_ALLOWED_CHATS')
    ?.split(',')
    .map((s) => s.trim())
    .find(Boolean);
  if (allowed) return allowed;
  try {
    const dataDir = runtime.getSetting?.('LAINOS_DATA_DIR') ?? './data';
    const parsed = JSON.parse(await readFile(join(dataDir, 'telegram.json'), 'utf8'));
    const chats = parsed.chats ?? [];
    const privates = chats.filter((id) => id > 0);
    if (privates.length === 1) return String(privates[0]);
    if (chats.length === 1) return String(chats[0]);
  } catch {
    // No known chats yet.
  }
  return null;
}

/** POST sendDocument. The token is used here and never returned to the model. */
async function sendDocument(runtime, apk, caption, proxy) {
  const token = runtime.getSetting?.('TELEGRAM_BOT_TOKEN')?.trim();
  if (!token) throw new Error('TELEGRAM_BOT_TOKEN не задан на хосте');
  const chatId = await operatorChatId(runtime);
  if (!chatId) throw new Error('не знаю чат оператора (TELEGRAM_OPERATOR_CHAT_ID)');
  if (apk.size > TELEGRAM_MAX_UPLOAD) {
    throw new Error(`${mb(apk.size)} — больше лимита Bot API (50 MB), файл лежит в ${apk.path}`);
  }

  const form = new FormData();
  form.set('chat_id', chatId);
  if (caption) form.set('caption', caption.slice(0, 1000));
  form.set(
    'document',
    new File([await readFile(apk.path)], apk.name, {
      type: 'application/vnd.android.package-archive',
    })
  );

  const dispatcher = proxy ? new ProxyAgent(proxy) : undefined;
  try {
    const res = await undiciFetch(`https://api.telegram.org/bot${token}/sendDocument`, {
      method: 'POST',
      body: form,
      dispatcher,
      signal: AbortSignal.timeout(600_000),
    });
    const json = await res.json();
    if (!json.ok) throw new Error(`sendDocument ${res.status}: ${json.description ?? 'error'}`);
    return chatId;
  } finally {
    await dispatcher?.close();
  }
}

/** Never let a bot token reach the transcript, even through an error string. */
function scrub(message, runtime) {
  const token = runtime.getSetting?.('TELEGRAM_BOT_TOKEN');
  const gh = runtime.getSetting?.('GITHUB_TOKEN');
  let out = String(message);
  for (const secret of [token, gh]) {
    if (secret) out = out.split(secret).join('[token]');
  }
  return out;
}

// ------------------------------------------------------------------ skill

export default {
  name: 'android_apk',
  description:
    'Build the Cyberia Android app (frontend/mobile, Capacitor) and send the APK to the operator in Telegram as a file. ' +
    'source=build compiles locally (needs JDK 21 + Android SDK, takes minutes), source=ci downloads the APK built by ' +
    'GitHub Actions (needs GITHUB_TOKEN), source=auto builds and falls back to CI when the toolchain is missing. ' +
    'Reports the exact blocker instead of guessing, and never touches secrets.',
  similes: ['build_apk', 'send_apk', 'android_build', 'apk'],
  parameters: {
    type: 'object',
    properties: {
      source: {
        type: 'string',
        enum: ['auto', 'build', 'ci'],
        description: 'Where the APK comes from. Default auto: local build, CI artifact as fallback.',
      },
      variant: {
        type: 'string',
        enum: ['debug', 'release'],
        description: 'Build variant for a local build. Default debug (release is unsigned).',
      },
      send: {
        type: 'boolean',
        description: 'Deliver the APK to the operator in Telegram. Default true.',
      },
      caption: { type: 'string', description: 'Optional caption for the Telegram document.' },
    },
  },
  async handler(runtime, _state, params = {}) {
    const source = ['auto', 'build', 'ci'].includes(params.source) ? params.source : 'auto';
    const variant = params.variant === 'release' ? 'release' : 'debug';
    const send = params.send !== false;
    const proxy =
      runtime.getSetting?.('TELEGRAM_PROXY') ??
      runtime.getSetting?.('HTTPS_PROXY') ??
      runtime.getSetting?.('https_proxy');

    try {
      const repo = await findRepo(runtime);
      const mobileDir = join(repo, MOBILE_REL);
      const steps = [];
      let apk = null;
      let origin = `local ${variant}`;
      let buildBlocker = null;

      if (source !== 'ci') {
        const built = await buildApk(mobileDir, variant, runtime);
        steps.push(...built.steps);
        if (built.apk) apk = built.apk;
        else buildBlocker = built.blocked;
      }

      if (!apk && (source === 'ci' || (source === 'auto' && buildBlocker))) {
        if (buildBlocker) steps.push(`локальная сборка отпала: ${buildBlocker}`);
        const ci = await fetchCiApk(repo, runtime, proxy);
        steps.push(...ci.steps);
        if (ci.apk) {
          apk = ci.apk;
          origin = ci.source;
        } else if (source === 'ci') {
          return { ok: false, text: `APK из CI не получила: ${ci.blocked}`, data: { steps } };
        } else {
          return {
            ok: false,
            text: `APK не собрала и не скачала.\nЛокально: ${buildBlocker}\nCI: ${ci.blocked}`,
            data: { steps, buildBlocker, ciBlocker: ci.blocked },
          };
        }
      }

      if (!apk) return { ok: false, text: `APK не собрала: ${buildBlocker}`, data: { steps } };

      const summary = `${apk.name} — ${mb(apk.size)} (${origin})`;
      if (!send) {
        return {
          ok: true,
          text: `${summary}\nлежит в ${apk.path}\n${steps.join('\n')}`,
          data: { path: apk.path, name: apk.name, bytes: apk.size, origin, steps, sent: false },
        };
      }

      const caption = params.caption?.trim() || `Cyberia Android — ${apk.name} (${origin})`;
      const chatId = await sendDocument(runtime, apk, caption, proxy);
      return {
        ok: true,
        text: `отправила APK оператору (чат ${chatId}): ${summary}`,
        data: { path: apk.path, name: apk.name, bytes: apk.size, origin, steps, sent: true, chatId },
      };
    } catch (err) {
      return { ok: false, text: `android_apk: ${scrub(err.message, runtime)}` };
    }
  },
};
