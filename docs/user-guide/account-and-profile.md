# Account and Profile

An account on [cyberia.church](https://cyberia.church) ties together your wallets, bridge history, on-chain nickname, and achievements. The profile page is at <https://cyberia.church/profile>.

## Signing in

Three ways to get an account — they all lead to the same profile:

- **Wallet sign-in** (<https://cyberia.church/wallet-login>): connect an **EVM wallet** (MetaMask etc.) or a **Solana wallet** (Phantom etc.) and sign a one-time nonce message. No password, no email. The signature only proves address ownership — it costs nothing and grants no token approvals.
- **X (Twitter)**: sign in with your X account via OAuth.
- **Email**: classic registration with email and password.

### Sign in with a wallet

1. Open <https://cyberia.church/wallet-login>.
2. Choose an EVM or Solana wallet.
3. Connect the address you want to associate with the profile.
4. Read and sign the one-time login message in the wallet.
5. Wait for the site to open your account or profile.

This is a message signature, so it does not send a transaction or spend gas.

### Attach another wallet

1. Sign in and open <https://cyberia.church/profile>.
2. Choose the option to attach an EVM or Solana wallet.
3. Select the address in that wallet.
4. Sign the one-time ownership message.
5. Confirm that the new address appears in the profile's wallet list.

After signing in you can **attach** additional wallets (EVM and Solana) to the same account from the profile page, again by signing a nonce message. Attached wallets are how the bridge and achievements recognize you.

## On-chain nickname

You can claim a nickname that is stored **on-chain** in the CyberiaProfile contract (`0xa9101ee859850c037b0867156b3535F78A387C0d`), bound to your wallet address — publicly readable by any app on Cyberia, not just the website. Claiming a nickname earns the **Netrunner** achievement.

1. Open your profile and connect the Cyberia wallet you want to name.
2. Enter an available nickname.
3. Choose **Save**.
4. The Cyberia relayer records the nickname on-chain and pays the network fee.
5. Confirm that the profile displays the nickname and check it in the explorer if desired.

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

To check achievements:

1. Attach the wallets that contain your Cyberia and supported cross-chain activity.
2. Open the achievements section on the profile.
3. Choose **Check for new**.
4. Review the badges found for those addresses.
5. Newly earned badges are recorded on-chain by the Cyberia service and then appear in the profile.

## Personal deposit addresses

For bridge corridors on chains without wallet-connect support (Bitcoin, Litecoin, Monero, Yenten), the profile page shows **your own** deposit address per chain, derived uniquely for your account. Anything sent there is credited to you automatically once the corridor is live — these corridors are currently marked "coming soon", and the addresses appear on the profile only when a chain is enabled.

Public profiles are visible at `cyberia.church/u/<user>`.
