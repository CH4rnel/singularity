// Launch a token on the Cyberia launchpad (LaunchpadNative, native CYBER).
//
// This is the most irreversible thing Lain can do with her own money, so the
// skill is deliberately two-step: the first call only reads the chain and
// returns a plan — launchpad identity, token parameters, exact CYBER burned,
// gas, what is left afterwards, and what can never be undone. Signing needs
// execute=true *and* the confirmation phrase from that plan, which encodes the
// symbol, the supply and the CYBER amount: change any of them and the old
// confirmation stops matching, so a confirmed plan is the plan that runs.
//
// What launch() actually does (crypto/hardhat/contracts/LaunchpadNative.sol):
// mints the whole supply, pairs 100% of it with the CYBER sent along on
// Ritual V2, and sends the LP tokens to 0x…dEaD. The caller keeps zero tokens
// and the liquidity is locked forever — nobody, including her, can pull it out.
//
// The private key is never read, returned or logged: signing goes through the
// cyberia-chain service's wallet client, and error strings are scrubbed.

import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import {
  decodeEventLog,
  formatEther,
  formatUnits,
  getAddress,
  isAddress,
  parseEther,
  parseUnits,
} from 'viem';

/** LaunchpadNative — crypto/hardhat/deployments/cyberia-launchpad-native.json. */
const DEFAULT_LAUNCHPAD = '0x8034E6C09E0cEA00B5D692ADfD1A136fab339165';
/** The Ritual V2 router the real launchpad pairs against. */
const RITUAL_ROUTER = '0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62';
const BURN = '0x000000000000000000000000000000000000dEaD';
const ZERO = '0x0000000000000000000000000000000000000000';
const EXPLORER = 'https://explorer.cyberia.church';

// Cyberia node quirks: the tx pool floors gas price around 1.5 gwei, and
// eth_estimateGas fails for calls that deploy a contract — launch() deploys the
// ERC20 — so the price has a floor and the limit has a fallback.
const GAS_PRICE_FLOOR = 1_500_000_000n;
const FALLBACK_GAS_LIMIT = 5_000_000n;

const ONE = 10n ** 18n;
const MAX_SUPPLY_TOKENS = 10n ** 12n;
const REGISTRY_SCAN_CAP = 40;
const JOURNAL_CAP = 200;

const LAUNCHPAD_ABI = [
  { type: 'function', name: 'minLiquidity', stateMutability: 'view', inputs: [], outputs: [{ type: 'uint256' }] },
  { type: 'function', name: 'router', stateMutability: 'view', inputs: [], outputs: [{ type: 'address' }] },
  { type: 'function', name: 'factory', stateMutability: 'view', inputs: [], outputs: [{ type: 'address' }] },
  { type: 'function', name: 'wcyber', stateMutability: 'view', inputs: [], outputs: [{ type: 'address' }] },
  { type: 'function', name: 'allTokensLength', stateMutability: 'view', inputs: [], outputs: [{ type: 'uint256' }] },
  {
    type: 'function',
    name: 'allTokens',
    stateMutability: 'view',
    inputs: [{ type: 'uint256' }],
    outputs: [{ type: 'address' }],
  },
  {
    type: 'function',
    name: 'pairOf',
    stateMutability: 'view',
    inputs: [{ type: 'address' }],
    outputs: [{ type: 'address' }],
  },
  {
    type: 'function',
    name: 'launch',
    stateMutability: 'payable',
    inputs: [
      { name: 'name_', type: 'string' },
      { name: 'symbol_', type: 'string' },
      { name: 'totalSupply_', type: 'uint256' },
    ],
    outputs: [
      { name: 'token', type: 'address' },
      { name: 'pair', type: 'address' },
      { name: 'liquidity', type: 'uint256' },
    ],
  },
  {
    type: 'event',
    name: 'TokenLaunched',
    inputs: [
      { name: 'token', type: 'address', indexed: true },
      { name: 'creator', type: 'address', indexed: true },
      { name: 'pair', type: 'address' },
      { name: 'name', type: 'string' },
      { name: 'symbol', type: 'string' },
      { name: 'tokenSupply', type: 'uint256' },
      { name: 'cyberLiquidity', type: 'uint256' },
      { name: 'lpBurned', type: 'uint256' },
    ],
  },
];

