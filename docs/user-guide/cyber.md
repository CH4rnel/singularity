# Why CYBER Exists

CYBER is the native coin of the Cyberia network. It gives every on-chain action one common fee currency and connects the network's applications through a shared base asset.

## What CYBER does

### Pays for transactions

Every Cyberia transaction uses CYBER for gas. This includes sending tokens, approving a swap, adding liquidity, interacting with lending markets, voting through a contract, and playing an on-chain game.

The fee is calculated by the network and shown in the wallet before you confirm. Applications can help a new address receive a small starting amount, while the final transaction is still signed by the user and paid in CYBER.

### Connects DEX markets

Ritual pools use WCYBER, the ERC-20 wrapper of native CYBER, as a common quote and routing asset. This makes it possible for the DEX to find routes between many tokens, including pairs that do not have a direct pool.

The swap interface handles wrapping and unwrapping when native CYBER is used. One CYBER wraps into one WCYBER and can be unwrapped back into one CYBER.

### Seeds launchpad liquidity

Cyberia's launchpad pairs a new token with CYBER. The resulting pool gives the token an on-chain market from its launch and makes CYBER the reserve asset for trades.

### Participates across Cyberia apps

CYBER is used throughout the ecosystem wherever an application needs the network's native coin. Examples include lending through the WCYBER market, staking flows, prediction-market positions, and transaction fees for DAO and game actions.

## Three related assets

| Asset | Network | Role |
| --- | --- | --- |
| **CYBER** | Cyberia | Native coin and gas currency |
| **WCYBER** | Cyberia | 1:1 ERC-20 wrapper used by contracts and liquidity pools |
| **CYBER.sol** | Solana | Solana community token with its own mint and supply |

CYBER has no contract address because it is built into the network. WCYBER and the bridged form of CYBER.sol are contracts, so their addresses can be checked in [Tokens and contracts](tokens.md).

CYBER.sol can be bridged to Cyberia and converted at the documented fixed rate of 1000 CYBER.sol to 1 native CYBER. The bridge and converter show the output before signing.

## Get CYBER step by step

### From another supported asset

1. [Bridge](bridge.md) a supported asset into Cyberia.
2. Open <https://cyberia.church/swap> or <https://swap.cyberia.church>.
3. Select the asset you received as the input and CYBER as the output.
4. Review the amount, route, price impact, and network fee.
5. Approve the input token if the wallet asks for an ERC-20 approval.
6. Confirm the swap and keep its transaction hash.

### From CYBER.sol

1. Open the [bridge](https://cyberia.church/bridge).
2. Choose Solana as the source, Cyberia as the destination, and CYBER.sol as the asset.
3. Enable conversion to native CYBER.
4. Review the receiving address and expected CYBER amount.
5. Sign the Solana deposit and follow the request until completion.

You can also convert wrapped CYBER.sol already held on Cyberia at <https://cyberia.church/convert>.

## Use CYBER step by step

1. Select Cyberia, chain ID `49406`, in your wallet.
2. Open the Cyberia application you want to use.
3. Connect the same address that holds CYBER.
4. Review the application action and the gas estimate separately.
5. Confirm the transaction in the wallet.
6. Open the transaction hash in the [explorer](https://explorer.cyberia.church) to verify completion.

CYBER's purpose is functional: it pays for shared network resources and acts as a common asset across Cyberia applications. Live market information is available on <https://cyberia.church/cyber> and the DEX.
