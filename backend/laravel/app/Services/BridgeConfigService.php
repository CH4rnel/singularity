<?php

namespace App\Services;

/**
 * Single source of truth over config/bridge.php: route/token availability
 * rules and the public-safe subsets served to the frontend as Inertia props.
 * Adding an EVM chain is a config-only change — nothing here hardcodes chains.
 */
class BridgeConfigService
{
    public function __construct(
        private readonly BridgeRelayerService $relayerService,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function chains(): array
    {
        return config('bridge.chains', []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function chain(string $key): ?array
    {
        return $this->chains()[$key] ?? null;
    }

    /**
     * Routes whose chains are operational: both chains exist and the source
     * chain has a deposit address (TON routes disappear while
     * BRIDGE_TON_DEPOSIT_ADDRESS is unset; evm_to_ton also requires it since
     * payouts come from the same TON hot wallet).
     *
     * @return array<string, array<string, mixed>>
     */
    public function availableRoutes(): array
    {
        $available = [];

        foreach (config('bridge.routes', []) as $direction => $route) {
            // A corridor toggled off (config/bridge.php 'enabled') is hidden
            // from the UI and rejected at submit/quote time.
            if (($route['enabled'] ?? true) === false) {
                continue;
            }

            $source = $this->chain($route['source_chain'] ?? '');
            $destination = $this->chain($route['destination_chain'] ?? '');

            if (! $source || ! $destination) {
                continue;
            }

            // Opt-in chains with manual deposits (TON, Yenten) are hidden
            // until their hot wallet is configured. EVM/Solana chains have
            // built-in defaults (relayer EOA / configured hot wallet), so a
            // missing relayer key there is an operational error surfaced at
            // processing time, not a reason to hide core routes.
            foreach ([$source, $destination] as $chain) {
                if (($chain['wallet'] ?? '') === 'manual'
                    && $this->depositAddress($chain['key']) === null) {
                    continue 2;
                }
            }

            // TON payouts additionally need the relayer wallet mnemonic.
            if (($destination['type'] ?? '') === 'ton'
                && ! config('services.bridge.ton_relayer_mnemonic')) {
                continue;
            }

            if (($source['type'] ?? '') === 'yenten' || ($destination['type'] ?? '') === 'yenten') {
                $yenten = $this->chain('yenten');

                if (! is_string($yenten['relayer_wif'] ?? null)
                    || $yenten['relayer_wif'] === '') {
                    continue;
                }
            }

            if ($this->tokensForRoute($direction) === []) {
                continue;
            }

            $available[$direction] = $route;
        }

        return $available;
    }

    /**
     * Address users deposit to on a chain: explicit deposit_address from
     * config, else the relayer EOA on EVM chains.
     */
    public function depositAddress(string $chainKey): ?string
    {
        $chain = $this->chain($chainKey);

        if (! $chain) {
            return null;
        }

        $explicit = $chain['deposit_address'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        if (($chain['type'] ?? '') === 'evm') {
            return $this->relayerService->evmAddress();
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null chain-specific token entry
     */
    public function tokenOnChain(string $symbol, string $chainKey): ?array
    {
        $token = config('bridge.tokens', [])[$symbol] ?? null;

        if (! is_array($token)) {
            return null;
        }

        $entry = $token['chains'][$chainKey] ?? null;

        return is_array($entry) && $this->chainEntryConfigured($entry) ? $entry : null;
    }

    /**
     * Tokens offered on a route: configured on BOTH of its chains.
     *
     * @return array<string, array<string, mixed>> keyed by symbol
     */
    public function tokensForRoute(string $direction): array
    {
        $route = config('bridge.routes', [])[$direction] ?? null;

        if (! is_array($route)) {
            return [];
        }

        $tokens = [];

        foreach (config('bridge.tokens', []) as $symbol => $token) {
            if ($this->tokenOnChain($symbol, $route['source_chain']) === null) {
                continue;
            }

            if ($this->tokenOnChain($symbol, $route['destination_chain']) === null) {
                continue;
            }

            $tokens[$symbol] = $token;
        }

        return $tokens;
    }

    /**
     * Public-safe chain list for the frontend (api keys stripped).
     *
     * @return array<int, array<string, mixed>>
     */
    public function publicChains(): array
    {
        return array_values(array_map(fn (array $chain) => [
            'key' => $chain['key'],
            'label' => $chain['label'],
            'type' => $chain['type'] ?? 'evm',
            'addressType' => $chain['address_type'],
            'wallet' => $chain['wallet'] ?? 'manual',
            'evmChainId' => $chain['evm_chain_id'] ?? null,
            'rpcUrl' => $chain['rpc_url'] ?? null,
            'explorerTx' => $chain['explorer_tx'] ?? null,
            'nativeCurrency' => $chain['native_currency'] ?? null,
            'depositAddress' => $this->depositAddress($chain['key']),
        ], $this->chains()));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publicRoutes(): array
    {
        $routes = [];

        foreach ($this->availableRoutes() as $direction => $route) {
            $source = $this->chain($route['source_chain']);
            $destination = $this->chain($route['destination_chain']);

            $routes[] = [
                'direction' => $direction,
                'source' => $route['source_chain'],
                'destination' => $route['destination_chain'],
                'sourceLabel' => $source['label'],
                'destinationLabel' => $destination['label'],
                'sourceWallet' => $source['wallet'] ?? 'manual',
                'destinationAddressType' => $destination['address_type'],
                'autoProcess' => (bool) ($route['auto_process'] ?? false),
                'tokens' => array_keys($this->tokensForRoute($direction)),
            ];
        }

        return $routes;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publicTokens(): array
    {
        $tokens = [];

        foreach (config('bridge.tokens', []) as $symbol => $token) {
            $chains = [];

            foreach ($token['chains'] ?? [] as $chainKey => $entry) {
                if (! $this->chainEntryConfigured($entry)) {
                    continue;
                }

                $chains[$chainKey] = [
                    'address' => $entry['address'] ?? null,
                    'mint' => $entry['mint'] ?? null,
                    'master' => $entry['master'] ?? null,
                    'native' => (bool) ($entry['native'] ?? false),
                    'decimals' => (int) $entry['decimals'],
                    'tokenProgram' => $entry['token_program'] ?? null,
                ];
            }

            $tokens[] = [
                'symbol' => $symbol,
                'model' => $token['model'] ?? 'direct',
                'chains' => $chains,
            ];
        }

        return $tokens;
    }

    /**
     * A token chain entry counts as configured when its identifier is present
     * (native chains need no address; wrapper addresses may be empty until the
     * contract is deployed and the env var is set).
     *
     * @param  array<string, mixed>  $entry
     */
    private function chainEntryConfigured(array $entry): bool
    {
        if (($entry['native'] ?? false) === true) {
            return true;
        }

        foreach (['address', 'mint', 'master'] as $key) {
            if (is_string($entry[$key] ?? null) && $entry[$key] !== '') {
                return true;
            }
        }

        return false;
    }
}
