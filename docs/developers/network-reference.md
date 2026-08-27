# Network Reference

## Cyberia mainnet

| Field | Value |
| --- | --- |
| Network name | Cyberia |
| Chain ID | `49406` |
| Chain ID (hex) | `0xC0FE` |
| RPC URL | `https://rpc.cyberia.church` |
| Native currency | CYBER |
| Native decimals | 18 |
| Explorer | `https://explorer.cyberia.church` |
| Main site | `https://cyberia.church` |
| Bridge | `https://bridge.cyberia.church` |
| DEX | `https://swap.cyberia.church` |

## Wallet configuration object

```json
{
  "chainId": "0xC0FE",
  "chainName": "Cyberia",
  "nativeCurrency": {
    "name": "CYBER",
    "symbol": "CYBER",
    "decimals": 18
  },
  "rpcUrls": ["https://rpc.cyberia.church"],
  "blockExplorerUrls": ["https://explorer.cyberia.church"]
}
```

Pass this object to the EIP-1193 `wallet_addEthereumChain` method when a wallet does not yet know Cyberia. Treat an error from the wallet as a user-visible action failure; do not silently fall back to signing on a different network.

## Add or switch the network in a browser

```ts
const CYBERIA_CHAIN_ID = '0xC0FE';

export async function connectCyberia(provider: {
  request(args: { method: string; params?: unknown[] }): Promise<unknown>;
}) {
  const accounts = await provider.request({
    method: 'eth_requestAccounts',
  });

  try {
    await provider.request({
      method: 'wallet_switchEthereumChain',
      params: [{ chainId: CYBERIA_CHAIN_ID }],
    });
  } catch (error: any) {
    if (error?.code !== 4902) throw error;

    await provider.request({
      method: 'wallet_addEthereumChain',
      params: [{
        chainId: CYBERIA_CHAIN_ID,
        chainName: 'Cyberia',
        nativeCurrency: {
          name: 'CYBER',
          symbol: 'CYBER',
          decimals: 18,
        },
        rpcUrls: ['https://rpc.cyberia.church'],
        blockExplorerUrls: ['https://explorer.cyberia.church'],
      }],
    });
  }

  const chainId = await provider.request({ method: 'eth_chainId' });
  if (chainId !== CYBERIA_CHAIN_ID) {
    throw new Error(`Expected ${CYBERIA_CHAIN_ID}, received ${chainId}`);
  }

  return accounts;
}
```

Integration flow:

1. Request accounts from the injected wallet provider.
2. Ask the wallet to switch to `0xC0FE`.
3. If the wallet reports error `4902`, add Cyberia using the canonical object.
4. Read `eth_chainId` again before enabling a signing action.
5. After broadcasting a transaction, wait for its receipt and link the hash to the explorer.

## Public asset identifiers

| Asset | Identifier |
| --- | --- |
| CYBER.sol on Solana | `E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump` |
| Wrapped CYBER | `0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9` |
| Wrapped CYBER.sol on Cyberia | `0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA` |
| CyberBridge | `0xEf2c8E731006EEDD8F44f5Ea03A389635BB28f90` |

The complete asset and infrastructure contract list is in [Tokens and contracts](../user-guide/tokens.md). Verify deployed addresses on the explorer before hardcoding or signing against them.

## Integration rules

- Compare chain IDs as integers or canonical hex values, not display strings.
- Never assume an ERC-20 uses 18 decimals; read `decimals()` or the chain's token index.
- Keep the native CYBER coin distinct from WCYBER, Solana CYBER.sol, and wrapped CYBER.sol.
- Use integer smallest-unit arithmetic for amounts and capacity calculations.
- A successful JSON-RPC HTTP response does not imply transaction settlement; wait for the required receipt or confirmation state.
- Browser-side Solana calls in the Laravel app use its `/api/solana/rpc` relay rather than a hardcoded upstream.
