# Rock–Paper–Scissors contract specification

`contracts/RockPaperScissors.sol` is the first Cyberia Arcade reference game.
It is a two-player native-CYBER escrow with no protocol fee.

## Lifecycle

```text
WaitingForPlayer -> Commit -> Reveal -> Resolved
        |              |          |
        +--------------+----------+-> Cancelled (symmetric timeout)
                       +-----------> Resolved (single-player forfeit)
```

The constructor receives one `phaseDuration` in seconds. Every transition starts
a fresh deadline. A player action is accepted through the deadline; timeout
settlement becomes available in the following second.

## Commitments

Clients must call `hashMove` or reproduce this exact encoding:

```solidity
keccak256(abi.encode(address(contract), chainId, gameId, player, move, secret))
```

The contract address, chain, game and player domains prevent a commitment from
being replayed elsewhere. `Move` values are `1 = Rock`, `2 = Paper`, and
`3 = Scissors`; zero is reserved as the unrevealed value. Secrets should be
random 32-byte values and must remain local until reveal.

## Stakes and settlement

- The creator deposits a non-zero native stake.
- The second player must deposit exactly the same amount.
- A winner receives the full two-stake pot.
- A draw credits one stake back to each player.
- If only one player acts before a commit or reveal timeout, that player wins.
- If neither player acts, both stakes are refunded.
- An unjoined expired game refunds its creator.

Settlement only records `pendingPayout`. Each player withdraws their own credit
with `claimPayout(gameId)`. The credit is cleared before the external call, and
the claim function is protected by `ReentrancyGuard`.

## Public API

```text
createGame() payable -> gameId
joinGame(gameId) payable
commitMove(gameId, commitment)
revealMove(gameId, move, secret)
resolveGame(gameId)
cancelExpiredGame(gameId)
claimPayout(gameId)
getGame(gameId)
hashMove(gameId, player, move, secret)
```

`resolveGame` and `cancelExpiredGame` may be called by anyone because their
outcomes are fully determined by committed contract state.
