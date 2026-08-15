'use strict';

/**
 * Pure-logic tests for the navigation rules. They run under plain Node
 * (`npm test`) because they must not need a display or an Electron binary.
 */

const assert = require('node:assert/strict');
const { test } = require('node:test');
const {
    DEFAULT_APP_PATH,
    DEFAULT_APP_URL,
    describeProxy,
    hasProxyFlag,
    isExternallyOpenable,
    isNavigable,
    normalizeProxySetting,
    parseProxyServer,
    resolveAppPath,
    resolveAppUrl,
    resolveProxy,
    resolveProxyDecision,
    resolveStartUrl,
} = require('../src/config');

const HOST = 'cyberia.church';
const BYPASS = 'localhost,127.0.0.1,::1,<local>';

test('falls back to the production URL', () => {
    assert.equal(resolveAppUrl({}, []), DEFAULT_APP_URL);
});

test('reads the URL from the environment and the command line', () => {
    assert.equal(resolveAppUrl({ CYBERIA_APP_URL: 'https://staging.test/' }, []), 'https://staging.test');
    assert.equal(
        resolveAppUrl({ CYBERIA_APP_URL: 'https://staging.test' }, ['--url=http://localhost:8000']),
        'http://localhost:8000',
    );
});

test('rejects a malformed or non-http override', () => {
    assert.equal(resolveAppUrl({ CYBERIA_APP_URL: 'not a url' }, []), DEFAULT_APP_URL);
    assert.equal(resolveAppUrl({ CYBERIA_APP_URL: 'file:///etc/passwd' }, []), DEFAULT_APP_URL);
});

test('the window opens on the wallet, not on the site home', () => {
    assert.equal(resolveAppPath({}, []), DEFAULT_APP_PATH);
    assert.equal(resolveStartUrl({}, []), `${DEFAULT_APP_URL}/wallet`);
});

test('reads the landing route from the environment and the command line', () => {
    assert.equal(resolveAppPath({ CYBERIA_APP_PATH: '/swap' }, []), '/swap');
    assert.equal(resolveAppPath({ CYBERIA_APP_PATH: '/swap' }, ['--path=/bridge?to=sol']), '/bridge?to=sol');
    assert.equal(
        resolveStartUrl({ CYBERIA_APP_URL: 'http://localhost:8000', CYBERIA_APP_PATH: '/wallet' }, []),
        'http://localhost:8000/wallet',
    );
});

test('a landing route may never move the app to another origin', () => {
    // `//evil.example` and a full URL would silently repoint the whole shell.
    assert.equal(resolveAppPath({ CYBERIA_APP_PATH: '//evil.example/wallet' }, []), DEFAULT_APP_PATH);
    assert.equal(resolveAppPath({ CYBERIA_APP_PATH: 'https://evil.example' }, []), DEFAULT_APP_PATH);
    assert.equal(resolveAppPath({ CYBERIA_APP_PATH: 'wallet' }, []), DEFAULT_APP_PATH);
    assert.equal(resolveAppPath({ CYBERIA_APP_PATH: '' }, []), DEFAULT_APP_PATH);
});

test('a root landing route keeps its slash, query and all', () => {
    assert.equal(resolveAppPath({}, ['--path=/']), '/');
    assert.equal(resolveAppPath({}, ['--path=/?ref=app']), '/?ref=app');
    assert.equal(resolveAppPath({}, ['--path=/wallet?net=base#send']), '/wallet?net=base#send');
});

test('a trailing slash never doubles up against the app URL', () => {
    assert.equal(resolveStartUrl({ CYBERIA_APP_URL: 'https://staging.test/' }, ['--path=/wallet/']), 'https://staging.test/wallet');
    assert.equal(resolveStartUrl({}, ['--path=/']), `${DEFAULT_APP_URL}/`);
});

test('keeps the app and its subdomains inside the window', () => {
    assert.ok(isNavigable('https://cyberia.church/swap', HOST));
    assert.ok(isNavigable('https://explorer.cyberia.church/tx/0x1', HOST));
});

