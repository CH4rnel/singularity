<?php

namespace Tests\Fixtures;

use App\Models\BridgeRequest;

final class BridgeNativeSourceProof
{
    public const BRIDGE = '0xEf2c8E731006EEDD8F44f5Ea03A389635BB28f90';

    public const SENDER = '0x5555555555555555555555555555555555555555';

    public const SOLANA_SENDER = 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG';

    public const HOT_WALLET = 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w';

    public const RECIPIENT_HEX = 'f027f9bde6b2b39d4630f352b6a715cf2b5e53d19f196952dadd3d0c7ca3cbd3';

    public const REDEEM_TOPIC = '0xb0f588ab32e51856c4eb70cdd8ace980ec59d74616c821513d605629ec06b610';

    public const TOKEN_2022 = 'TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb';

    public static function word(string $decimal): string
    {
        $hex = '';
        do {
            $hex = dechex((int) bcmod($decimal, '16')).$hex;
            $decimal = bcdiv($decimal, '16', 0);
        } while (bccomp($decimal, '0', 0) > 0);

        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    public static function evm(BridgeRequest $request, string $gross = '1000000000000000000', ?string $net = null): array
    {
        $net ??= $gross;
        $sender = '0x'.str_pad(substr(strtolower($request->sender_address), 2), 64, '0', STR_PAD_LEFT);

        return [
            'transaction' => [
                'hash' => $request->source_tx_hash, 'from' => $request->sender_address, 'to' => self::BRIDGE,
                'input' => '0x6714c820'.self::word($gross).self::RECIPIENT_HEX,
            ],
            'receipt' => [
                'transactionHash' => $request->source_tx_hash, 'status' => '0x1',
                'logs' => [
                    [
                        'address' => config('bridge.tokens')['CYBER.sol']['chains']['cyberia']['address'],
                        'topics' => ['0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef', $sender, '0x'.str_repeat('0', 64)],
                        'data' => '0x'.self::word($gross),
                    ],
                    [
                        'address' => self::BRIDGE, 'topics' => [self::REDEEM_TOPIC, $sender],
                        'data' => '0x'.self::word($net).self::RECIPIENT_HEX.self::word((string) $request->source_nonce),
                    ],
                ],
            ],
        ];
    }

    public static function balance(int $index, string $owner, string $amount): array
    {
        return [
            'accountIndex' => $index, 'owner' => $owner,
            'mint' => config('bridge.tokens')['CYBER.sol']['chains']['solana']['mint'], 'programId' => self::TOKEN_2022,
            'uiTokenAmount' => ['amount' => $amount, 'decimals' => 6],
        ];
    }

    public static function solana(BridgeRequest $request, string $raw = '1000000'): array
    {
        return [
            'transaction' => [
                'signatures' => [$request->source_tx_hash],
                'message' => [
                    'accountKeys' => [
                        ['pubkey' => $request->sender_address, 'signer' => true, 'writable' => true],
                        ['pubkey' => self::HOT_WALLET, 'signer' => false, 'writable' => false],
                    ],
                    'instructions' => [[
                        'program' => 'spl-token-2022', 'programId' => self::TOKEN_2022,
                        'parsed' => ['type' => 'transfer', 'info' => [
                            'authority' => $request->sender_address, 'amount' => $raw,
                            'source' => 'sender-token-account', 'destination' => 'hot-wallet-token-account',
                        ]],
                    ]],
                ],
            ],
            'meta' => [
                'err' => null,
                'preTokenBalances' => [self::balance(2, $request->sender_address, $raw), self::balance(3, self::HOT_WALLET, '2000000')],
                'postTokenBalances' => [self::balance(2, $request->sender_address, '0'), self::balance(3, self::HOT_WALLET, bcadd('2000000', $raw, 0))],
            ],
        ];
    }
}