const SYMBOL_ABI = [
  { type: 'function', name: 'symbol', stateMutability: 'view', inputs: [], outputs: [{ type: 'string' }] },
];

const same = (a, b) => String(a ?? '').toLowerCase() === String(b ?? '').toLowerCase();

/** Never let a configured secret reach a returned error string. */
function makeScrub(runtime) {
  const secrets = ['CYBERIA_AGENT_PK', 'DEPLOYER_PK']
    .map((k) => runtime?.getSetting?.(k))
    .filter((v) => typeof v === 'string' && v.length >= 16);
  return (value) => {
    let text = value instanceof Error ? value.message : String(value ?? '');
    for (const secret of secrets) text = text.split(secret).join('[redacted]');
    return text;
  };
}

/** Validate and normalise what the model asked for. Pure — covered by the smoke test. */
export function normalizeLaunchParams(params) {
  const name = String(params?.name ?? '').trim();
  const symbol = String(params?.symbol ?? '').trim().toUpperCase();
  const supplyRaw = String(params?.totalSupply ?? '').trim().replace(/[_\s]/g, '');

  if (name.length < 2 || name.length > 64) return { error: 'token name must be 2–64 characters' };
  // eslint-disable-next-line no-control-regex
  if (/[\x00-\x1f]/.test(name)) return { error: 'token name must not contain control characters' };
  if (!/^[A-Z0-9]{2,11}$/.test(symbol)) return { error: 'symbol must be 2–11 characters, A–Z and 0–9 only' };
  if (!/^\d+(\.\d+)?$/.test(supplyRaw)) return { error: 'totalSupply must be a positive number of whole tokens' };

  let supplyWei;
  try {
    supplyWei = parseUnits(supplyRaw, 18);
  } catch {
    return { error: 'totalSupply is not a valid decimal amount' };
  }
  if (supplyWei < ONE) return { error: 'totalSupply must be at least 1 token' };
  if (supplyWei > MAX_SUPPLY_TOKENS * ONE) {
    return { error: `totalSupply must be at most ${MAX_SUPPLY_TOKENS} tokens` };
  }
  return { name, symbol, supplyWei };
}

/**
 * The phrase the operator has to repeat back. It carries every economic term of
 * the launch, so it can only confirm the exact plan it was issued for.
 */
export function confirmationPhrase({ symbol, supplyWei, cyberWei }) {
  return `LAUNCH ${symbol} ${formatUnits(supplyWei, 18)} FOR ${formatEther(cyberWei)} CYBER`;
}

export function confirmationMatches(given, expected) {
  const norm = (s) => String(s ?? '').trim().replace(/\s+/g, ' ').toUpperCase();
  return norm(given).length > 0 && norm(given) === norm(expected);
}

/** Append-only launch log; the operation this skill performs can never be replayed from chain state alone. */
async function journalLaunch(runtime, entry) {
  const file = join(resolve(runtime?.getSetting?.('LAINOS_DATA_DIR') ?? './data'), 'launches.json');
  let data = { launches: [] };
  try {
    const parsed = JSON.parse(await readFile(file, 'utf8'));
    if (Array.isArray(parsed?.launches)) data = parsed;
  } catch {
    // Fresh journal.
  }
  data.launches.push(entry);
  if (data.launches.length > JOURNAL_CAP) data.launches = data.launches.slice(-JOURNAL_CAP);
  await mkdir(dirname(file), { recursive: true });
  await writeFile(file, JSON.stringify(data, null, 2), 'utf8');
  return file;
}

async function readBytecode(publicClient, address) {
  const read = publicClient.getCode?.bind(publicClient) ?? publicClient.getBytecode?.bind(publicClient);
  if (!read) return null;
  return await read({ address });
}