test('keeps the OAuth providers inside the window', () => {
    assert.ok(isNavigable('https://x.com/i/oauth2/authorize?x=1', HOST));
});

test('sends everything else to the system browser', () => {
    assert.equal(isNavigable('https://evil.example/cyberia.church', HOST), false);
    assert.equal(isNavigable('https://cyberia.church.evil.example/', HOST), false);
    assert.equal(isNavigable('wc:topic@2?relay-protocol=irn', HOST), false);
    assert.equal(isNavigable('file:///etc/passwd', HOST), false);
    assert.equal(isNavigable('javascript:alert(1)', HOST), false);
});

test('hands wallet and messenger schemes to the operating system', () => {
    assert.ok(isExternallyOpenable('wc:topic@2?relay-protocol=irn'));
    assert.ok(isExternallyOpenable('metamask://wc?uri=wc%3Atopic'));
    assert.ok(isExternallyOpenable('tg://resolve?domain=cyberia'));
    assert.ok(isExternallyOpenable('https://explorer.cyberia.church/'));
});

test('leaves the system proxy alone when nothing is configured', () => {
    assert.equal(resolveProxy({}, []), null);
    assert.equal(describeProxy(null), 'system');
});

test('takes the proxy from the shell environment', () => {
    assert.deepEqual(
        resolveProxy(
            {
                http_proxy: 'http://127.0.0.1:10808',
                https_proxy: 'http://127.0.0.1:10808',
                no_proxy: 'localhost,127.0.0.1',
            },
            [],
        ),
        { proxyRules: 'http://127.0.0.1:10808', proxyBypassRules: BYPASS },
    );
});

test('keeps the schemes apart when they disagree', () => {
    assert.equal(
        resolveProxy({ http_proxy: '10.0.0.1:3128', HTTPS_PROXY: 'socks5://127.0.0.1:1080' }, [])
            .proxyRules,
        'http=http://10.0.0.1:3128;https=socks5://127.0.0.1:1080',
    );
    assert.equal(
        resolveProxy({ ALL_PROXY: 'socks://127.0.0.1:10808' }, []).proxyRules,
        'socks5://127.0.0.1:10808',
    );
});

test('lets the command line and CYBERIA_PROXY win over the environment', () => {
    const env = { https_proxy: 'http://127.0.0.1:10808' };

    assert.equal(
        resolveProxy({ ...env, CYBERIA_PROXY: 'http://127.0.0.1:10809' }, []).proxyRules,
        'http://127.0.0.1:10809',
    );
    assert.equal(
        resolveProxy(env, ['--proxy=socks5://127.0.0.1:1080']).proxyRules,
        'socks5://127.0.0.1:1080',
    );
});

test('overrides a broken system proxy with an explicit direct mode', () => {
    assert.deepEqual(resolveProxy({ https_proxy: 'http://127.0.0.1:10808' }, ['--no-proxy']), {
        mode: 'direct',
    });
    assert.deepEqual(resolveProxy({ CYBERIA_PROXY: 'direct' }, []), { mode: 'direct' });
    assert.equal(describeProxy({ mode: 'direct' }), 'direct');
});

test('normalises proxy servers and drops the ones Chromium cannot use', () => {
    assert.equal(parseProxyServer('127.0.0.1:10808'), 'http://127.0.0.1:10808');
    assert.equal(parseProxyServer('http://user:pass@proxy.test:8080'), 'http://proxy.test:8080');
    assert.equal(parseProxyServer('ftp://proxy.test:21'), null);
    assert.equal(parseProxyServer('   '), null);
    assert.equal(resolveProxy({ CYBERIA_PROXY: 'nonsense://x' }, []), null);
});

test('turns NO_PROXY into bypass rules', () => {
    assert.equal(
        resolveProxy({ https_proxy: '127.0.0.1:10808', no_proxy: '.cyberia.church, 10.0.0.0/8' }, [])
            .proxyBypassRules,
        `*.cyberia.church,10.0.0.0/8,${BYPASS}`,
    );
    assert.equal(
        resolveProxy({ https_proxy: '127.0.0.1:10808', NO_PROXY: '*' }, []).proxyBypassRules,
        '*',
    );
});

