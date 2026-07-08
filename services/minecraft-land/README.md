# Cyberia Minecraft Land

Paper-плагин, который связывает Minecraft-чанки с NFT из контракта
`MinecraftLand` в сети Cyberia. Передача ERC-721 новому адресу автоматически
передаёт право строить на соответствующем участке.

## Что защищено

- установка и разрушение блоков;
- использование блоков, вёдер и механизмов через `PlayerInteractEvent`;
- изменение блоков мобами;
- разрушение заявленных участков взрывами.

Незаявленные чанки остаются общими. Операторы с правом
`cyberialand.bypass` обходят защиту. При недоступном RPC защита по умолчанию
работает в fail-closed режиме.

## Контракт

Контракт находится в `crypto/hardhat/contracts/MinecraftLand.sol`. Один токен
соответствует `(worldId, chunkX, chunkZ)`, где `worldId` —
`keccak256(UTF-8(world-key))`.

Развёртывание:

```bash
cd crypto/hardhat
DEPLOYER_PK=0x... npx hardhat ignition deploy \
  ignition/modules/MinecraftLand.ts --network cyberia
```

Цена модуля по умолчанию — `10 CYBER`, base URI пустой. Для production задайте
параметры Ignition `mintPrice` (wei) и `baseTokenURI` явно.

Минт использует commit/reveal против кражи координат из мемпула:

1. Получить `worldId`, координаты чанка и адрес контракта через `/land info`.
2. Создать случайный `bytes32 salt` и вызвать
   `commitmentFor(wallet, worldId, x, z, salt)`.
3. Отправить результат в `commit(bytes32)`.
4. Не раньше следующего блока вызвать
   `claim(worldId, x, z, salt)` с точным `mintPrice`.

Reveal должен попасть в один из следующих 256 блоков. Соль нельзя терять между
транзакциями.

## Сборка и установка

Требуется JDK 21. Gradle поставляется через wrapper:

```bash
cd services/minecraft-land
./gradlew test build
cp build/libs/cyberia-minecraft-land-0.1.0.jar /path/to/paper/plugins/
```

Плагин целится в Paper API 1.21.4 и использует стандартный `plugin.yml`.
Web3j загружается Paper через секцию `libraries`.

После первого запуска заполните
`plugins/CyberiaLand/config.yml`:

```yaml
rpc-url: "https://rpc.cyberia.church"
contract-address: "0x..."
world-key: "cyberia-survival-v1"
protected-worlds: [world]
```

`world-key` нельзя менять после начала продаж: другое значение создаёт другое
пространство участков.

## Привязка кошелька

```text
/land link 0xАдрес
/land verify 0xПодпись
/land info
/land unlink
```

После `/land link` игрок получает одноразовое сообщение. Его нужно подписать
как EIP-191 `personal_sign` и передать подпись в `/land verify`. Плагин
восстанавливает адрес из подписи; простое указание чужого адреса прав не даёт.
Привязки сохраняются в `plugins/CyberiaLand/wallets.yml`.

RPC-ответы кэшируются на 15 секунд, поэтому после передачи NFT права в игре
обновятся с задержкой не более TTL кэша.