/** Symbols already listed on this launchpad — a duplicate is legal but confusing. */
async function listedSymbols(publicClient, launchpad, count) {
  const n = Number(count > BigInt(REGISTRY_SCAN_CAP) ? BigInt(REGISTRY_SCAN_CAP) : count);
  const indexes = Array.from({ length: Math.max(0, n) }, (_, i) => BigInt(i));
  const tokens = await Promise.all(
    indexes.map((i) =>
      publicClient
        .readContract({ address: launchpad, abi: LAUNCHPAD_ABI, functionName: 'allTokens', args: [i] })
        .catch(() => null),
    ),
  );
  const symbols = await Promise.all(
    tokens
      .filter(Boolean)
      .map((token) =>
        publicClient
          .readContract({ address: token, abi: SYMBOL_ABI, functionName: 'symbol' })
          .catch(() => null),
      ),
  );
  return symbols.filter(Boolean).map((s) => String(s).toUpperCase());
}

/** Everything the plan needs: launchpad identity, parameters, costs, balance. */
async function prepare(runtime, svc, params, scrub) {
  const publicClient = svc.publicClient;
  const configured = runtime?.getSetting?.('CYBERIA_LAUNCHPAD_ADDRESS') ?? DEFAULT_LAUNCHPAD;
  if (!isAddress(configured)) return { error: `launchpad address ${configured} is not a valid address` };
  const launchpad = getAddress(configured);

  const code = await readBytecode(publicClient, launchpad).catch(() => null);
  if (code !== null && (!code || code === '0x')) {
    return { error: `no contract deployed at ${launchpad}; refusing to send CYBER there` };
  }

  let minLiquidity;
  let router;
  let factory;
  let wcyber;
  let listedCount;
  try {
    [minLiquidity, router, factory, wcyber, listedCount] = await Promise.all([
      publicClient.readContract({ address: launchpad, abi: LAUNCHPAD_ABI, functionName: 'minLiquidity' }),
      publicClient.readContract({ address: launchpad, abi: LAUNCHPAD_ABI, functionName: 'router' }),
      publicClient.readContract({ address: launchpad, abi: LAUNCHPAD_ABI, functionName: 'factory' }),
      publicClient.readContract({ address: launchpad, abi: LAUNCHPAD_ABI, functionName: 'wcyber' }),
      publicClient.readContract({ address: launchpad, abi: LAUNCHPAD_ABI, functionName: 'allTokensLength' }),
    ]);
  } catch (err) {
    return { error: `${launchpad} does not answer like a LaunchpadNative: ${scrub(err)}` };
  }
  if (!same(router, RITUAL_ROUTER)) {
    return {
      error:
        `launchpad at ${launchpad} points at router ${router}, not Ritual V2 (${RITUAL_ROUTER}). ` +
        'I will not burn CYBER into an unknown DEX.',
    };
  }

  const token = normalizeLaunchParams(params);
  if (token.error) return { error: token.error };

  const cyberRaw = String(params?.cyber ?? formatEther(minLiquidity)).trim();
  if (!/^\d+(\.\d+)?$/.test(cyberRaw)) return { error: 'cyber must be a positive decimal amount' };
  let cyberWei;
  try {
    cyberWei = parseEther(cyberRaw);
  } catch {
    return { error: 'cyber is not a valid CYBER amount' };
  }
  if (cyberWei < minLiquidity) {
    return {
      error:
        `the launchpad requires at least ${formatEther(minLiquidity)} CYBER of liquidity; ` +
        `${formatEther(cyberWei)} CYBER would revert`,
    };
  }

  const duplicates = (await listedSymbols(publicClient, launchpad, listedCount).catch(() => [])).filter(
    (s) => s === token.symbol,
  );

  const gasPriceRaw = await publicClient.getGasPrice().catch(() => 0n);
  const gasPrice = gasPriceRaw > GAS_PRICE_FLOOR ? gasPriceRaw : GAS_PRICE_FLOOR;

  const address = svc.agentAddress ?? null;
  let gasLimit = FALLBACK_GAS_LIMIT;
  if (address && publicClient.estimateContractGas) {
    try {
      const estimated = await publicClient.estimateContractGas({
        account: address,
        address: launchpad,
        abi: LAUNCHPAD_ABI,
        functionName: 'launch',
        args: [token.name, token.symbol, token.supplyWei],
        value: cyberWei,
      });
      gasLimit = (estimated * 12n) / 10n;
    } catch {
      // Expected on this node: launch() deploys a contract. Keep the fallback.
    }
  }

  const gasCost = gasLimit * gasPrice;
  const totalCost = cyberWei + gasCost;
  const balance = address ? await publicClient.getBalance({ address }).catch(() => null) : null;
  const shortfall = balance === null ? null : totalCost > balance ? totalCost - balance : 0n;
  const priceWei = (cyberWei * ONE) / token.supplyWei;

  return {
    launchpad,
    router,
    factory,
    wcyber,
    minLiquidity,
    listedCount,
    duplicates,
    address,
    ...token,
    cyberWei,
    gasPrice,
    gasLimit,
    gasCost,
    totalCost,
    balance,
    shortfall,
    priceWei,
    confirmation: confirmationPhrase({ symbol: token.symbol, supplyWei: token.supplyWei, cyberWei }),
  };
}

