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

## Public Key

## CoinGecko API

This project uses the CoinGecko API for cryptocurrency market data.

Documentation:
https://www.coingecko.com/en/api

Example request:

```bash
curl -X GET "https://api.coingecko.com/api/v3/ping"
```

Example response:

```json
{
  "gecko_says": "(V3) To the Moon!"
}
```

## PumpFunData API

Documentation:
https://pumpfundata.com/docs

Example request:

```bash
curl -X GET "https://pumpfundata.com/api/endpoint" \
  -H "Authorization: Bearer YOUR_API_KEY"
```


## CoinAPI Integration

This project uses the CoinAPI Market Data API.

Documentation:
https://www.coinapi.io/products/market-data-api/docs

### Example Request

```bash
curl -H "X-CoinAPI-Key: YOUR_API_KEY" \
https://rest.coinapi.io/v1/exchangerate/BTC/USD
```

### Example Response

```json
{
  "time": "2026-05-18T12:00:00.0000000Z",
  "asset_id_base": "BTC",
  "asset_id_quote": "USD",
  "rate": 103421.12
}
```
## 1inch API

Website: https://1inch.com/

Example request:

```bash
curl -X GET "https://api.1inch.dev/swap/v6.0/1/quote?src=ETH&dst=USDC&amount=1000000000000000000" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

Documentation:
https://portal.1inch.dev/documentation/apis/swap/introduction

## CoinAPI Setup

1. Create `.env`

```env
COINAPI_KEY=your_api_key_here
```

2. Get API key from:
https://www.coinapi.io/

3. Example request:

```js
fetch("https://rest.coinapi.io/v1/exchangerate/BTC/USD", {
  headers: {
    "X-CoinAPI-Key": process.env.COINAPI_KEY
  }
});
```

---

## Daemon Service

The Lisp daemon runs via systemd. See service configuration in README or `cli.sh`.

---

## License

GPL-3.0

---

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidelines.
