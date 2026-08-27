# Как поднять ноду Cyberia

Эта инструкция поднимает **полную follower/RPC-ноду** Cyberia: она скачивает и проверяет всю цепочку, хранит собственную копию состояния и может обслуживать JSON-RPC, но **не производит блоки и не участвует в консенсусе**.

Готовая конфигурация находится в `services/cyberia-node/` и рассчитана на сервер `213.135.146.117`. На другом сервере нужно заменить публичный IP в параметре `--nat` файла `docker-compose.yml`.

::: danger Не превращайте её в валидатор
В production сейчас один IBFT-валидатор. Добавление второго превращает сеть в схему 2-of-2: остановка любого из двух валидаторов остановит выпуск блоков. Для безопасной резервной ноды оставляйте `--seal=false`.
:::

## Что будет запущено

| Параметр | Значение |
| --- | --- |
| Клиент | Polygon Edge |
| Сеть | Cyberia |
| Chain ID | `49406` (`0xC0FE`) |
| Режим | Полная follower-нода, без sealing |
| JSON-RPC | `8545/tcp` |
| P2P | `1337/tcp` |
| WebSocket mapping | `8546/tcp`; используйте только если он действительно включён клиентом |
| Bootnode | `2.26.24.177:1337` |
| Блоки | примерно раз в секунду |