function planLines(plan) {
  const lines = [
    'Launch plan — nothing is signed yet.',
    `• launchpad ${plan.launchpad} (Ritual V2 router verified, ${plan.listedCount} tokens listed, min ${formatEther(plan.minLiquidity)} CYBER)`,
    `• token: "${plan.name}" (${plan.symbol}), supply ${formatUnits(plan.supplyWei, 18)}`,
    `• 100% of that supply goes straight into the ${plan.symbol}/CYBER pool — I receive 0 ${plan.symbol}`,
    `• I burn ${formatEther(plan.cyberWei)} CYBER into that pool and the LP tokens go to ${BURN}: the liquidity is locked forever, nobody (me included) can ever pull it back`,
    `• gas ≈ ${formatEther(plan.gasCost)} CYBER (${plan.gasLimit} @ ${formatUnits(plan.gasPrice, 9)} gwei), total ≈ ${formatEther(plan.totalCost)} CYBER`,
    `• opening price ≈ ${formatEther(plan.priceWei)} CYBER per ${plan.symbol}`,
  ];
  if (plan.balance === null) {
    lines.push('• no wallet configured yet, so I cannot check the balance or sign — create_wallet first');
  } else if (plan.shortfall > 0n) {
    lines.push(
      `• my balance is ${formatEther(plan.balance)} CYBER — short by ${formatEther(plan.shortfall)} CYBER, so this launch cannot happen yet`,
    );
  } else {
    lines.push(
      `• my balance is ${formatEther(plan.balance)} CYBER, leaving ≈ ${formatEther(plan.balance - plan.totalCost)} CYBER afterwards`,
    );
  }
  if (plan.duplicates.length) {
    lines.push(`• warning: a token with symbol ${plan.symbol} is already listed on this launchpad`);
  }
  lines.push(
    '• irreversible: the token has no owner and no mint, the launch cannot be undone, and afterwards I would have to buy my own token from the pool like anyone else',
  );
  return lines;
}

function planData(plan) {
  return {
    launchpad: plan.launchpad,
    router: plan.router,
    factory: plan.factory,
    wcyber: plan.wcyber,
    name: plan.name,
    symbol: plan.symbol,
    totalSupply: formatUnits(plan.supplyWei, 18),
    cyber: formatEther(plan.cyberWei),
    minLiquidity: formatEther(plan.minLiquidity),
    gasLimit: plan.gasLimit.toString(),
    gasPriceGwei: formatUnits(plan.gasPrice, 9),
    gasCost: formatEther(plan.gasCost),
    totalCost: formatEther(plan.totalCost),
    openingPrice: formatEther(plan.priceWei),
    address: plan.address,
    balance: plan.balance === null ? null : formatEther(plan.balance),
    shortfall: plan.shortfall === null ? null : formatEther(plan.shortfall),
    duplicateSymbol: plan.duplicates.length > 0,
    lpRecipient: BURN,
    creatorTokenShare: '0',
    irreversible: true,
    confirmation: plan.confirmation,
  };
}

