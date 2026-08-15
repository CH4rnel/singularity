/**
 * The words the three worlds use to talk to each other.
 *
 * A page talks to the injected provider, the provider talks to the content
 * script over `window.postMessage`, the content script talks to the service
 * worker over a port, and the popup talks to the service worker over
 * `runtime.sendMessage`. Only the last two can be trusted with anything: the
 * first two cross into page memory, so every message that arrives from them is
 * treated as a request from a stranger — the origin is taken from the browser
 * (`sender.origin`), never from the message body.
 */

/** Port the content script opens; the service worker stays alive while it lives. */
export const PROVIDER_PORT = 'cyberia-provider';

/** Tag on every window message, so a page's own postMessage traffic is ignored. */
export const PAGE_CHANNEL = 'cyberia-wallet';

export const PAGE_TO_CONTENT = 'cyberia-wallet:to-content';
export const CONTENT_TO_PAGE = 'cyberia-wallet:to-page';

/** EIP-6963 provider identity, so a browser with several wallets can pick. */
export const PROVIDER_INFO = {
    uuid: '9a1c8e5c-4f2a-4b30-9c6a-3a5f2c4b8d10',
    name: 'Cyberia Wallet',
    rdns: 'church.cyberia.wallet',
};

/** EIP-1193 / JSON-RPC codes, spelled out where they are thrown. */
export const RPC_ERRORS = {
    userRejected: { code: 4001, message: 'Request rejected in the wallet' },
    unauthorized: { code: 4100, message: 'This site is not connected to an account' },
    unsupportedMethod: { code: 4200, message: 'Method not supported by Cyberia Wallet' },
    disconnected: { code: 4900, message: 'Wallet is locked or disconnected' },
    chainDisconnected: { code: 4901, message: 'Not connected to the requested chain' },
    unrecognizedChain: { code: 4902, message: 'This chain is not in the wallet' },
    invalidParams: { code: -32602, message: 'Invalid parameters' },
    internal: { code: -32603, message: 'Wallet could not complete the request' },
};

export const rpcError = (kind, message) => {
    const base = RPC_ERRORS[kind] ?? RPC_ERRORS.internal;

    return { code: base.code, message: message ?? base.message };
};

/**
 * Read-only calls a connected site may make straight through to the chain's
 * RPC. They ask public questions of a public chain — nothing here can move a
 * coin, and none of them says anything about the vault that the page did not
 * already know.
 */
export const PASSTHROUGH_METHODS = new Set([
    'eth_blockNumber',
    'eth_call',
    'eth_chainId',
    'eth_estimateGas',
    'eth_feeHistory',
    'eth_gasPrice',
    'eth_getBalance',
    'eth_getBlockByHash',
    'eth_getBlockByNumber',
    'eth_getCode',
    'eth_getLogs',
    'eth_getStorageAt',
    'eth_getTransactionByHash',
    'eth_getTransactionCount',
    'eth_getTransactionReceipt',
    'eth_maxPriorityFeePerGas',
    'net_version',
    'web3_clientVersion',
]);

/** Calls that reach the signer, and so always reach the human first. */
export const APPROVAL_METHODS = new Set([
    'eth_sendTransaction',
    'personal_sign',
    'eth_sign',
    'eth_signTypedData',
    'eth_signTypedData_v3',
    'eth_signTypedData_v4',
]);

/** What the popup asks the service worker for. One name per intent. */
export const POPUP = {
    state: 'popup:state',
    unlock: 'popup:unlock',
    lock: 'popup:lock',
    // Importing a phrase is creating a vault around one you already have, so
    // there is no separate verb for it.
    create: 'popup:create',
    newPhrase: 'popup:new-phrase',
    selectAccount: 'popup:select-account',
    addAccount: 'popup:add-account',
    selectChain: 'popup:select-chain',
    resolveRequest: 'popup:resolve-request',
    revokeOrigin: 'popup:revoke-origin',
    setRelay: 'popup:set-relay',
    rotateCircuit: 'popup:rotate-circuit',
    send: 'popup:send',
    quote: 'popup:quote',
    setAutoLock: 'popup:set-auto-lock',
    forget: 'popup:forget',
};
