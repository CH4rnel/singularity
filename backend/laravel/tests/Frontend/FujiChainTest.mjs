import assert from 'node:assert/strict';
import test from 'node:test';
import { reactive } from 'vue';
import { fujiChain } from '@/lib/fujiChain';

const network = () => ({
    chainId: 49407,
    name: 'Cyberia Fuji',
    rpcUrl: 'https://cyberia.church/api/fuji/rpc',
    currency: { name: 'Cyber', symbol: 'CYBER', decimals: 18 },
    explorerTx: null,
});

/**
 * MetaMask carries every request to its extension through window.postMessage.
 * Props arrive from Inertia as Vue proxies, which postMessage cannot clone,
 * and the add-network request died there without the wallet ever showing it.
 */
test('the chain handed to the wallet survives structured cloning, even built from reactive props', () => {
    const props = reactive({ network: network() });

    assert.throws(() => structuredClone(props.network.currency));

    assert.deepEqual(structuredClone(fujiChain(props.network)), {
        chainId: 49407,
        name: 'Cyberia Fuji',
        nativeCurrency: { name: 'Cyber', symbol: 'CYBER', decimals: 18 },
        rpcUrls: ['https://cyberia.church/api/fuji/rpc'],
        status: 'live',
    });
});
