# Account and Profile

An account on [cyberia.church](https://cyberia.church) ties together your wallets, bridge history, on-chain nickname, and achievements. The profile page is at <https://cyberia.church/profile>.

## Signing in

Three ways to get an account — they all lead to the same profile:

- **Wallet sign-in** (<https://cyberia.church/wallet-login>): connect an **EVM wallet** (MetaMask etc.) or a **Solana wallet** (Phantom etc.) and sign a one-time nonce message. No password, no email. The signature only proves address ownership — it costs nothing and grants no token approvals.
- **X (Twitter)**: sign in with your X account via OAuth.
- **Email**: classic registration with email and password.

After signing in you can **attach** additional wallets (EVM and Solana) to the same account from the profile page, again by signing a nonce message. Attached wallets are how the bridge and achievements recognize you.

## On-chain nickname

You can claim a nickname that is stored **on-chain** in the CyberiaProfile contract (`0xa9101ee859850c037b0867156b3535F78A387C0d`), bound to your wallet address — publicly readable by any app on Cyberia, not just the website. Claiming a nickname earns the **Netrunner** achievement.

## Achievements

Achievements are permanent on-chain badges recorded in the CyberiaProfile contract. They are awarded automatically based on what your attached wallets actually did on-chain — use the "check achievements" action on your profile to claim anything newly earned.

| Badge | How to earn it |
| --- | --- |
| **First Exchange** | Swap tokens on the DEX. |
| **Bridge Walker** | Complete a cross-chain bridge transfer. |
| **Liquidity Farmer** | Provide liquidity to a DEX pool. |
| **Converter** | Convert CYBER.sol into native CYBER. |
| **Lender** | Use the lending market. |
| **Netrunner** | Claim an on-chain nickname. |

Because the badges live on-chain, anyone can verify them — they are not just website flair.

## Personal deposit addresses

For bridge corridors on chains without wallet-connect support (Bitcoin, Litecoin, Monero, Yenten), the profile page shows **your own** deposit address per chain, derived uniquely for your account. Anything sent there is credited to you automatically once the corridor is live — these corridors are currently marked "coming soon", and the addresses appear on the profile only when a chain is enabled.

Public profiles are visible at `cyberia.church/u/<user>`.
