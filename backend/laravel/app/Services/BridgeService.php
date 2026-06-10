<?php

namespace App\Services;

use App\Models\BridgeRequest;
use App\Support\Environment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class BridgeService
{
    /**
     * Hot wallet address on Solana (receives deposits from users).
     */
    private const SOLANA_HOT_WALLET = 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w';

    /**
     * CYBER token mint on Solana devnet (9 decimals).
     */
    private const SOLANA_CYBER_MINT = 'E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump';

    private const SOLANA_RPC = 'https://mainnet.helius-rpc.com/?api-key=7e740762-a25d-4d37-b854-de4cec9815ed';

    /**
     * Bridge fee percentage (1% = 0.01).
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

    public function createRequest(
        ?int $userId,
        string $direction,
        string $sourceChain,
        string $sourceTxHash,
        int $sourceNonce,
        string $senderAddress,
        string $recipientAddress,
        string $amount,
        string $token = 'CYBER.sol',
        ?string $feeAmount = null,
        ?string $feeUsd = null,
        bool $gasDropPlanned = false,
        ?string $gasDropAmount = null,
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
            'amount' => $amount,
            'fee_amount' => $feeAmount,
            'fee_usd' => $feeUsd,
            'gas_drop_planned' => $gasDropPlanned,
            'gas_drop_amount' => $gasDropAmount,
            'status' => 'pending',
        ]);
    }

    /**
     * Verify that a Solana transaction is a real SPL transfer to our hot wallet.
     * Returns the transfer amount in raw units, or null if invalid.
     */
    public function verifySolanaDeposit(string $txHash, string $expectedSender): ?string
    {
        try {
            $response = Http::timeout(30)->post(self::SOLANA_RPC, [
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
        $expectedRecipient ??= self::SOLANA_HOT_WALLET;

        try {
            $response = Http::timeout(30)->post(self::SOLANA_RPC, [
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
     * Verify an ERC20 deposit on Cyberia EVM. Returns the raw transfer amount
     * (wei in token's decimals) or null if the tx does not contain a matching
     * Transfer(sender → expectedRecipient) event for the given token.
     */
    public function verifyEvmDeposit(
        string $txHash,
        string $expectedSender,
        string $tokenAddress,
        string $expectedRecipient,
    ): ?string {
        $rpc = config('services.bridge.evm_rpc_url')
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

                $data = $log['data'] ?? '0x0';
                $hex = strtolower(ltrim(substr($data, 2), '0')) ?: '0';
                $dec = '0';

                foreach (str_split($hex) as $ch) {
                    $dec = bcadd(bcmul($dec, '16'), (string) hexdec($ch));
                }

                return $dec;
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
     * Direct-model relay: user has already transferred the source token directly
     * to the hot wallet (Solana side) or relayer EOA (EVM side). We verify the
     * deposit on the source chain, then send the (amount - fee) on the destination
     * chain. For sol_to_evm we can also drop native CYBER if the request was
     * flagged for gas drop.
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

        $request->markProcessing();

        try {
            $netAmount = bcsub((string) $request->amount, (string) ($request->fee_amount ?: '0'), 18);

            if (bccomp($netAmount, '0', 18) <= 0) {
                $request->markFailed('Net amount after fee is zero or negative');

                return false;
            }

            return match ($request->direction) {
                'sol_to_evm' => $this->directSolToEvm($request, $tokenConfig, $netAmount),
                'evm_to_sol' => $this->directEvmToSol($request, $tokenConfig, $netAmount),
                default => tap(false, fn () => $request->markFailed("Unknown direction: {$request->direction}")),
            };
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
     * @param  array<string, mixed>  $tokenConfig
     */
    private function directSolToEvm(BridgeRequest $request, array $tokenConfig, string $netAmount): bool
    {
        $verified = $this->verifySolanaTokenDeposit(
            $request->source_tx_hash,
            $request->sender_address,
            (string) $tokenConfig['solana_mint'],
        );

        if ($verified === null) {
            $request->markFailed('Could not verify Solana deposit transaction');

            return false;
        }

        // Compare claimed vs verified raw amounts (with token's solana decimals).
        $claimedRaw = bcmul($request->amount, bcpow('10', (string) $tokenConfig['solana_decimals']));
        $claimedRaw = explode('.', $claimedRaw)[0];

        if (bccomp($verified, $claimedRaw, 0) < 0) {
            $request->markFailed("Deposit underfunded: verified={$verified} claimed={$claimedRaw}");

            return false;
        }

        $evmDecimals = (int) $tokenConfig['evm_decimals'];
        $amountWei = bcmul($netAmount, bcpow('10', (string) $evmDecimals));
        $amountWei = explode('.', $amountWei)[0];

        $gasDropWei = '0';

        if ($request->gas_drop_planned && $request->gas_drop_amount) {
            $gasDropWei = bcmul((string) $request->gas_drop_amount, bcpow('10', '18'));
            $gasDropWei = explode('.', $gasDropWei)[0];
        }

        $hardhatDir = Environment::isProduction()
            ? '/singularity/crypto/hardhat'
            : base_path('/../../crypto/hardhat');

        // mint model: relayer is the wrapper-token owner → mint to recipient.
        // direct model: relayer holds inventory → plain ERC20.transfer.
        $script = ($tokenConfig['model'] ?? 'direct') === 'mint'
            ? 'scripts/relay-mint.ts'
            : 'scripts/relay-erc20-transfer.ts';

        $result = Process::path($hardhatDir)
            ->env([
                'CYBERIA_RPC_URL' => config('services.bridge.evm_rpc_url')
                    ?: config('services.ethereum.rpc_url', 'https://rpc.cyberia.church'),
                'BRIDGE_RELAYER_PRIVATE_KEY' => app(BridgeRelayerService::class)->privateKey() ?? '',
            ])
            ->timeout(120)
            ->run([
                'npx', 'tsx', $script,
                (string) $tokenConfig['evm_address'],
                $request->recipient_address,
                $amountWei,
                $gasDropWei,
            ]);

        Log::info('Bridge relay direct sol_to_evm', [
            'id' => $request->id,
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'exit' => $result->exitCode(),
        ]);

        if ($result->exitCode() !== 0) {
            $request->markFailed('Relay failed: '.$result->errorOutput());

            return false;
        }

        $json = $this->lastJsonLine($result->output());

        if (! $json || empty($json['txHash'])) {
            $request->markFailed('Could not parse relay output');

            return false;
        }

        $request->markCompleted((string) $json['txHash']);

        return true;
    }

    /**
     * @param  array<string, mixed>  $tokenConfig
     */
    private function directEvmToSol(BridgeRequest $request, array $tokenConfig, string $netAmount): bool
    {
        $relayerEvm = app(BridgeRelayerService::class)->evmAddress();

        if (! is_string($relayerEvm) || $relayerEvm === '') {
            $request->markFailed('Bridge relayer private key not configured (set DEPLOYER_PK)');

            return false;
        }

        $verified = $this->verifyEvmDeposit(
            $request->source_tx_hash,
            $request->sender_address,
            (string) $tokenConfig['evm_address'],
            $relayerEvm,
        );

        if ($verified === null) {
            $request->markFailed('Could not verify EVM deposit transaction');

            return false;
        }

        $evmDecimals = (int) $tokenConfig['evm_decimals'];
        $claimedRaw = bcmul($request->amount, bcpow('10', (string) $evmDecimals));
        $claimedRaw = explode('.', $claimedRaw)[0];

        if (bccomp($verified, $claimedRaw, 0) < 0) {
            $request->markFailed("Deposit underfunded: verified={$verified} claimed={$claimedRaw}");

            return false;
        }

        // For 'mint' tokens (USDC/USDT) we destroy the wrapped EVM tokens the
        // user transferred to the relayer, so EVM supply stays backed by the
        // Solana hot-wallet reserve. Burn the full claimed amount (no fee
        // deduction here — fee is reflected in the destination amount).
        if (($tokenConfig['model'] ?? 'direct') === 'mint') {
            $burned = $this->burnEvmWrapper(
                (string) $tokenConfig['evm_address'],
                $claimedRaw,
                $request->id,
            );

            if (! $burned) {
                $request->markFailed('Failed to burn wrapped EVM tokens after user deposit');

                return false;
            }
        }

        $solanaDecimals = (int) $tokenConfig['solana_decimals'];
        $amountRaw = bcmul($netAmount, bcpow('10', (string) $solanaDecimals));
        $amountRaw = explode('.', $amountRaw)[0];

        $scriptDir = Environment::isProduction()
            ? '/singularity/crypto/anchor'
            : base_path('/../../crypto/anchor');

        $home = env('HOME', $_SERVER['HOME'] ?? '/home/lain');

        $walletPath = Environment::isProduction()
            ? '/solana/id.json'
            : $home.'/.config/solana/id.json';

        $result = Process::path($scriptDir)
            ->env([
                'ANCHOR_PROVIDER_URL' => self::SOLANA_RPC,
                'ANCHOR_WALLET' => $walletPath,
            ])
            ->timeout(120)
            ->run([
                'npx', 'ts-node', '--transpile-only', 'scripts/relay-spl-transfer.ts',
                (string) $tokenConfig['solana_mint'],
                $request->recipient_address,
                $amountRaw,
                (string) $tokenConfig['solana_token_program'],
            ]);

        Log::info('Bridge relay direct evm_to_sol', [
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
     * Burn the relayer's freshly-received wrapper-token balance so EVM supply
     * stays in sync with the destination-side reserve. Used by the mint model
     * for evm_to_sol bridges.
     */
    private function burnEvmWrapper(string $tokenAddress, string $amountWei, int $requestId): bool
    {
        $hardhatDir = Environment::isProduction()
            ? '/singularity/crypto/hardhat'
            : base_path('/../../crypto/hardhat');

        $result = Process::path($hardhatDir)
            ->env([
                'CYBERIA_RPC_URL' => config('services.bridge.evm_rpc_url')
                    ?: config('services.ethereum.rpc_url', 'https://rpc.cyberia.church'),
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
            // submit time). For CYBER.sol this is 0 — only USDC/USDT carry a
            // fee. Falls back to legacy 1% if no fee was stored (very old rows).
            $feeAmount = $request->fee_amount !== null
                ? (string) $request->fee_amount
                : self::calculateFee((string) $request->amount);

            $amountAfterFee = bcsub((string) $request->amount, $feeAmount, 18);
            $amountWei = bcmul($amountAfterFee, bcpow('10', '18'));
            $amountWei = explode('.', $amountWei)[0];

            // Optional native-CYBER gas drop for empty recipients.
            $gasDropWei = '0';
            if ($request->gas_drop_planned && $request->gas_drop_amount) {
                $gasDropWei = bcmul((string) $request->gas_drop_amount, bcpow('10', '18'));
                $gasDropWei = explode('.', $gasDropWei)[0];
            }

            Log::info('Bridge: sol_to_evm fee applied', [
                'id' => $request->id,
                'original' => $request->amount,
                'fee' => $feeAmount,
                'after_fee' => $amountAfterFee,
                'gas_drop_wei' => $gasDropWei,
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
                ])
                ->timeout(120)
                ->run([
                    'npx', 'tsx', 'scripts/relay-bridge.ts',
                    'sol_to_evm',
                    $request->recipient_address,
                    $amountWei,
                    (string) $request->id,
                    $gasDropWei,
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

            // Convert amount to Solana smallest units (6 decimals on mainnet).
            $amountRaw = bcmul($amountAfterFee, bcpow('10', '6'));
            $amountRaw = explode('.', $amountRaw)[0];

            $scriptDir = Environment::isProduction()
                ? '/singularity/crypto/anchor'
                : base_path('/../../crypto/anchor');

            $home = env('HOME', $_SERVER['HOME'] ?? '/home/lain');

            $walletPath = Environment::isProduction()
                ? '/solana/id.json'
                : $home.'/.config/solana/id.json';

            $result = Process::path($scriptDir)
                ->env([
                    'ANCHOR_PROVIDER_URL' => self::SOLANA_RPC,
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
