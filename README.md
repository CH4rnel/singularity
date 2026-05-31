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

## License

GPL-3.0

---

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidelines.
