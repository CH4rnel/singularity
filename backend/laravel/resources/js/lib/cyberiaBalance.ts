// Same-origin proxy: the public node may reject browser CORS requests.
const CYBERIA_RPC = '/api/rpc/cyberia';

type RpcBalanceResponse = {
    result?: unknown;
    error?: { message?: string };
};

export async function fetchCyberBalance(
    address: string,
    request: typeof fetch = fetch,
): Promise<bigint> {
    const response = await request(CYBERIA_RPC, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            jsonrpc: '2.0',
            id: 1,
            method: 'eth_getBalance',
            params: [address, 'latest'],
        }),
    });

    if (!response.ok) {
        throw new Error(`Cyberia RPC request failed: ${response.status}`);
    }

    const payload = (await response.json()) as RpcBalanceResponse;

    if (
        typeof payload.result !== 'string' ||
        !/^0x[0-9a-fA-F]+$/.test(payload.result)
    ) {
        throw new Error(payload.error?.message || 'Invalid Cyberia balance');
    }

    return BigInt(payload.result);
}

export function formatCyberBalance(balanceWei: bigint, precision = 4): string {
    if (balanceWei === 0n) {
        return '0';
    }

    const scale = 10n ** 18n;
    const whole = balanceWei / scale;
    const fraction = (balanceWei % scale).toString().padStart(18, '0');
    const visibleFraction = fraction.slice(0, precision).replace(/0+$/, '');

    if (whole === 0n && visibleFraction === '') {
        return `<${`0.${'0'.repeat(Math.max(precision - 1, 0))}1`}`;
    }

    const formattedWhole = whole.toLocaleString('en-US');

    return visibleFraction
        ? `${formattedWhole}.${visibleFraction}`
        : formattedWhole;
}