Production использует образ `0xpolygon/polygon-edge:latest`, который сейчас сообщает версию `v1.3.3`. Репозиторий [Polygon Edge](https://github.com/0xPolygon/polygon-edge) архивирован, а опубликованные [release notes](https://github.com/0xPolygon/polygon-edge/releases) предупреждают о несовместимых обновлениях между поколениями клиента. Не обновляйте follower-ноду отдельно от production-валидатора и сохраняйте digest реально запущенного образа.

## Требования к серверу

Практическая стартовая конфигурация для текущей сети:

- Ubuntu 22.04 или 24.04, `amd64`;
- 4 vCPU;
- 8 GB RAM;
- от 100 GB SSD/NVMe с возможностью расширения;
- стабильный публичный IPv4;
- Docker Engine и Docker Compose plugin;
- исходящий TCP к `2.26.24.177:1337`;
- входящий `1337/tcp` для P2P.

Это операционный запас, а не протокольный минимум. На 27 августа 2026 года каталог действующего валидатора занимает около 12 GB, но история и состояние постоянно растут, первичная синхронизация создаёт интенсивную запись, а заполненный диск останавливает ноду. Настройте предупреждение минимум на 80% заполнения.

JSON-RPC не нужно открывать миру напрямую. Безопасный вариант — слушать `8545` только на `127.0.0.1`, а наружу отдавать HTTPS через nginx с rate limit.

## 1. Установите Docker

Установите Docker Engine из [официального репозитория Docker](https://docs.docker.com/engine/install/ubuntu/), затем проверьте:

```bash
docker --version
docker compose version
docker run --rm hello-world
```

Пользователь, запускающий ноду, должен иметь доступ к Docker. Доступ к Docker фактически равен root-доступу к серверу — не добавляйте в группу `docker` случайные учётные записи.

Установите утилиты для проверок:

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl git jq netcat-openbsd
```

## 2. Скопируйте конфигурацию Cyberia

```bash
sudo git clone https://github.com/cyberia-temple/singularity.git /opt/singularity
cd /opt/singularity/services/cyberia-node
```

Если репозиторий уже развёрнут другим способом, нужен только каталог `services/cyberia-node` целиком: `genesis.json`, `docker-compose.yml` и `setup.sh`.

Проверьте chain ID и bootnode в genesis:

```bash
jq '.params.chainID, .bootnodes' genesis.json
```

Ожидается chain ID `49406` и адрес:

```text
/ip4/2.26.24.177/tcp/1337/p2p/16Uiu2HAmGYqgBskF5GLAgMbYaB1rDyYbVnEWN6qapYZGesUTm9go
```

Не берите старый genesis с production-сервера: в нём исторически оставался прежний IP bootnode. Репозиторный `genesis.json` отличается только исправленным адресом подключения и относится к той же цепочке.

## 3. Укажите публичный IP и закройте RPC

В `docker-compose.yml` найдите:

```text
--nat=213.135.146.117
```

Если нода запускается не на этом сервере, замените значение на её реальный публичный IPv4. Не ставьте туда `127.0.0.1`, адрес Docker-сети или внутренний адрес NAT.

По умолчанию репозиторный compose публикует RPC на всех интерфейсах. Если прямой публичный RPC не нужен, до первого запуска измените bindings:

```yaml
ports:
  - "127.0.0.1:8545:8545"
  - "127.0.0.1:8546:8546"
  - "1337:1337"
```

Даже для публичного RPC рекомендуется оставить loopback binding и проксировать его через nginx на `443/tcp`. Так можно ограничить размер запроса, частоту вызовов и разрешённые JSON-RPC методы. Простого UFW недостаточно: опубликованные Docker-порты могут обходить обычные правила UFW.

Минимально необходимые входящие порты:

| Порт | Источник | Назначение |
| --- | --- | --- |
| `22/tcp` | Только административные IP | SSH |
| `1337/tcp` | Интернет или известные peers | Polygon Edge P2P |
| `443/tcp` | Пользователи RPC | Только если публикуется HTTPS RPC |
| `8545/tcp` | Никто извне | Локальный upstream для reverse proxy |

## 4. Проверьте bootnode и образ

```bash
nc -zv -w 5 2.26.24.177 1337
docker compose config --quiet
docker compose pull
docker run --rm 0xpolygon/polygon-edge:latest version
docker image inspect 0xpolygon/polygon-edge:latest \
  --format '{{index .RepoDigests 0}}'
```

Сохраните выведенный digest в журнале развёртывания. Если версия отличается от production, остановитесь и согласуйте обновление клиента до начала синхронизации.

Проверка публичной сети должна вернуть `0xc0fe`:

```bash
curl --fail --silent --show-error https://rpc.cyberia.church \
  -H 'content-type: application/json' \
  --data '{"jsonrpc":"2.0","method":"eth_chainId","params":[],"id":1}' \
  | jq
```

## 5. Создайте уникальные ключи ноды

```bash
chmod +x setup.sh
./setup.sh
```

Скрипт:

1. проверяет bootnode в `genesis.json`;
2. проверяет доступность production P2P;
3. создаёт в `data/` новую libp2p identity и локальные secrets;
4. печатает публичную identity ноды;
5. **не запускает** контейнер.

Повторный запуск сохраняет уже существующие secrets. Никогда не копируйте на follower каталог данных или ключи production-валидатора: одинаковая libp2p identity создаёт конфликт peers, а повторное использование BLS/validator key создаёт риск double-signing.

Ограничьте доступ к каталогу:

```bash
sudo chown -R root:root /opt/singularity/services/cyberia-node
sudo chmod 700 data
```

Для follower потеря ключа не теряет средства и не ломает консенсус, но меняет node ID. Если постоянный node ID важен для allowlist peers, резервируйте secrets отдельно от общей копии диска и никогда не публикуйте их в Git.

## 6. Запустите синхронизацию

```bash
docker compose up -d
docker compose ps
docker compose logs --tail=200 -f cyberia-node
```

Нода начинает с блока 0. Время первичной синхронизации зависит от диска, CPU, сети и текущей высоты цепочки. Не считайте её готовой только потому, что контейнер имеет статус `Up`.

## 7. Проверьте синхронизацию

Проверьте chain ID локальной ноды:

```bash
curl --fail --silent --show-error http://127.0.0.1:8545 \
  -H 'content-type: application/json' \
  --data '{"jsonrpc":"2.0","method":"eth_chainId","params":[],"id":1}' \
  | jq -r '.result'
```

Ожидается `0xc0fe`.

Сравните высоту follower и production:

```bash
rpc_height() {
  rpc_url=$1
  block_hex=$(curl --fail --silent --show-error "$rpc_url" \
    -H 'content-type: application/json' \
    --data '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
    | jq -r '.result')
  printf '%d\n' "$block_hex"
}

rpc_height http://127.0.0.1:8545
rpc_height https://rpc.cyberia.church
```

Во время первичной синхронизации локальная высота должна регулярно расти. Нода готова обслуживать резервный RPC, когда:

- peer count не меньше 1;
- высота догнала production и дальше движется вместе с ней;
- хеш одного и того же свежего блока совпадает на обеих нодах;
- в логах нет повторяющихся ошибок записи, consensus или peer discovery.

Проверьте peers:

```bash
docker exec cyberia-node polygon-edge peers list
```

Проверьте хеш последнего блока, подставив одинаковый номер в оба RPC:

```bash
curl --fail --silent --show-error http://127.0.0.1:8545 \
  -H 'content-type: application/json' \
  --data '{"jsonrpc":"2.0","method":"eth_getBlockByNumber","params":["0xBLOCK","false"],"id":1}' \
  | jq -r '.result.hash'
```

`0xBLOCK` здесь — hex-высота, например значение, которое вернул `eth_blockNumber`.

## 8. Подключайтесь безопасно

Для административной проверки без публичного RPC используйте SSH tunnel:

```bash
ssh -L 8545:127.0.0.1:8545 root@NODE_IP
```

После этого локальные инструменты могут обращаться к `http://127.0.0.1:8545`.

Для публичного backup RPC:

1. оставьте Docker binding на `127.0.0.1:8545`;
2. создайте отдельный DNS hostname;
3. включите TLS;
4. проксируйте только через nginx;
5. установите rate limit и небольшой `client_max_body_size`;
6. ограничьте тяжёлые или административные JSON-RPC методы;
7. проверяйте возраст head, а не только HTTP 200.

Не переключайте `rpc.cyberia.church` на новую ноду до полной синхронизации и отдельной проверки чтения, отправки транзакций, CORS и rate limits.

## Ежедневные проверки

```bash
docker compose ps
docker inspect cyberia-node --format '{{.RestartCount}}'
docker compose logs --since=30m --tail=200 cyberia-node
df -h .
du -sh data
docker exec cyberia-node polygon-edge peers list
```

Мониторинг должен отдельно отслеживать:

- возраст и высоту head;
- отставание от production;
- peer count;
- свободное место и ошибки диска;
- рост restart count;
- время ответа JSON-RPC.

HTTP 200 без свежего блока не означает здоровую ноду.

## Обновление клиента

Не выполняйте без проверки обычное `docker compose pull && docker compose up -d`: тег `latest` изменяемый, а upstream архивирован.

Безопасный порядок:

1. зафиксировать текущую версию и image digest;
2. проверить версию production-валидатора;
3. изучить несовместимости выбранного образа;
4. сначала испытать его на отдельной follower-копии;
5. сохранить возможность вернуться к прежнему digest;
6. обновлять production-валидатор отдельным согласованным окном.

Follower не должна становиться испытательным полигоном, если её данные затем предполагается использовать как production fallback.

## Остановка и восстановление

Остановить ноду без удаления данных:

```bash
docker compose stop cyberia-node
```

Запустить снова:

```bash
docker compose start cyberia-node
```

`docker compose down` удаляет контейнер и сеть Compose, но bind-mounted каталог `data/` остаётся. Не используйте `down -v` и не удаляйте `data/`, пока не установлена причина проблемы.

Если локальная база повреждена, сначала остановите ноду и переместите каталог в отдельную диагностическую копию. Новая пустая `data/` потребует новых secrets и полной синхронизации от блока 0. Production-валидатор для этого останавливать или перезапускать не нужно.

## Частые проблемы

### Peer count равен нулю

- проверьте `nc -zv -w 5 2.26.24.177 1337`;
- убедитесь, что исходящий трафик разрешён;
- проверьте исправленный bootnode в `genesis.json`;
- убедитесь, что `data/` не скопирован с другой ноды;
- проверьте системное время сервера.

### Высота не растёт

- проверьте peers и последние 200 строк логов;
- убедитесь, что диск не заполнен и не перешёл в read-only;
- проверьте, что локальный `eth_chainId` равен `0xc0fe`;
- сравните image version с production.

### RPC отвечает снаружи, хотя должен быть закрыт

Проверьте `docker compose ps` и bindings через `docker port cyberia-node`. Замените публикацию на `127.0.0.1:8545:8545`, пересоздайте контейнер и повторно проверьте порт с другого хоста. Не полагайтесь только на UFW для Docker published ports.

### Контейнер постоянно перезапускается

```bash
docker inspect cyberia-node --format '{{.State.ExitCode}} {{.State.Error}} {{.RestartCount}}'
docker compose logs --tail=300 cyberia-node
```

Не лечите restart loop удалением данных: сначала сохраните логи, версию образа, digest, свободное место и точную ошибку.

## Связанные файлы

- `services/cyberia-node/genesis.json` — genesis Cyberia с актуальным bootnode;
- `services/cyberia-node/docker-compose.yml` — follower с обязательным `--seal=false`;
- `services/cyberia-node/setup.sh` — безопасная первоначальная генерация identity;
- [Network reference](network-reference.md) — публичные параметры Cyberia;
- [Service monitoring](../monitoring.md) — общие правила health checks и инцидентов.
