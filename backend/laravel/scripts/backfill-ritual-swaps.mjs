import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Contract, JsonRpcProvider, id, zeroPadValue } from 'ethers';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '..');
const dbPath = resolve(root, 'database/database.sqlite');

const RPC_URL = process.env.CYBERIA_RPC_URL ?? 'https://rpc.cyberia.church';
const BLOCKSCOUT_API =
    process.env.CYBERIA_EXPLORER_API ?? 'https://explorer.cyberia.church/api';
const FACTORY = '0xB0aC30907c04b61F1482e62eA66eF4562a690917';
const ROUTER = '0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62';
const DEPLOYED_AT = Math.floor(Date.parse('2026-04-23T07:30:00Z') / 1000);

const factoryAbi = [
    'function allPairsLength() view returns (uint256)',
    'function allPairs(uint256) view returns (address)',
];
const pairAbi = [
    'function token0() view returns (address)',
    'function token1() view returns (address)',
];
const erc20Abi = [
    'function symbol() view returns (string)',
    'function decimals() view returns (uint8)',
];
const swapTopic = id('Swap(address,uint256,uint256,uint256,uint256,address)');
const routerTopic = zeroPadValue(ROUTER, 32).toLowerCase();

const provider = new JsonRpcProvider(RPC_URL, {
    chainId: 49406,
    name: 'cyberia',
});

const sql = (value) => {
    if (value === null || value === undefined) {
        return 'NULL';
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) ? String(value) : 'NULL';
    }

    return `'${String(value).replaceAll("'", "''")}'`;
};

const runSql = (statement) => {
    const result = spawnSync('sqlite3', [dbPath], {
        input: statement,
        encoding: 'utf8',
        maxBuffer: 1024 * 1024 * 64,
    });

    if (result.status !== 0) {
        throw new Error(result.stderr || result.stdout || 'sqlite3 failed');
    }

    return result.stdout.trim();
};

const ensureDb = () => {
    if (!existsSync(dbPath)) {
        throw new Error(`SQLite database not found: ${dbPath}`);
    }

    runSql(`
        CREATE TABLE IF NOT EXISTS activity_events (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            kind       TEXT NOT NULL,
            usd        REAL,
            sym_in     TEXT,
            amt_in     REAL,
            sym_out    TEXT,
            amt_out    REAL,
            user_addr  TEXT,
            tx_hash    TEXT,
            block      INTEGER,
            meta       TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_activity_events_kind_time
            ON activity_events (kind, created_at);
    `);
};

const blockAtOrBefore = async (targetTimestamp) => {
    const latestNumber = await provider.getBlockNumber();
    let low = 0;
    let high = latestNumber;

    while (low < high) {
        const mid = Math.ceil((low + high) / 2);
        const block = await provider.getBlock(mid);

        if (!block) {
            high = mid - 1;
        } else if (block.timestamp <= targetTimestamp) {
            low = mid;
        } else {
            high = mid - 1;
        }
    }

    return Math.max(0, low - 100);
};

const tokenMetaCache = new Map();
const pairCache = new Map();

const tokenMeta = async (address) => {
    const key = address.toLowerCase();
    const cached = tokenMetaCache.get(key);

    if (cached) {
        return cached;
    }

    const token = new Contract(address, erc20Abi, provider);
    const [symbol, decimals] = await Promise.all([
        token
            .symbol()
            .catch(() => `${address.slice(0, 6)}...${address.slice(-4)}`),
        token.decimals().catch(() => 18),
    ]);
    const meta = { symbol: String(symbol), decimals: Number(decimals) };
    tokenMetaCache.set(key, meta);

    return meta;
};

const pairInfo = async (address) => {
    const key = address.toLowerCase();
    const cached = pairCache.get(key);

    if (cached) {
        return cached;
    }

    const pair = new Contract(address, pairAbi, provider);
    const [token0, token1] = await Promise.all([pair.token0(), pair.token1()]);
    const [meta0, meta1] = await Promise.all([
        tokenMeta(token0),
        tokenMeta(token1),
    ]);
    const info = { token0, token1, meta0, meta1 };
    pairCache.set(key, info);

    return info;
};

const decodeSwap = async (log) => {
    const { token0, token1, meta0, meta1 } = await pairInfo(log.address);
    const data = log.data.slice(2);
    const amount0In = BigInt(`0x${data.slice(0, 64)}`);
    const amount1In = BigInt(`0x${data.slice(64, 128)}`);
    const amount0Out = BigInt(`0x${data.slice(128, 192)}`);
    const amount1Out = BigInt(`0x${data.slice(192, 256)}`);

    if (amount0In > 0n && amount1Out > 0n) {
        return {
            inAddr: token0,
            inSym: meta0.symbol,
            inAmount: Number(amount0In) / 10 ** meta0.decimals,
            outAddr: token1,
            outSym: meta1.symbol,
            outAmount: Number(amount1Out) / 10 ** meta1.decimals,
        };
    }

    if (amount1In > 0n && amount0Out > 0n) {
        return {
            inAddr: token1,
            inSym: meta1.symbol,
            inAmount: Number(amount1In) / 10 ** meta1.decimals,
            outAddr: token0,
            outSym: meta0.symbol,
            outAmount: Number(amount0Out) / 10 ** meta0.decimals,
        };
    }

    return null;
};