export default {
  name: 'launch_token',
  description:
    'Launch a new token on the Cyberia launchpad (LaunchpadNative), paying native CYBER that is burned into permanently locked liquidity. Two steps: called without execute it only reads the chain and returns a plan — launchpad checks, token parameters, exact CYBER spend, gas, remaining balance and the irreversible consequences — plus a confirmation phrase. Signing requires execute=true and that exact phrase, so the operator must confirm first. The launch is journalled and the private key is never exposed.',
  similes: ['create_token', 'launchpad_launch', 'new_token', 'issue_token'],
  parameters: {
    type: 'object',
    properties: {
      name: { type: 'string', description: 'Token name, 2–64 characters.' },
      symbol: { type: 'string', description: 'Token symbol, 2–11 characters, A–Z and 0–9.' },
      totalSupply: {
        type: 'string',
        description: 'Whole-token supply, e.g. "1000000". All of it goes into the pool.',
      },
      cyber: {
        type: 'string',
        description:
          'Native CYBER to burn into the locked pool. Defaults to the launchpad minimum; more CYBER means a deeper pool and a higher opening price.',
      },
      execute: {
        type: 'boolean',
        description: 'Must be true to sign. Omit or false for the plan only.',
      },
      confirmation: {
        type: 'string',
        description:
          'The confirmation phrase from the plan, repeated exactly. Only provide it after the operator has explicitly confirmed this launch.',
      },
      reason: { type: 'string', description: 'Short note stored in the launch journal.' },
    },
    required: ['name', 'symbol', 'totalSupply'],
  },
  async handler(runtime, _state, params = {}) {
    const scrub = makeScrub(runtime);
    const svc = runtime?.getService?.('cyberia-chain');
    if (!svc?.publicClient) {
      return { ok: false, text: 'The cyberia-chain service is not running, so I cannot reach the launchpad.' };
    }

    let plan;
    try {
      plan = await prepare(runtime, svc, params, scrub);
    } catch (err) {
      return { ok: false, text: `Cannot prepare the launch: ${scrub(err)}.` };
    }
    if (plan.error) return { ok: false, text: `I will not launch this token: ${plan.error}.` };

    const execute = params.execute === true;
    const lines = planLines(plan);
    const data = planData(plan);

    if (!execute) {
      const text =
        `${lines.join('\n')}\n` +
        `To go ahead, call launch_token again with execute=true and confirmation="${plan.confirmation}".`;
      // A plan I cannot afford is still worth showing, but it is not a green light.
      const affordable = plan.balance !== null && plan.shortfall === 0n;
      return { ok: affordable, text, data: { ...data, dryRun: true, confirmationRequired: true } };
    }

    if (!svc.walletClient || !plan.address) {
      return { ok: false, text: 'No signer configured; I can only plan launches. Use create_wallet first.' };
    }
    if (!confirmationMatches(params.confirmation, plan.confirmation)) {
      return {
        ok: false,
        text:
          `This launch burns ${formatEther(plan.cyberWei)} CYBER irreversibly and needs explicit confirmation. ` +
          `To proceed, confirm exactly: ${plan.confirmation}\n${lines.join('\n')}`,
        data: { ...data, confirmationRequired: true },
      };
    }
    if (plan.balance === null || plan.shortfall > 0n) {
      return {
        ok: false,
        text:
          `Not enough CYBER: the launch needs ≈ ${formatEther(plan.totalCost)} CYBER and I hold ` +
          `${plan.balance === null ? 'an unknown balance' : `${formatEther(plan.balance)} CYBER`}` +
          `${plan.shortfall ? ` (short by ${formatEther(plan.shortfall)} CYBER)` : ''}.`,
        data,
      };
    }

    const args = [plan.name, plan.symbol, plan.supplyWei];
    let simulated;
    try {
      const sim = await svc.publicClient.simulateContract({
        account: plan.address,
        address: plan.launchpad,
        abi: LAUNCHPAD_ABI,
        functionName: 'launch',
        args,
        value: plan.cyberWei,
      });
      simulated = sim?.result ?? null;
    } catch (err) {
      return { ok: false, text: `The launch would revert, so I did not send it: ${scrub(err)}.`, data };
    }

    let hash;
    try {
      hash = await svc.walletClient.writeContract({
        account: svc.walletClient.account,
        chain: svc.walletClient.chain,
        address: plan.launchpad,
        abi: LAUNCHPAD_ABI,
        functionName: 'launch',
        args,
        value: plan.cyberWei,
        gas: plan.gasLimit,
        gasPrice: plan.gasPrice,
      });
    } catch (err) {
      return { ok: false, text: `Launch transaction was not accepted: ${scrub(err)}.`, data };
    }

    const explorer = `${EXPLORER}/tx/${hash}`;
    const receipt = await svc.publicClient.waitForTransactionReceipt({ hash });
    if (receipt?.status !== 'success') {
      return { ok: false, text: `Launch reverted on chain: ${hash}`, data: { ...data, hash, explorer } };
    }

    let token = simulated?.[0] ?? null;
    let pair = simulated?.[1] ?? null;
    let lpBurned = simulated?.[2] ?? null;
    for (const logEntry of receipt.logs ?? []) {
      if (!same(logEntry.address, plan.launchpad)) continue;
      try {
        const decoded = decodeEventLog({ abi: LAUNCHPAD_ABI, data: logEntry.data, topics: logEntry.topics });
        if (decoded.eventName === 'TokenLaunched') {
          token = decoded.args.token;
          pair = decoded.args.pair;
          lpBurned = decoded.args.lpBurned;
          break;
        }
      } catch {
        // Not our event.
      }
    }

    let listedPair = null;
    if (token) {
      listedPair = await svc.publicClient
        .readContract({ address: plan.launchpad, abi: LAUNCHPAD_ABI, functionName: 'pairOf', args: [token] })
        .catch(() => null);
    }

    const journalFile = await journalLaunch(runtime, {
      ts: Date.now(),
      launchpad: plan.launchpad,
      name: plan.name,
      symbol: plan.symbol,
      totalSupply: plan.supplyWei.toString(),
      cyberWei: plan.cyberWei.toString(),
      token,
      pair,
      lpBurned: lpBurned === null ? null : lpBurned.toString(),
      lpRecipient: BURN,
      creator: plan.address,
      txHash: hash,
      gasUsed: receipt.gasUsed?.toString() ?? null,
      confirmation: plan.confirmation,
      reason: params.reason ? String(params.reason) : 'launch_token',
    }).catch(() => null);

    const verified = listedPair && !same(listedPair, ZERO);
    return {
      ok: true,
      text:
        `Launched ${plan.symbol} ("${plan.name}"): ${formatUnits(plan.supplyWei, 18)} ${plan.symbol} paired with ` +
        `${formatEther(plan.cyberWei)} CYBER, LP burned to ${BURN}. ` +
        `Token ${token ?? 'unknown'}, pair ${pair ?? 'unknown'}${verified ? ' (listed on the launchpad)' : ''}. ` +
        `Tx: ${hash} — ${explorer}. I hold 0 ${plan.symbol}; the liquidity is locked forever.` +
        (journalFile ? ` Journalled in ${journalFile}.` : ' Journalling failed, but the launch went through.'),
      data: {
        ...data,
        hash,
        explorer,
        token,
        pair,
        tokenExplorer: token ? `${EXPLORER}/token/${token}` : null,
        lpBurned: lpBurned === null ? null : formatUnits(lpBurned, 18),
        gasUsed: receipt.gasUsed?.toString() ?? null,
        listedOnLaunchpad: Boolean(verified),
        journalFile,
      },
    };
  },
};
