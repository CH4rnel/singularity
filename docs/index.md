---
layout: home
titleTemplate: false

hero:
  name: Cyberia Docs
  text: Use, build, and operate the network
  tagline: One manual for the Cyberia wallet, bridge, DEX, contracts, services, and the Singularity monorepo.
  actions:
    - theme: brand
      text: Start using Cyberia
      link: /user-guide/getting-started
    - theme: alt
      text: Developer guide
      link: /developers/
    - theme: alt
      text: Open the wallet
      link: https://cyberia.church/wallet

features:
  - icon: ◈
    title: Use Cyberia
    details: Set up the network, secure the multichain wallet, bridge assets, trade, and troubleshoot transactions.
    link: /user-guide/
    linkText: Read the user guide
  - icon: ⌘
    title: Build on Cyberia
    details: Understand the monorepo, run each component locally, integrate the EVM network, and use the inference API.
    link: /developers/
    linkText: Open developer docs
  - icon: ◉
    title: Operate the stack
    details: Run the console, monitoring, analytics, releases, and the services that keep the ecosystem observable.
    link: /operations/
    linkText: Open operations docs
---

## Network at a glance

| | |
| --- | --- |
| Network | Cyberia |
| Chain ID | `49406` (`0xC0FE`) |
| RPC | `https://rpc.cyberia.church` |
| Native token | CYBER |
| Explorer | <https://explorer.cyberia.church> |

Cyberia is an experimental EVM Layer 1 and application stack. Production-facing applications and prototypes live in the same repository, so each guide states which surface it covers and where the source of truth lives.

::: warning Verify before you transact
Cyberia's bridge is relayer-operated, and some products are experimental. Verify token and contract addresses on the explorer and read the relevant trust model before moving significant value.
:::