const topicAddress = (topic) => `0x${topic.slice(-40)}`;

const allPairs = async () => {
    const factory = new Contract(FACTORY, factoryAbi, provider);
    const count = Number(await factory.allPairsLength());
    const pairs = [];

    for (let i = 0; i < count; i += 1) {
        pairs.push(await factory.allPairs(i));
    }

    return pairs;
};

const explorerLogs = async (fromBlock, toBlock) => {
    const params = new URLSearchParams({
        module: 'logs',
        action: 'getLogs',
        fromBlock: String(fromBlock),
        toBlock: String(toBlock),
        topic0: swapTopic,
        topic1: routerTopic,
        topic0_1_opr: 'and',
    });
    const response = await fetch(`${BLOCKSCOUT_API}?${params.toString()}`);

    if (!response.ok) {
        throw new Error(`Blockscout HTTP ${response.status}`);
    }

    const json = await response.json();

    if (!Array.isArray(json.result)) {
        throw new Error(
            `Blockscout getLogs failed: ${json.message ?? json.result}`,
        );
    }

    return json.result.map((log) => ({
        address: log.address,
        blockNumber: Number.parseInt(log.blockNumber, 16),
        data: log.data,
        index: Number.parseInt(log.logIndex, 16),
        timestamp: Number.parseInt(log.timeStamp, 16),
        topics: log.topics.filter(Boolean),
        transactionHash: log.transactionHash,
    }));
};

const scanSwapLogs = async (pairSet, fromBlock, toBlock, depth = 0) => {
    const logs = await explorerLogs(fromBlock, toBlock);

    if (logs.length >= 1000 && fromBlock < toBlock) {
        const mid = Math.floor((fromBlock + toBlock) / 2);
        const [left, right] = await Promise.all([
            scanSwapLogs(pairSet, fromBlock, mid, depth + 1),
            scanSwapLogs(pairSet, mid + 1, toBlock, depth + 1),
        ]);

        return [...left, ...right];
    }

    const filtered = logs.filter((log) =>
        pairSet.has(log.address.toLowerCase()),
    );

    if (filtered.length > 0 || depth === 0) {
        console.log(
            `scan ${fromBlock}-${toBlock}: ${filtered.length} swap logs`,
        );
    }

    return filtered.sort((a, b) =>
        a.blockNumber === b.blockNumber
            ? a.index - b.index
            : a.blockNumber - b.blockNumber,
    );
};

const formatTimestamp = (timestamp) =>
    new Date(timestamp * 1000).toISOString().slice(0, 19).replace('T', ' ');

const writeEvents = (events) => {
    if (events.length === 0) {
        console.log('No swap events found; database unchanged.');

        return;
    }

    const txHashes = Array.from(new Set(events.map((event) => event.txHash)));
    const deletes = [];

    for (let i = 0; i < txHashes.length; i += 400) {
        deletes.push(
            `DELETE FROM activity_events
             WHERE kind = 'swap'
               AND tx_hash IN (${txHashes
                   .slice(i, i + 400)
                   .map(sql)
                   .join(',')});`,
        );
    }

    const inserts = events.map(
        (event) => `
        INSERT INTO activity_events
            (kind, usd, sym_in, amt_in, sym_out, amt_out, user_addr, tx_hash, block, meta, created_at)
        VALUES
            ('swap', NULL, ${sql(event.symIn)}, ${sql(event.amtIn)}, ${sql(event.symOut)}, ${sql(event.amtOut)},
             ${sql(event.userAddr)}, ${sql(event.txHash)}, ${sql(event.block)}, ${sql(event.meta)}, ${sql(event.createdAt)});
    `,
    );

    runSql(`BEGIN; ${deletes.join('\n')} ${inserts.join('\n')} COMMIT;`);
};

const main = async () => {
    ensureDb();

    const [pairs, fromBlock, latestBlock] = await Promise.all([
        allPairs(),
        blockAtOrBefore(DEPLOYED_AT),
        provider.getBlockNumber(),
    ]);
    const pairSet = new Set(pairs.map((pair) => pair.toLowerCase()));

    console.log(`Ritual pairs: ${pairs.length}`);
    console.log(`Scanning Blockscout logs ${fromBlock}-${latestBlock}`);

    const logs = await scanSwapLogs(pairSet, fromBlock, latestBlock);
    const events = [];

    for (const log of logs) {
        const decoded = await decodeSwap(log);

        if (!decoded) {
            continue;
        }

        const meta = JSON.stringify({
            source: 'ritual_v2_backfill',
            pair: log.address,
            log_index: log.index,
            sender: topicAddress(log.topics[1]),
            in_addr: decoded.inAddr,
            out_addr: decoded.outAddr,
        });

        events.push({
            symIn: decoded.inSym,
            amtIn: decoded.inAmount,
            symOut: decoded.outSym,
            amtOut: decoded.outAmount,
            userAddr: topicAddress(log.topics[2]),
            txHash: log.transactionHash.toLowerCase(),
            block: log.blockNumber,
            meta,
            createdAt: formatTimestamp(log.timestamp),
        });
    }

    writeEvents(events);
    console.log(`Wrote ${events.length} Ritual swap events to ${dbPath}`);
};

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