test('reads back only settings the shell can act on', () => {
    assert.deepEqual(normalizeProxySetting({ mode: 'manual', server: '127.0.0.1:10808' }), {
        mode: 'manual',
        server: 'http://127.0.0.1:10808',
    });
    assert.deepEqual(normalizeProxySetting({ mode: 'direct' }), { mode: 'direct', server: '' });

    // A saved entry Chromium cannot use is not kept as a broken proxy.
    const system = { mode: 'system', server: '' };

    assert.deepEqual(normalizeProxySetting({ mode: 'manual', server: 'nonsense://x' }), system);
    assert.deepEqual(normalizeProxySetting({ mode: 'manual' }), system);
    assert.deepEqual(normalizeProxySetting({ mode: 'whatever' }), system);
    assert.deepEqual(normalizeProxySetting(null), system);
    assert.deepEqual(normalizeProxySetting('socks5://127.0.0.1:1080'), system);
});

test('what the user chose in the app outranks the ambient environment', () => {
    const env = { https_proxy: 'http://127.0.0.1:10808', http_proxy: 'http://127.0.0.1:10808' };
    const saved = { mode: 'manual', server: 'socks5://127.0.0.1:1080' };

    assert.deepEqual(resolveProxyDecision(env, [], saved), {
        source: 'saved',
        proxy: { proxyRules: 'socks5://127.0.0.1:1080', proxyBypassRules: BYPASS },
    });

    // Including the choice to ignore a proxy the environment still exports.
    assert.deepEqual(resolveProxyDecision({ ...env, CYBERIA_PROXY: '127.0.0.1:3128' }, [], {
        mode: 'direct',
    }), { source: 'saved', proxy: { mode: 'direct' } });

    // "System" is not a choice to override anything: the environment takes over.
    assert.deepEqual(resolveProxyDecision(env, [], { mode: 'system' }), {
        source: 'env',
        proxy: { proxyRules: 'http://127.0.0.1:10808', proxyBypassRules: BYPASS },
    });
});

test('a launch flag still wins over the saved setting', () => {
    const saved = { mode: 'manual', server: 'socks5://127.0.0.1:1080' };

    assert.deepEqual(resolveProxyDecision({}, ['--no-proxy'], saved), {
        source: 'flag',
        proxy: { mode: 'direct' },
    });
    assert.equal(resolveProxyDecision({}, ['--proxy=127.0.0.1:3128'], saved).source, 'flag');
    assert.equal(
        resolveProxy({}, ['--proxy=127.0.0.1:3128'], saved).proxyRules,
        'http://127.0.0.1:3128',
    );

    assert.ok(hasProxyFlag(['--no-proxy']));
    assert.ok(hasProxyFlag(['--proxy=direct']));
    assert.equal(hasProxyFlag(['--url=https://staging.test']), false);
});

test('an unusable value is reported as the system proxy, never invented', () => {
    assert.deepEqual(resolveProxyDecision({ CYBERIA_PROXY: 'nonsense://x' }, []), {
        source: 'system',
        proxy: null,
    });
    assert.deepEqual(resolveProxyDecision({}, ['--proxy=ftp://proxy.test:21']), {
        source: 'system',
        proxy: null,
    });
    assert.deepEqual(resolveProxyDecision({}, []), { source: 'system', proxy: null });
});

test('never hands executable or local schemes to the operating system', () => {
    assert.equal(isExternallyOpenable('javascript:alert(1)'), false);
    assert.equal(isExternallyOpenable('data:text/html,<script>alert(1)</script>'), false);
    assert.equal(isExternallyOpenable('file:///etc/passwd'), false);
    assert.equal(isExternallyOpenable('not a url'), false);
    assert.equal(isExternallyOpenable(`https://cyberia.church/${'a'.repeat(2048)}`), false);
});
