# Cyberia
- Website/blog: https://cyberia-temple.github.io
- X/Twitter: https://x.com/cyberia_temple
- Telegram channel: https://t.me/cyberia_network
- Telegram chat: https://t.me/cyberia_network_chat
- CYBER.sol CA: E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump

# Network config
- network name: cyberia
- URL RPC: https://rpc.cyberia.church
- Chain ID: 49406
- Token: CYBER
- Explorer: https://explorer.cyberia.church/

# Singularity

Unified backend for integrating and interacting with multiple public APIs.

---

## Components

```
singularity/
├── backend/laravel/   # Laravel 13 + Vue 3 + Inertia API
├── frontend/jekyll/   # Jekyll static site (Cyberia Blog)
├── hardhat/           # Solidity contracts
├── linux/             # Cyberia OS build config
├── services/          # Lisp daemon services
└── scripts/           # Deployment & maintenance scripts
```

---

## Quick Start (Laravel Backend)

```bash
cd backend/laravel
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
npm install && npm run build

composer run dev
```
---

## Daemon Service

The Lisp daemon runs via systemd. See service configuration in README or `cli.sh`.

---

## Public API

PublicDrop Crypto API:

```
curl https://publicdrop.in/APIv3/coins?page=1
curl https://publicdrop.in/APIv3/details?sym=BTC
curl https://publicdrop.in/APIv3/search?q=ethereum
curl https://publicdrop.in/APIv3/status
```

CoinLore API:

```
curl https://api.coinlore.net/api/tickers/
curl https://api.coinlore.net/api/ticker/?id=90
curl https://api.coinlore.net/api/global/
```

CoinGecko Public API:

```
curl https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,ethereum&vs_currencies=usd
curl https://api.coingecko.com/api/v3/coins/markets?vs_currency=usd&order=market_cap_desc
curl https://api.coingecko.com/api/v3/global
```

Binance Public API:

```
curl https://api.binance.com/api/v3/ticker/price
curl https://api.binance.com/api/v3/ticker/price?symbol=BTCUSDT
curl https://api.binance.com/api/v3/depth?symbol=BTCUSDT
```

Kraken Public API: 

```
curl https://api.kraken.com/0/public/Ticker?pair=XBTUSD
curl https://api.kraken.com/0/public/OHLC?pair=XBTUSD
curl https://api.kraken.com/0/public/Assets
```
---

## License

GPL-3.0

---

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidelines.
