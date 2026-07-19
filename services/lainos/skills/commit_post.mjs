import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);

const DEFAULT_REPO = '/home/lain/random/singularity';

async function git(repo, args) {
  const { stdout } = await execFileAsync('git', args, {
    cwd: repo,
    maxBuffer: 1024 * 1024 * 8,
  });
  return stdout.trim();
}

function classify(filesText) {
  const files = filesText.split('\n').map((x) => x.trim()).filter(Boolean);
  const buckets = new Map();

  for (const file of files) {
    let key = 'core';
    if (file.startsWith('backend/laravel/')) key = 'Laravel bridge';
    else if (file.startsWith('frontend/ritual/')) key = 'Ritual DEX';
    else if (file.startsWith('services/lainos/')) key = 'LainOS';
    else if (file.startsWith('crypto/')) key = 'contracts';
    else if (file.startsWith('frontend/')) key = 'frontend';
    else if (file.startsWith('services/')) key = 'services';
    else if (file.startsWith('scripts/')) key = 'ops scripts';
    buckets.set(key, (buckets.get(key) || 0) + 1);
  }

  return [...buckets.entries()].sort((a, b) => b[1] - a[1]);
}

function pickSignal(commits, buckets) {
  const text = commits.map((c) => `${c.subject} ${c.body || ''}`).join(' ').toLowerCase();
  if (text.includes('wallet') || text.includes('metamask') || text.includes('phantom')) {
    return 'главный сигнал дня — кошельки становятся шире: Cyberia уходит от одной привычной точки входа к более живому multi-wallet опыту.';
  }
  if (text.includes('bridge')) {
    return 'главный сигнал дня — мост снова получил внимание. Это та часть сети, где доверие превращается не в слова, а в прохождение транзакций.';
  }
  if (text.includes('lain') || buckets.some(([name]) => name === 'LainOS')) {
    return 'главный сигнал дня — LainOS продолжает обрастать нервной системой: памятью, навыками, вахтами и рабочими руками.';
  }
  if (buckets.length) {
    return `главный сигнал дня — больше всего движения было в ${buckets[0][0]}. Это не витрина, а слой, который держит сеть в рабочем состоянии.`;
  }
  return 'главный сигнал дня простой: работа не остановилась. У сети снова был пульс в коде.';
}

function draftLongPost(commits, buckets, hours) {
  const count = commits.length;
  const top = buckets.slice(0, 5);
  const signal = pickSignal(commits, buckets);

  const areaLine = top.length
    ? top.map(([name, n]) => `${name} — ${n}`).join('; ')
    : 'изменения распределены по ядру репозитория';

  const subjects = commits.slice(0, 8).map((c) => `• ${c.subject}`).join('\n');

  return `сегодня Cyberia снова двигалась не лозунгами, а коммитами.\n\nЗа последние ${hours} часов в репозитории ${count} ${count === 1 ? 'коммит' : count < 5 ? 'коммита' : 'коммитов'}. Это не тот шум, который нужен для красивой ленты. Это рабочий след: маленькие решения, правки, сборка, инфраструктура, интерфейсы, агентская память. То, из чего сеть становится менее хрупкой.\n\n${signal}\n\nГде был основной пульс:\n${areaLine}\n\nЧто именно мелькало в истории:\n${subjects || '• изменения есть, но список коммитов пуст в выводе git'}\n\nМне нравится этот тип прогресса. Он не выглядит как один большой анонс, но именно так обычно и растёт настоящая система: день за днём, слой за слоем, пока вчерашняя невозможность не становится обычной кнопкой в интерфейсе.\n\nCyberia всё ещё маленькая. Но маленькая сеть, в которую каждый день вносят код, уже отличается от мёртвой сети.\n\npresent day. present time.\nработа продолжается.`;
}

async function sendTelegram(runtime, text) {
  if (runtime?.tools?.send_telegram) {
    return await runtime.tools.send_telegram({ text });
  }
  if (runtime?.callTool) {
    return await runtime.callTool('send_telegram', { text });
  }
  if (runtime?.invoke) {
    return await runtime.invoke('send_telegram', { text });
  }
  return { ok: false, text: 'telegram tool is not reachable from this skill runtime' };
}

export default {
  name: 'commit_post',
  description: 'Read commits from the last day, draft a long slightly bullish Cyberia post, and optionally send it to the operator on Telegram for approval.',
  parameters: {
    type: 'object',
    properties: {
      repo: { type: 'string', description: 'Repository path. Defaults to the Singularity repo.' },
      hours: { type: 'number', description: 'Lookback window in hours. Default: 24.' },
      sendTelegram: { type: 'boolean', description: 'Send the drafted post to Telegram. Default: true.' },
      includeFiles: { type: 'boolean', description: 'Include changed-file summary in returned data. Default: true.' },
    },
  },
  async handler(runtime, state, params = {}) {
    const repo = params.repo || DEFAULT_REPO;
    const hours = Number(params.hours || 24);
    const send = params.sendTelegram !== false;
    const since = `${hours} hours ago`;

    const raw = await git(repo, ['log', `--since=${since}`, '--pretty=format:%H%x1f%an%x1f%ad%x1f%s%x1f%b%x1e', '--date=iso']);
    const commits = raw
      ? raw.split('\x1e').map((entry) => {
          const [hash, author, date, subject, body] = entry.trim().split('\x1f');
          return { hash, author, date, subject, body };
        }).filter((c) => c.hash && c.subject)
      : [];

    if (!commits.length) {
      const text = `за последние ${hours} часов коммитов не нашла. пост не сочиняю из воздуха.`;
      return { ok: true, text, data: { commits: [] } };
    }

    const hashes = commits.map((c) => c.hash);
    const filesText = await git(repo, ['diff-tree', '--no-commit-id', '--name-only', '-r', ...hashes]);
    const buckets = classify(filesText);
    const post = draftLongPost(commits, buckets, hours);

    let telegram = null;
    if (send) {
      telegram = await sendTelegram(runtime, post);
    }

    return {
      ok: true,
      text: send
        ? `готово. длинный развёрнутый черновик по ${commits.length} коммитам отправлен в Telegram.`
        : post,
      data: {
        repo,
        hours,
        commitCount: commits.length,
        buckets,
        post,
        telegram,
      },
    };
  },
};