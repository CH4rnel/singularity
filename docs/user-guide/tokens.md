# Tokens on Cyberia

## CYBER vs CYBER.sol vs wrapped CYBER.sol

Three distinct assets share the CYBER name. Don't mix them up:

| Asset | Where it lives | What it is |
| --- | --- | --- |
| **CYBER** | Cyberia (native) | The gas token of the Cyberia network. Not an ERC-20; it's the chain's native coin, like ETH on Ethereum. |
| **CYBER.sol** | Solana | SPL (Token-2022) market token, mint `E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump` (the pump.fun asset). |
| **Wrapped CYBER.sol** | Cyberia (ERC-20) | The bridge's representation of CYBER.sol on Cyberia, minted/burned by the bridge contract. |

CYBER.sol is **not** a 1:1 wrapped version of native CYBER. The only fixed link is the one-way [converter](bridge.md#converting-cybersol-to-native-cyber): 1000 CYBER.sol → 1 native CYBER, with the input burned.

## Bridged assets (ERC-20 contracts on Cyberia)

Import these addresses into MetaMask to see your balances. Verify any address on the [explorer](https://explorer.cyberia.church) before trusting it.

| Symbol | Cyberia contract | Decimals | Origin |
| --- | --- | --- | --- |
| CYBER.sol | `0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA` | 18 | Solana (Token-2022, 6 dec) |
| USDC | `0xdc25597B19799010047F17e9591EFE08EFd40077` | 6 | Solana / Base (Circle USDC) |
| USDT | `0x94845aF24a3E431593A2b941b2b31836dE45185D` | 6 | Solana / BNB Chain |
| SOL | `0x53450B1d205f1e41d10B653FBBDEa74160dafFf4` | 9 | Solana (native SOL) |
| ETH | `0xFDa2F6EEB11f1aCc7ccAb559133E8F07d9F81986` | 18 | Base / Robinhood Chain (native ETH) |
| TON | `0x92aBF73698383176Aa2894F1f7263807C3a4e6e6` | 18 | TON (native Toncoin) |
| SPY | `0x1241FC4F06DB7268243D9439ef56B7a2708DC096` | 18 | Robinhood Chain (tokenized SPDR S&P 500 ETF) |
| HATCHER | `0x621021F18b6404123f98b1395c418868418ACF36` | 9 | Solana (Token-2022) |
| ORBV | `0x19E92D8475522FF6c8f3660372B9dc6674d85cC8` | 6 | Solana (Token-2022, Orbserv) |
| KRSQ | `0x4945419ccEEF0Dc70B054700DE2750A056B03eE3` | 18 | TON jetton (KARASIQUE) |
| GOAL | `0xEb91EC10462a249b9922D6D62FB2BE73Bd084ADe` | 18 | TON jetton (Goal Bear Coin) |
| YTN | `0x3a5820Be90c3fB9c5F3Fb47a4859544193B0f8C6` | 18 | Yenten (corridor coming soon) |
| BTC | `0x9332081f308BC978fe259237850fA253131b46Fa` | 8 | Bitcoin (corridor coming soon) |
| LTC | `0x001AFD19C9d890b0cf0fcd6D654f9BFe4f264F14` | 8 | Litecoin (corridor coming soon) |
| XMR | `0xe2E8D51C18d6e0FDDbb9Ff4BF63235D688dd00Ae` | 12 | Monero (corridor coming soon) |

Stablecoins and shared assets (USDC, USDT, ETH) use **one unified wrapper** regardless of which source chain they were bridged from; the bridge pools its reserves across chains and caps withdrawals per destination by its live inventory.

Some wrappers use different decimals than their origin asset (e.g. CYBER.sol is 6-dec on Solana, 18-dec on Cyberia) — the bridge scales amounts automatically; the value is unaffected.

## Infrastructure contracts

| Contract | Address |
| --- | --- |
| WCYBER (wrapped native CYBER, for DEX pools) | `0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9` |
| CyberBridge (CYBER.sol release/redeem) | `0xEf2c8E731006EEDD8F44f5Ea03A389635BB28f90` |
| CyberSolBurnSwap (CYBER.sol → CYBER converter) | `0xa5Ae36E5b1eDb24BCa2F96783d079B28e0BCfd71` |
| CyberiaProfile (nicknames, achievements) | `0xa9101ee859850c037b0867156b3535F78A387C0d` |

## Community and launchpad tokens

Beyond bridged assets, Cyberia hosts community tokens: tokens launched through the [launchpad](https://cyberia.church/launchpad) (each launch burns CYBER into locked liquidity), Telegram chat tokens minted by the community bot, and other experiments. Browse them all with prices and pools at <https://cyberia.church/tokens>.
