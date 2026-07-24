<?php

namespace App\Services;

use App\Models\BridgeRequest;
use App\Support\Environment;
use App\Support\TokenAmount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class BridgeService
{
    /**
     * Bridge fee percentage (1% = 0.01) — legacy fallback for old rows only.
     */
    private const FEE_RATE = '0.01';

    /**
     * Calculate the amount after deducting the bridge fee.
     */
    public static function deductFee(string $amount): string
    {
        $fee = bcmul($amount, self::FEE_RATE, 18);
        $afterFee = bcsub($amount, $fee, 18);

        return $afterFee;
    }

    /**
     * Calculate the fee for a given amount.
     */
    public static function calculateFee(string $amount): string
    {
        return bcmul($amount, self::FEE_RATE, 18);
    }

    private function solanaRpc(): string
    {
        return (string) config('bridge.chains.solana.rpc_url');
    }

    private function solanaHotWallet(): string
    {
        return (string) config('bridge.chains.solana.deposit_address');
    }

    public function createRequest(
        ?int $userId,
        string $direction,
        string $sourceChain,
        ?string $sourceTxHash,
        int $sourceNonce,
        ?string $senderAddress,
        string $recipientAddress,
        string $amount,
        string $token = 'CYBER.sol',
        ?string $feeAmount = null,
        ?string $feeUsd = null,
        bool $gasDropPlanned = false,
        ?string $gasDropAmount = null,
        bool $convertToNative = false,
        ?string $depositAddress = null,
        ?string $depositWif = null,
        string $status = 'pending',
    ): BridgeRequest {
        return BridgeRequest::create([
            'user_id' => $userId,
            'direction' => $direction,
            'token' => $token,
            'source_chain' => $sourceChain,
            'source_tx_hash' => $sourceTxHash,
            'source_nonce' => $sourceNonce,
            'sender_address' => $senderAddress,
            'recipient_address' => $recipientAddress,
            'deposit_address' => $depositAddress,
            'deposit_wif' => $depositWif,
            'amount' => $amount,
            'fee_amount' => $feeAmount,
            'fee_usd' => $feeUsd,
            'gas_drop_planned' => $gasDropPlanned,
            'gas_drop_amount' => $gasDropAmount,
            'convert_to_native' => $convertToNative,
            'status' => $status,
        ]);
    }

    /**
     * Verify that a Solana transaction is a real SPL transfer to our hot wallet.
     * Returns the transfer amount in raw units, or null if invalid.
     */
    public function verifySolanaDeposit(string $txHash, string $expectedSender): ?string
    {
        try {
            $response = Http::timeout(30)->post($this->solanaRpc(), [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getTransaction',
                'params' => [
                    $txHash,
                    ['encoding' => 'jsonParsed', 'commitment' => 'confirmed', 'maxSupportedTransactionVersion' => 0],
                ],
            ]);

            if (! $response->successful()) {
                Log::error('Bridge: Solana RPC HTTP error', ['tx' => $txHash, 'status' => $response->status(), 'body' => $response->body()]);

                return null;
            }

            $result = $response->json('result');

            if (! $result || ($result['meta']['err'] ?? null) !== null) {
                Log::warning('Bridge: Solana tx not found or failed', [
                    'tx' => $txHash,
                    'result_null' => $result === null,
                    'rpc_error' => $response->json('error'),
                    'meta_err' => $result['meta']['err'] ?? null,
                ]);

                return null;
            }

            // Look through inner instructions and top-level instructions for SPL transfers
            $instructions = $result['transaction']['message']['instructions'] ?? [];
            $innerInstructions = $result['meta']['innerInstructions'] ?? [];

            foreach ($innerInstructions as $inner) {
                foreach ($inner['instructions'] ?? [] as $ix) {
                    $instructions[] = $ix;
                }
            }

            Log::info('Bridge: verifySolanaDeposit scanning instructions', [
                'tx' => $txHash,
                'expectedSender' => $expectedSender,
                'instruction_count' => count($instructions),
            ]);

            foreach ($instructions as $ix) {
                $parsed = $ix['parsed'] ?? null;
                $program = $ix['program'] ?? '';
                $type = $parsed['type'] ?? '';

                if ($program !== 'spl-token') {
                    continue;
                }

                $info = $parsed['info'] ?? [];

                Log::info('Bridge: verifySolanaDeposit found spl-token ix', [
                    'type' => $type,
                    'authority' => $info['authority'] ?? 'n/a',
                    'amount' => $info['amount'] ?? ($info['tokenAmount']['amount'] ?? 'n/a'),
                ]);

                // Support both 'transfer' and 'transferChecked'
                if ($type !== 'transfer' && $type !== 'transferChecked') {
                    continue;
                }

                if (($info['authority'] ?? '') !== $expectedSender) {
                    continue;
                }

                // 'transfer' has 'amount', 'transferChecked' has 'tokenAmount.amount'
                $amount = $info['amount'] ?? ($info['tokenAmount']['amount'] ?? null);

                return $amount;
            }

            Log::warning('Bridge: verifySolanaDeposit no matching transfer found', ['tx' => $txHash]);

            return null;
        } catch (\Exception $e) {
            Log::error('Bridge: verifySolanaDeposit failed', ['tx' => $txHash, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Verify an SPL deposit by computing the balance delta on the hot wallet
     * for a specific mint. More robust than instruction scanning because it
     * also confirms the mint matches what we expected (prevents replay of a
     * transfer of a different token claiming to be USDC etc.).
     *
     * @return string|null raw amount delta on the hot wallet, or null if not a valid deposit
     */
    public function verifySolanaTokenDeposit(
        string $txHash,
        string $expectedSender,
        string $expectedMint,
        ?string $expectedRecipient = null,
    ): ?string {
        $expectedRecipient ??= $this->solanaHotWallet();

        try {
            $response = Http::timeout(30)->post($this->solanaRpc(), [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getTransaction',
                'params' => [
                    $txHash,
                    ['encoding' => 'jsonParsed', 'commitment' => 'confirmed', 'maxSupportedTransactionVersion' => 0],
                ],
            ]);

            if (! $response->successful()) {
                Log::error('Bridge: Solana RPC HTTP error', ['tx' => $txHash, 'status' => $response->status()]);

                return null;
            }

            $result = $response->json('result');

            if (! $result || ($result['meta']['err'] ?? null) !== null) {
                Log::warning('Bridge: Solana tx not found or failed', ['tx' => $txHash]);

                return null;
            }

            $pre = $this->indexTokenBalances($result['meta']['preTokenBalances'] ?? []);
            $post = $this->indexTokenBalances($result['meta']['postTokenBalances'] ?? []);

            $key = $expectedRecipient.':'.$expectedMint;
            $preRaw = $pre[$key] ?? '0';
            $postRaw = $post[$key] ?? '0';

            if (bccomp($postRaw, $preRaw, 0) <= 0) {
                Log::warning('Bridge: hot wallet balance did not increase', [
                    'tx' => $txHash,
                    'mint' => $expectedMint,
                    'pre' => $preRaw,
                    'post' => $postRaw,
                ]);

                return null;
            }

            // Verify the sender actually lost the same amount (sanity check).
            $senderKey = $expectedSender.':'.$expectedMint;
            $senderPre = $pre[$senderKey] ?? null;
            $senderPost = $post[$senderKey] ?? '0';

            if ($senderPre !== null && bccomp($senderPre, $senderPost, 0) <= 0) {
                Log::warning('Bridge: sender balance did not decrease', [
                    'tx' => $txHash,
                    'sender' => $expectedSender,
                    'pre' => $senderPre,
                    'post' => $senderPost,
                ]);

                return null;
            }

            return bcsub($postRaw, $preRaw, 0);
        } catch (\Exception $e) {
            Log::error('Bridge: verifySolanaTokenDeposit failed', [
                'tx' => $txHash,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Verify a native SOL deposit by computing the lamport balance delta on
     * the hot wallet (system-transfer deposits carry no token balances). The
     * sender must have lost lamports in the same transaction so a transfer
     * between third parties can't be replayed as a deposit.
     *
     * @return string|null raw lamport delta on the hot wallet, or null if not a valid deposit
     */
    public function verifySolanaNativeDeposit(
        string $txHash,
        string $expectedSender,
        ?string $expectedRecipient = null,
    ): ?string {
        $expectedRecipient ??= $this->solanaHotWallet();

        try {
            $response = Http::timeout(30)->post($this->solanaRpc(), [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getTransaction',
                'params' => [
                    $txHash,
                    ['encoding' => 'jsonParsed', 'commitment' => 'confirmed', 'maxSupportedTransactionVersion' => 0],
                ],
            ]);

            if (! $response->successful()) {
                Log::error('Bridge: Solana RPC HTTP error', ['tx' => $txHash, 'status' => $response->status()]);

                return null;
            }

            $result = $response->json('result');

            if (! $result || ($result['meta']['err'] ?? null) !== null) {
                Log::warning('Bridge: Solana tx not found or failed', ['tx' => $txHash]);

                return null;
            }

            $lamports = $this->indexLamportBalances($result);
            [$recipientPre, $recipientPost] = $lamports[$expectedRecipient] ?? ['0', '0'];

            if (bccomp($recipientPost, $recipientPre, 0) <= 0) {
                Log::warning('Bridge: hot wallet lamports did not increase', [
                    'tx' => $txHash,
                    'pre' => $recipientPre,
                    'post' => $recipientPost,
                ]);

                return null;
            }

            [$senderPre, $senderPost] = $lamports[$expectedSender] ?? [null, null];

            if ($senderPre === null || bccomp($senderPre, (string) $senderPost, 0) <= 0) {
                Log::warning('Bridge: sender lamports did not decrease', [
                    'tx' => $txHash,
                    'sender' => $expectedSender,
                ]);

                return null;
            }

            return bcsub($recipientPost, $recipientPre, 0);
        } catch (\Exception $e) {
            Log::error('Bridge: verifySolanaNativeDeposit failed', [
                'tx' => $txHash,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Index meta.preBalances/postBalances by account pubkey → [pre, post]
     * lamport strings, using the parallel transaction.message.accountKeys.
     *
     * @param  array<string, mixed>  $result  getTransaction result (jsonParsed)
     * @return array<string, array{0: string, 1: string}>
     */
    private function indexLamportBalances(array $result): array
    {
        $accountKeys = $result['transaction']['message']['accountKeys'] ?? [];
        $pre = $result['meta']['preBalances'] ?? [];
        $post = $result['meta']['postBalances'] ?? [];

        $indexed = [];

        foreach (array_values($accountKeys) as $i => $entry) {
            $pubkey = is_array($entry) ? ($entry['pubkey'] ?? null) : $entry;

            if (is_string($pubkey) && isset($pre[$i], $post[$i])) {
                $indexed[$pubkey] = [(string) $pre[$i], (string) $post[$i]];
            }
        }

        return $indexed;
    }

    /**
     * Index pre/postTokenBalances entries by "owner:mint" → raw amount string.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, string>
     */
    private function indexTokenBalances(array $entries): array
    {
        $indexed = [];

        foreach ($entries as $entry) {
            $owner = $entry['owner'] ?? null;
            $mint = $entry['mint'] ?? null;
            $amount = $entry['uiTokenAmount']['amount'] ?? null;

            if (is_string($owner) && is_string($mint) && is_string($amount)) {
                $indexed[$owner.':'.$mint] = $amount;
            }
        }

        return $indexed;
    }

    /**
     * Verify an ERC20 deposit on an EVM chain. Returns the raw transfer amount
     * (in the token's decimals) or null if the tx does not contain a matching
     * Transfer(sender → expectedRecipient) event for the given token.
     */
    public function verifyEvmDeposit(
        string $txHash,
        string $expectedSender,
        string $tokenAddress,
        string $expectedRecipient,
        ?string $rpcUrl = null,
    ): ?string {
        $rpc = $rpcUrl
            ?: config('services.bridge.evm_rpc_url')
            ?: config('services.ethereum.rpc_url', 'https://rpc.cyberia.church');

        try {
            $response = Http::timeout(15)->post($rpc, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_getTransactionReceipt',
                'params' => [$txHash],
            ]);

            $receipt = $response->json('result');

            if (! is_array($receipt) || ($receipt['status'] ?? null) !== '0x1') {
                Log::warning('Bridge: EVM receipt missing or failed', ['tx' => $txHash]);

                return null;
            }

            $transferTopic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
            $token = strtolower($tokenAddress);
            $sender = strtolower($expectedSender);
            $recipient = strtolower($expectedRecipient);

            foreach ($receipt['logs'] ?? [] as $log) {
                if (strtolower($log['address'] ?? '') !== $token) {
                    continue;
                }

                $topics = $log['topics'] ?? [];

                if (count($topics) < 3 || strtolower($topics[0]) !== $transferTopic) {
                    continue;
                }

                $from = '0x'.strtolower(substr($topics[1], -40));
                $to = '0x'.strtolower(substr($topics[2], -40));

                if ($from !== $sender || $to !== $recipient) {
                    continue;
                }

                return TokenAmount::hexToDec($log['data'] ?? '0x0');
            }

            Log::warning('Bridge: no matching Transfer log', [
                'tx' => $txHash,
                'token' => $tokenAddress,
                'expected_recipient' => $expectedRecipient,
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('Bridge: verifyEvmDeposit failed', [
                'tx' => $txHash,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Verify a NATIVE-coin deposit on an EVM chain (e.g. BNB sent to the
     * relayer EOA on BSC): the tx itself must move value from the sender to
     * the deposit address and be successfully mined. Returns the value in wei.
     */
    public function verifyEvmNativeDeposit(
        string $txHash,
        string $expectedSender,
        string $expectedRecipient,
        string $rpcUrl,
    ): ?string {
        try {
            $txResponse = Http::timeout(15)->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_getTransactionByHash',
                'params' => [$txHash],
            ]);

            $tx = $txResponse->json('result');

            if (! is_array($tx)) {
                Log::warning('Bridge: EVM native tx not found', ['tx' => $txHash]);

                return null;
            }

            if (strtolower((string) ($tx['from'] ?? '')) !== strtolower($expectedSender)
                || strtolower((string) ($tx['to'] ?? '')) !== strtolower($expectedRecipient)) {
                Log::warning('Bridge: EVM native tx sender/recipient mismatch', ['tx' => $txHash]);

                return null;
            }

            $receiptResponse = Http::timeout(15)->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'eth_getTransactionReceipt',
                'params' => [$txHash],
            ]);

            $receipt = $receiptResponse->json('result');

            if (! is_array($receipt) || ($receipt['status'] ?? null) !== '0x1') {
                Log::warning('Bridge: EVM native receipt missing or failed', ['tx' => $txHash]);

                return null;
            }

            $value = TokenAmount::hexToDec((string) ($tx['value'] ?? '0x0'));

            return bccomp($value, '0', 0) > 0 ? $value : null;
        } catch (\Throwable $e) {
            Log::error('Bridge: verifyEvmNativeDeposit failed', [
                'tx' => $txHash,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Direct/mint-model relay, config-driven: the route's source and
     * destination chains (config/bridge.php) pick the verification and payout
     * strategies, so adding an EVM chain is a config-only change.
     */
    public function processDirectRelay(BridgeRequest $request): bool
    {
        if (! $request->isPending()) {
            return false;
        }

        $tokenConfig = config('bridge.tokens', [])[$request->token] ?? null;

        if (! is_array($tokenConfig)) {
            $request->markFailed("Unknown token: {$request->token}");

            return false;
        }

        $route = config('bridge.routes', [])[$request->direction] ?? null;

        if (! is_array($route)) {
            $request->markFailed("Unknown direction: {$request->direction}");

            return false;
        }

        $sourceChain = config('bridge.chains', [])[$route['source_chain']] ?? null;
        $destinationChain = config('bridge.chains', [])[$route['destination_chain']] ?? null;

        if (! is_array($sourceChain) || ! is_array($destinationChain)) {
            $request->markFailed("Route {$request->direction} references an unknown chain");

            return false;
        }

        $sourceToken = $tokenConfig['chains'][$sourceChain['key']] ?? null;
        $destinationToken = $tokenConfig['chains'][$destinationChain['key']] ?? null;

        if (! is_array($sourceToken) || ! is_array($destinationToken)) {
            $request->markFailed("Token {$request->token} is not configured for route {$request->direction}");

            return false;
        }

        $request->markProcessing();

        try {
            $feeAmount = (string) ($request->fee_amount ?: '0');
            $nativeGasFee = app(BridgeFeeService::class)->nativePayoutFee(
                $request->direction,
                $request->token,
            );

            if (bccomp($nativeGasFee, $feeAmount, 18) > 0) {
                $feeAmount = $nativeGasFee;
                $request->update(['fee_amount' => $feeAmount]);
            }

            $netAmount = bcsub((string) $request->amount, $feeAmount, 18);

            if (bccomp($netAmount, '0', 18) <= 0) {
                $request->markFailed('Net amount after fee is zero or negative');

                return false;
            }

            $verified = $this->verifySourceDeposit($request, $sourceChain, $sourceToken);

            if ($verified === null) {
                $request->markFailed("Could not verify {$sourceChain['label']} deposit transaction");

                return false;
            }

            $claimedRaw = TokenAmount::toRaw((string) $request->amount, (int) $sourceToken['decimals']);

            // The on-chain transfer is the ground truth. The requested amount
            // can drift a few wei ABOVE what actually arrived (float casts in
            // the web layer and SQLite's REAL storage round 18-dec strings at
            // ~15 significant digits), and a user can also simply deposit less
            // than they typed. Either way settle for what the chain says —
            // never fail the request, never pay out more than was received.
            if (bccomp($verified, $claimedRaw, 0) < 0) {
                $verifiedAmount = TokenAmount::fromRaw($verified, (int) $sourceToken['decimals']);

                Log::warning('Bridge: deposit below claimed amount, settling for verified', [
                    'id' => $request->id,
                    'claimed' => $claimedRaw,
                    'verified' => $verified,
                ]);

                $request->update(['amount' => $verifiedAmount]);
                $claimedRaw = $verified;
                $netAmount = bcsub($verifiedAmount, $feeAmount, 18);

                if (bccomp($netAmount, '0', 18) <= 0) {
                    $request->markFailed('Net amount after fee is zero or negative');

                    return false;
                }
            }

            // Leaving a chain where the deposit is a relayer-owned wrapper
            // (the home chain, or an 'owned' entry like bridged CYBER on
            // Robinhood) with a mint-model token: destroy the wrapper the
            // user deposited so supply stays backed by the destination-side
            // reserve. Deposits on external EVM chains (e.g. canonical USDT
            // on BSC) are reserves — never burn. Idempotent: a retry after a
            // failed payout must not burn again — the wrapper is already gone.
            if (($sourceChain['type'] ?? '') === 'evm'
                && ($sourceChain['key'] === config('bridge.home_chain', 'cyberia')
                    || ($sourceToken['owned'] ?? false))
                && ($tokenConfig['model'] ?? 'direct') === 'mint'
                && ! ($sourceToken['native'] ?? false)
                && ! $request->wrapper_burned) {
                $burned = $this->burnEvmWrapper(
                    (string) $sourceToken['address'],
                    $claimedRaw,
                    $request->id,
                    $sourceChain,
                );

                if (! $burned) {
                    $request->markFailed('Failed to burn wrapped EVM tokens after user deposit');

                    return false;
                }

                $request->update(['wrapper_burned' => true]);
            }

            return $this->payoutDestination($request, $destinationChain, $destinationToken, $tokenConfig, $netAmount);
        } catch (\Throwable $e) {
            $request->markFailed($e->getMessage());
            Log::error('Bridge: direct relay failed', [
                'id' => $request->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Verify the source-chain deposit and return the raw deposited amount.
     *
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>  $tokenEntry
     */
    private function verifySourceDeposit(BridgeRequest $request, array $chain, array $tokenEntry): ?string
    {
        return match ($chain['type'] ?? '') {
            'solana' => ($tokenEntry['native'] ?? false)
                ? $this->verifySolanaNativeDeposit(
                    $request->source_tx_hash,
                    $request->sender_address,
                    $chain['deposit_address'] ?? null,
                )
                : $this->verifySolanaTokenDeposit(
                    $request->source_tx_hash,
                    $request->sender_address,
                    (string) $tokenEntry['mint'],
                    $chain['deposit_address'] ?? null,
                ),
            'evm' => ($tokenEntry['native'] ?? false)
                ? $this->verifyEvmNativeDeposit(
                    $request->source_tx_hash,
                    $request->sender_address,
                    $this->evmDepositAddress($chain),
                    (string) $chain['rpc_url'],
                )
                : $this->verifyEvmDeposit(
                    $request->source_tx_hash,
                    $request->sender_address,
                    (string) $tokenEntry['address'],
                    $this->evmDepositAddress($chain),
                    (string) $chain['rpc_url'],
                ),
            'ton' => $this->verifyTonDeposit($request, $chain, $tokenEntry),
            // Whatever the user deposited to THIS request's one-time address is
            // what gets minted — the address binds the deposit to this request
            // and its committed recipient. No amount or tx hash needed.
            'yenten' => $request->deposit_address
                ? app(YentenApiService::class)->addressBalance((string) $request->deposit_address)
                : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>  $tokenEntry
     */
    private function verifyTonDeposit(BridgeRequest $request, array $chain, array $tokenEntry): ?string
    {
        $txHash = TonApiService::normalizeTxHash($request->source_tx_hash);
        $sender = TonApiService::normalizeAddress($request->sender_address);
        $recipient = TonApiService::normalizeAddress((string) ($chain['deposit_address'] ?? ''));

        if (! $txHash || ! $sender || ! $recipient) {
            Log::warning('Bridge: TON deposit fields failed normalization', [
                'id' => $request->id,
                'tx_ok' => (bool) $txHash,
                'sender_ok' => (bool) $sender,
            ]);

            return null;
        }

        $tonApi = app(TonApiService::class);

        if ($tokenEntry['native'] ?? false) {
            $verified = $tonApi->verifyNativeDeposit($txHash, $sender, $recipient);
        } else {
            $master = TonApiService::normalizeAddress((string) $tokenEntry['master']);

            if (! $master) {
                return null;
            }

            $verified = $tonApi->verifyJettonDeposit($txHash, $sender, $master, $recipient);
        }

        if ($verified === null) {
            return null;
        }

        // TON Connect submits the external-message hash, not the transaction
        // hash. Re-point the request at the canonical event id so the same
        // deposit resubmitted under its other encoding trips the
        // source_tx_hash unique constraint instead of double-minting.
        if ($verified['event_id'] !== $txHash) {
            try {
                $request->update(['source_tx_hash' => $verified['event_id']]);
            } catch (UniqueConstraintViolationException) {
                Log::warning('Bridge: TON deposit already claimed under its canonical hash', [
                    'id' => $request->id,
                    'event_id' => $verified['event_id'],
                ]);

                return null;
            }
        }

        return $verified['amount'];
    }

    /**
     * Deposit address on an EVM chain: explicit config value or relayer EOA.
     *
     * @param  array<string, mixed>  $chain
     */
    private function evmDepositAddress(array $chain): string
    {
        $explicit = $chain['deposit_address'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return (string) app(BridgeRelayerService::class)->evmAddress();
    }

    /**
     * Pay out the net amount on the destination chain.
     *
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>  $tokenEntry
     * @param  array<string, mixed>  $tokenConfig
     */
    private function payoutDestination(
        BridgeRequest $request,
        array $chain,
        array $tokenEntry,
        array $tokenConfig,
        string $netAmount,
    ): bool {
        return match ($chain['type'] ?? '') {
            'evm' => $this->payoutEvm($request, $chain, $tokenEntry, $tokenConfig, $netAmount),
            'solana' => $this->payoutSolana($request, $chain, $tokenEntry, $netAmount),
            'ton' => $this->payoutTon($request, $chain, $tokenEntry, $netAmount),
            'yenten' => $this->payoutYenten($request, $chain, $tokenEntry, $netAmount),
            default => tap(false, fn () => $request->markFailed("No payout strategy for chain type '{$chain['type']}'")),
        };
    }

    /**
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>  $tokenEntry
     * @param  array<string, mixed>  $tokenConfig
     */
    private function payoutEvm(
        BridgeRequest $request,
        array $chain,
        array $tokenEntry,
        array $tokenConfig,
        string $netAmount,
    ): bool {
        $amountRaw = TokenAmount::toRaw($netAmount, (int) $tokenEntry['decimals']);

        $gasDropWei = '0';

        if ($request->gas_drop_planned && $request->gas_drop_amount) {
            $gasDropWei = TokenAmount::toRaw((string) $request->gas_drop_amount, 18);
        }

        $isHomeChain = ($chain['key'] ?? '') === config('bridge.home_chain', 'cyberia');

        if ($tokenEntry['native'] ?? false) {
            // Native-coin payout from the relayer balance (e.g. BNB on BSC).
            $args = ['scripts/relay-native-transfer.ts', $request->recipient_address, $amountRaw];
        } elseif (($tokenConfig['model'] ?? 'direct') === 'mint'
            && ($isHomeChain || ($tokenEntry['owned'] ?? false))) {
            // Relayer owns the wrapper (home chain, or an 'owned' entry such
            // as bridged CYBER on Robinhood): mint to recipient on demand.
            $args = ['scripts/relay-mint.ts', (string) $tokenEntry['address'], $request->recipient_address, $amountRaw, $gasDropWei];
        } else {
            // External chain or direct model: pay out of relayer inventory
            // (e.g. canonical USDT on BSC) with a plain ERC20 transfer.
            $args = ['scripts/relay-erc20-transfer.ts', (string) $tokenEntry['address'], $request->recipient_address, $amountRaw, $gasDropWei];
        }

        $txHash = $this->runEvmRelayScript($args, $chain, $request->id);

        if ($txHash === null) {
            $request->markFailed('Relay failed on '.$chain['label']);

            return false;
        }

        $request->markCompleted($txHash);

        return true;
    }

    /**
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>  $tokenEntry
     */
    private function payoutSolana(BridgeRequest $request, array $chain, array $tokenEntry, string $netAmount): bool
    {
        $amountRaw = TokenAmount::toRaw($netAmount, (int) $tokenEntry['decimals']);

        $scriptDir = Environment::isProduction()
            ? '/singularity/crypto/anchor'
            : base_path('/../../crypto/anchor');

        $home = env('HOME', $_SERVER['HOME'] ?? '/home/lain');

        $walletPath = Environment::isProduction()
            ? '/solana/id.json'
            : $home.'/.config/solana/id.json';

        // Native SOL pays out with a plain system transfer from the hot
        // wallet; SPL tokens go through the parametrised token relay.
        $args = ($tokenEntry['native'] ?? false)
            ? [
                'scripts/relay-sol-transfer.ts',
                $request->recipient_address,
                $amountRaw,
            ]
            : [
                'scripts/relay-spl-transfer.ts',
                (string) $tokenEntry['mint'],
                $request->recipient_address,
                $amountRaw,
                (string) ($tokenEntry['token_program'] ?? 'token'),
            ];

        $result = Process::path($scriptDir)
            ->env([
                'ANCHOR_PROVIDER_URL' => (string) $chain['rpc_url'],
                'ANCHOR_WALLET' => $walletPath,
            ])
            ->timeout(120)
            ->run(['npx', 'ts-node', '--transpile-only', ...$args]);

        Log::info('Bridge relay payout solana', [
            'id' => $request->id,
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'exit' => $result->exitCode(),
        ]);

        if ($result->exitCode() !== 0) {
            $request->markFailed('Solana relay failed: '.$result->errorOutput());

            return false;
        }

        $json = $this->lastJsonLine($result->output());

        if (! $json || empty($json['txHash'])) {
            $request->markFailed('Could not parse Solana relay output');

            return false;
        }

        $request->markCompleted((string) $json['txHash']);

        return true;
    }

    /**
     * TON payout via crypto/ton relay scripts: native Toncoin through
     * relay-ton-transfer.ts, jettons through relay-jetton-transfer.ts.
     * query_id = request id makes the transfer idempotent: each script checks
     * for an existing outgoing transfer with the same query_id before sending.
     *
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>  $tokenEntry
     */
    private function payoutTon(BridgeRequest $request, array $chain, array $tokenEntry, string $netAmount): bool
    {
        $mnemonic = (string) config('services.bridge.ton_relayer_mnemonic');

        if ($mnemonic === '') {
            $request->markFailed('TON relayer mnemonic not configured (TON_RELAYER_MNEMONIC)');

            return false;
        }

        $amountRaw = TokenAmount::toRaw($netAmount, (int) $tokenEntry['decimals']);

        $scriptDir = Environment::isProduction()
            ? '/singularity/crypto/ton'
            : base_path('/../../crypto/ton');

        $args = ($tokenEntry['native'] ?? false)
            ? [
                'scripts/relay-ton-transfer.ts',
                $request->recipient_address,
                $amountRaw,
                (string) $request->id,
            ]
            : [
                'scripts/relay-jetton-transfer.ts',
                (string) $tokenEntry['master'],
                $request->recipient_address,
                $amountRaw,
                (string) $request->id,
            ];

        $result = Process::path($scriptDir)
            ->env([
                'TON_RELAYER_MNEMONIC' => $mnemonic,
                'TONCENTER_RPC_URL' => (string) ($chain['toncenter_rpc_url'] ?? 'https://toncenter.com/api/v2/jsonRPC'),
                'TONCENTER_API_KEY' => (string) config('services.bridge.toncenter_api_key', ''),
                'TONAPI_URL' => (string) ($chain['api_url'] ?? 'https://tonapi.io'),
                'TONAPI_KEY' => (string) ($chain['api_key'] ?? ''),
            ])
            ->timeout(240)
            ->run(['npx', 'tsx', ...$args]);

        Log::info('Bridge relay payout ton', [
            'id' => $request->id,
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'exit' => $result->exitCode(),
        ]);

        if ($result->exitCode() !== 0) {
            $request->markFailed('TON relay failed: '.$result->errorOutput());

            return false;
        }

        $json = $this->lastJsonLine($result->output());

        if (! $json || empty($json['txHash'])) {
            $request->markFailed('Could not parse TON relay output');

            return false;
        }

        $request->markCompleted((string) $json['txHash']);

        return true;
    }

    /**
     * Native YTN payout through the official light-wallet API. The WIF stays
     * local to the relay process; only the signed raw transaction is broadcast.
     *
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>  $tokenEntry
     */
    private function payoutYenten(BridgeRequest $request, array $chain, array $tokenEntry, string $netAmount): bool
    {
        // Liquidity is pooled across every relayer-controlled address: the
        // central wallet plus the one-time deposit addresses (funds from
        // prior yenten_to_evm bridge-ins). The recipient receives the net
        // amount exactly; the network fee is paid by the pool out of the
        // flat YTN bridge fee retained above (yenten_payout_fee_ytn).
        $wifs = $this->yentenPayoutKeys($chain);

        if ($wifs === []) {
            $request->markFailed('Yenten relayer key not configured (BRIDGE_YENTEN_RELAYER_WIF / deposits)');

            return false;
        }

        $amountRaw = TokenAmount::toRaw($netAmount, (int) $tokenEntry['decimals']);
        $scriptDir = Environment::isProduction()
            ? '/singularity/crypto/yenten'
            : base_path('/../../crypto/yenten');

        $result = Process::path($scriptDir)
            ->env([
                'YENTEN_RELAYER_WIFS' => json_encode(array_values($wifs)),
                'YENTEN_CHANGE_ADDRESS' => (string) ($chain['deposit_address'] ?? ''),
                'YENTEN_API_URL' => (string) ($chain['api_url'] ?? 'https://api.yentencoin.info'),
            ])
            // Generous: the relay script retries slow light-wallet API reads
            // and reconciles a lost /broadcast response by polling the txid.
            ->timeout(300)
            ->run([
                'npm', 'run', '--silent', 'relay', '--',
                $request->recipient_address,
                $amountRaw,
                (string) $request->id,
            ]);

        Log::info('Bridge relay payout yenten', [
            'id' => $request->id,
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'exit' => $result->exitCode(),
        ]);

        if ($result->exitCode() !== 0) {
            $request->markFailed('Yenten relay failed: '.$result->errorOutput());

            return false;
        }

        $json = $this->lastJsonLine($result->output());

        if (! $json || empty($json['txHash'])) {
            $request->markFailed('Could not parse Yenten relay output');

            return false;
        }

        // Mark the deposit addresses whose coins funded this payout as swept.
        foreach ((array) ($json['spentAddresses'] ?? []) as $address) {
            BridgeRequest::where('deposit_address', $address)->update(['swept' => true]);
        }

        $request->markCompleted((string) $json['txHash']);

        return true;
    }

    /**
     * Relayer signing keys for a Yenten payout: the central wallet plus the
     * one-time deposit-address keys still holding funds (unswept). Deposit keys
     * are stored encrypted per request.
     *
     * @param  array<string, mixed>  $chain
     * @return array<int, string> WIFs
     */
    private function yentenPayoutKeys(array $chain): array
    {
        $wifs = [];

        $central = (string) ($chain['relayer_wif'] ?? '');

        if ($central !== '') {
            $wifs[] = $central;
        }

        // Bounded set of unswept deposit keys. Only COMPLETED requests: their
        // deposit is claimed and minted, so the coins belong to the pool. An
        // awaiting_deposit address may hold a deposit the user has not claimed
        // yet — spending it would make the later claim come up empty. Never-
        // funded/expired rows are excluded the same way, so the relay does not
        // hammer the API over addresses that cannot hold pool funds.
        $deposits = BridgeRequest::query()
            ->where('source_chain', 'yenten')
            ->where('status', 'completed')
            ->whereNotNull('deposit_wif')
            ->where('swept', false)
            ->latest('id')
            ->limit(50)
            ->pluck('deposit_wif');

        foreach ($deposits as $wif) {
            if (is_string($wif) && $wif !== '') {
                $wifs[] = $wif;
            }
        }

        return array_values(array_unique($wifs));
    }

    /**
     * Run a hardhat relay script against an arbitrary EVM chain and return
     * the resulting tx hash, or null on failure.
     *
     * The relay scripts broadcast the payout, print its hash, then block on
     * the receipt. A slow destination RPC can push that wait past the timeout
     * even though the payout is already in the mempool — so we stream stdout
     * into a buffer and, on timeout, recover the broadcast hash. Returning it
     * lets the caller record the request completed instead of failing it; a
     * blind retry would send the payout a second time.
     *
     * @param  array<int, string>  $args  script path + its arguments
     * @param  array<string, mixed>  $chain
     */
    private function runEvmRelayScript(array $args, array $chain, int $requestId): ?string
    {
        $hardhatDir = Environment::isProduction()
            ? '/singularity/crypto/hardhat'
            : base_path('/../../crypto/hardhat');

        $captured = '';

        try {
            $result = Process::path($hardhatDir)
                ->env([
                    'EVM_RPC_URL' => (string) $chain['rpc_url'],
                    'EVM_CHAIN_ID' => (string) ($chain['evm_chain_id'] ?? ''),
                    // Back-compat for scripts still reading the legacy var.
                    'CYBERIA_RPC_URL' => (string) $chain['rpc_url'],
                    'BRIDGE_RELAYER_PRIVATE_KEY' => app(BridgeRelayerService::class)->privateKey() ?? '',
                ])
                ->timeout(120)
                ->run(['npx', 'tsx', ...$args], function (string $type, string $buffer) use (&$captured) {
                    $captured .= $buffer;
                });
        } catch (ProcessTimedOutException $e) {
            $broadcastHash = $this->extractRelayTxHash($captured);

            Log::warning('Bridge relay evm script timed out waiting for receipt', [
                'id' => $requestId,
                'chain' => $chain['key'] ?? null,
                'script' => $args[0] ?? null,
                'broadcast_tx_hash' => $broadcastHash,
                'output' => $captured,
            ]);

            // null when it timed out before broadcasting — safe to retry then.
            return $broadcastHash;
        }

        Log::info('Bridge relay evm script', [
            'id' => $requestId,
            'chain' => $chain['key'] ?? null,
            'script' => $args[0] ?? null,
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'exit' => $result->exitCode(),
        ]);

        if ($result->exitCode() !== 0) {
            // The script can broadcast the payout and then exit non-zero while
            // waiting for the receipt (a flaky destination RPC dropping the
            // connection: `read ETIMEDOUT`). The tx is already on-chain, so a
            // blind retry double-pays. If a broadcast hash reached us, confirm
            // it on-chain: recover it unless the chain says it reverted.
            $broadcastHash = $this->extractRelayTxHash($result->output());

            if ($broadcastHash !== null
                && $this->evmTxSucceeded((string) $chain['rpc_url'], $broadcastHash) !== false) {
                Log::warning('Bridge relay evm script exited non-zero after broadcasting; recovered hash', [
                    'id' => $requestId,
                    'chain' => $chain['key'] ?? null,
                    'script' => $args[0] ?? null,
                    'broadcast_tx_hash' => $broadcastHash,
                    'stderr' => $result->errorOutput(),
                ]);

                return $broadcastHash;
            }

            return null;
        }

        return $this->extractRelayTxHash($result->output());
    }

    /**
     * On-chain receipt status for a relayer payout hash:
     *   true  = mined and succeeded,
     *   false = mined and reverted (a real failure, safe to retry),
     *   null  = not found yet / RPC unreachable (treat as still pending — the
     *           tx was broadcast, so recovering its hash beats a double-pay).
     */
    private function evmTxSucceeded(string $rpcUrl, string $txHash): ?bool
    {
        try {
            $response = Http::timeout(15)->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_getTransactionReceipt',
                'params' => [$txHash],
            ]);

            $receipt = $response->json('result');

            if (! is_array($receipt)) {
                return null;
            }

            return ($receipt['status'] ?? null) === '0x1';
        } catch (\Throwable $e) {
            Log::warning('Bridge: evmTxSucceeded receipt check failed', [
                'tx' => $txHash,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Pull the relayer tx hash from EVM relay-script stdout, newest line first.
     * Accepts the confirmed {"txHash":...} line and the pre-receipt
     * {"broadcastTxHash":...} line the scripts print the moment the tx is
     * broadcast, so a receipt-wait timeout can still recover the hash.
     */
    private function extractRelayTxHash(string $output): ?string
    {
        $lines = array_reverse(array_filter(array_map('trim', explode("\n", $output))));

        foreach ($lines as $line) {
            $json = json_decode($line, true);

            if (! is_array($json)) {
                continue;
            }

            $hash = $json['txHash'] ?? $json['broadcastTxHash'] ?? null;

            if (is_string($hash) && $hash !== '') {
                return $hash;
            }
        }

        return null;
    }

    /**
     * Burn the relayer's freshly-received wrapper-token balance so EVM supply
     * stays in sync with the destination-side reserve. Used by the mint model
     * when bridging OUT of an EVM chain.
     *
     * @param  array<string, mixed>  $chain
     */
    private function burnEvmWrapper(string $tokenAddress, string $amountWei, int $requestId, array $chain): bool
    {
        $hardhatDir = Environment::isProduction()
            ? '/singularity/crypto/hardhat'
            : base_path('/../../crypto/hardhat');

        $result = Process::path($hardhatDir)
            ->env([
                'EVM_RPC_URL' => (string) $chain['rpc_url'],
                'EVM_CHAIN_ID' => (string) ($chain['evm_chain_id'] ?? ''),
                'CYBERIA_RPC_URL' => (string) $chain['rpc_url'],
                'BRIDGE_RELAYER_PRIVATE_KEY' => app(BridgeRelayerService::class)->privateKey() ?? '',
            ])
            ->timeout(120)
            ->run([
                'npx', 'tsx', 'scripts/relay-burn.ts',
                $tokenAddress,
                $amountWei,
            ]);

        Log::info('Bridge relay burn wrapper', [
            'id' => $requestId,
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'exit' => $result->exitCode(),
        ]);

        return $result->exitCode() === 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastJsonLine(string $output): ?array
    {
        $lines = array_filter(explode("\n", trim($output)));
        $last = end($lines);

        if ($last === false) {
            return null;
        }

        $decoded = json_decode($last, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Process Solana->EVM: verify deposit, then call relay script to mint CYBER.sol on EVM.
     */
    public function processSolToEvm(BridgeRequest $request): bool
    {
        if (! $request->isPending()) {
            return false;
        }

        $request->markProcessing();

        try {
            // Verify the Solana transaction is a real deposit to our hot wallet
            $verifiedAmount = $this->verifySolanaDeposit(
                $request->source_tx_hash,
                $request->sender_address,
            );

            if ($verifiedAmount === null) {
                $request->markFailed('Could not verify Solana deposit transaction');

                return false;
            }

            Log::info('Bridge: Solana deposit verified', [
                'id' => $request->id,
                'verified_amount_raw' => $verifiedAmount,
                'claimed_amount' => $request->amount,
            ]);

            // Use the per-request fee_amount (computed by BridgeFeeService at
            // submit time). For CYBER.sol this is 0 — only stables carry a
            // fee. Falls back to legacy 1% if no fee was stored (very old rows).
            $feeAmount = $request->fee_amount !== null
                ? (string) $request->fee_amount
                : self::calculateFee((string) $request->amount);

            $amountAfterFee = bcsub((string) $request->amount, $feeAmount, 18);
            $amountWei = TokenAmount::toRaw($amountAfterFee, 18);

            // Optional native-CYBER gas drop for empty recipients.
            $gasDropWei = '0';
            if ($request->gas_drop_planned && $request->gas_drop_amount) {
                $gasDropWei = TokenAmount::toRaw((string) $request->gas_drop_amount, 18);
            }

            Log::info('Bridge: sol_to_evm fee applied', [
                'id' => $request->id,
                'original' => $request->amount,
                'fee' => $feeAmount,
                'after_fee' => $amountAfterFee,
                'gas_drop_wei' => $gasDropWei,
                'convert_to_native' => $request->convert_to_native,
            ]);

            $hardhatDir = Environment::isProduction()
                ? '/singularity/crypto/hardhat'
                : base_path('/../../crypto/hardhat');

            $result = Process::path($hardhatDir)
                ->env([
                    'CYBERIA_RPC_URL' => Environment::isProduction()
                        ? 'http://polygon-edge:8545'
                        : 'https://rpc.cyberia.church',
                    'BRIDGE_EVM_CONTRACT_ADDRESS' => config('services.bridge.evm_bridge_address'),
                    'BRIDGE_RELAYER_PRIVATE_KEY' => app(BridgeRelayerService::class)->privateKey() ?? '',
                    'CYBERSOL_BURN_SWAP_ADDRESS' => (string) config('bridge.convert.burn_swap_address'),
                    'CYBER_SOL_TOKEN_ADDRESS' => (string) (config('bridge.tokens', [])['CYBER.sol']['chains']['cyberia']['address'] ?? ''),
                ])
                ->timeout(120)
                ->run([
                    'npx', 'tsx', 'scripts/relay-bridge.ts',
                    'sol_to_evm',
                    $request->recipient_address,
                    $amountWei,
                    (string) $request->id,
                    $gasDropWei,
                    $request->convert_to_native ? '1' : '0',
                ]);

            Log::info('Bridge relay sol_to_evm', [
                'id' => $request->id,
                'stdout' => $result->output(),
                'stderr' => $result->errorOutput(),
                'exit' => $result->exitCode(),
            ]);

            if ($result->exitCode() !== 0) {
                $request->markFailed('Relay failed: '.$result->errorOutput());

                return false;
            }

            $lines = array_filter(explode("\n", trim($result->output())));
            $json = json_decode(end($lines), true);

            if ($json && isset($json['txHash'])) {
                // Record the conversion outcome: the relayer falls back to a
                // plain CYBER.sol delivery when the burn-swap lacks liquidity.
                if ($request->convert_to_native) {
                    $request->update(['converted' => (bool) ($json['converted'] ?? false)]);
                }

                $request->markCompleted($json['txHash']);

                return true;
            }

            $request->markFailed('Could not parse relay output');

            return false;
        } catch (\Exception $e) {
            $request->markFailed($e->getMessage());
            Log::error('Bridge: SolToEvm failed', ['id' => $request->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Process EVM->Solana: send CYBER SPL tokens from hot wallet to recipient.
     */
    public function processEvmToSol(BridgeRequest $request): bool
    {
        if (! $request->isPending()) {
            return false;
        }

        $request->markProcessing();

        try {
            $feeAmount = $request->fee_amount !== null
                ? (string) $request->fee_amount
                : self::calculateFee((string) $request->amount);

            $amountAfterFee = bcsub((string) $request->amount, $feeAmount, 18);

            Log::info('Bridge: evm_to_sol fee applied', [
                'id' => $request->id,
                'original' => $request->amount,
                'fee' => $feeAmount,
                'after_fee' => $amountAfterFee,
            ]);

            // Convert amount to Solana smallest units (CYBER.sol mint decimals).
            $solanaDecimals = (int) (config('bridge.tokens', [])['CYBER.sol']['chains']['solana']['decimals'] ?? 6);
            $amountRaw = TokenAmount::toRaw($amountAfterFee, $solanaDecimals);

            $scriptDir = Environment::isProduction()
                ? '/singularity/crypto/anchor'
                : base_path('/../../crypto/anchor');

            $home = env('HOME', $_SERVER['HOME'] ?? '/home/lain');

            $walletPath = Environment::isProduction()
                ? '/solana/id.json'
                : $home.'/.config/solana/id.json';

            $result = Process::path($scriptDir)
                ->env([
                    'ANCHOR_PROVIDER_URL' => $this->solanaRpc(),
                    'ANCHOR_WALLET' => $walletPath,
                ])
                ->timeout(120)
                ->run([
                    'npx', 'ts-node', '--transpile-only', 'scripts/relay-release-native.ts',
                    $request->recipient_address,
                    $amountRaw,
                ]);

            Log::info('Bridge relay evm_to_sol', [
                'id' => $request->id,
                'stdout' => $result->output(),
                'stderr' => $result->errorOutput(),
                'exit' => $result->exitCode(),
            ]);

            if ($result->exitCode() !== 0) {
                $request->markFailed('Solana relay failed: '.$result->errorOutput());

                return false;
            }

            $lines = array_filter(explode("\n", trim($result->output())));
            $json = json_decode(end($lines), true);

            if ($json && isset($json['txHash'])) {
                $request->markCompleted($json['txHash']);

                return true;
            }

            $request->markFailed('Could not parse Solana relay output');

            return false;
        } catch (\Exception $e) {
            $request->markFailed($e->getMessage());
            Log::error('Bridge: EvmToSol failed', ['id' => $request->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
