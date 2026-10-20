<?php

namespace App\Support;

use App\Models\BridgeRequest;

final class BridgeSubmission
{
    public static function sourceHash(string $chain, string $hash): string
    {
        return config("bridge.chains.{$chain}.type") === 'evm' ? strtolower(trim($hash)) : trim($hash);
    }

    public static function fingerprint(
        string $direction,
        string $token,
        string $sourceChain,
        string $sourceHash,
        int $nonce,
        ?string $sender,
        string $recipient,
        string $amount,
        bool $convertToNative,
    ): string {
        $destination = config('bridge.routes', [])[$direction]['destination_chain'] ?? '';

        return hash('sha256', json_encode([
            $direction,
            $token,
            $sourceChain,
            self::sourceHash($sourceChain, $sourceHash),
            $nonce,
            self::address($sourceChain, $sender ?? ''),
            self::address($destination, $recipient),
            TokenAmount::toRaw($amount, 18),
            $convertToNative,
        ], JSON_THROW_ON_ERROR));
    }

    public static function matches(BridgeRequest $request, string $fingerprint): bool
    {
        $original = $request->submitted_intent_hash ?? self::fingerprint(
            $request->direction,
            $request->token,
            $request->source_chain,
            $request->source_tx_hash ?? '',
            $request->source_nonce,
            $request->sender_address,
            $request->recipient_address,
            (string) $request->amount,
            $request->convert_to_native,
        );

        return hash_equals($original, $fingerprint);
    }

    private static function address(string $chain, string $address): string
    {
        return config("bridge.chains.{$chain}.type") === 'evm' ? strtolower(trim($address)) : trim($address);
    }
}
