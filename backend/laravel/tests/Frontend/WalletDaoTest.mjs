import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

function mountDao(
    login = async (_address, sign) => {
        await sign('login challenge');
    },
) {
    const path = new URL(
        '../../resources/js/components/wallet/WalletDao.vue',
        import.meta.url,
    );
    const { descriptor } = parse(readFileSync(path, 'utf8'));
    const script = compileScript(descriptor, { id: 'wallet-dao-test' });
    const code = ts.transpileModule(script.content, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    const calls = [];
    const form = vue.reactive({
        name: 'Test DAO',
        address: '0x1234',
        processing: false,
        reset() {
            this.name = '';
            this.address = '';
        },
        clearErrors() {},
        post(url, options) {
            calls.push({
                url,
                name: this.name,
                address: this.address,
                options,
            });
        },
    });
    const exports = {};
    runInNewContext(code, {
        exports,
        require(name) {
            if (name === 'vue') return vue;
            if (name === '@inertiajs/vue3') return { useForm: () => form };
            if (name === 'lucide-vue-next') return { ExternalLink: {} };
            if (name.includes('useLocale'))
                return {
                    useLocale: () => ({
                        t: (key) => key,
                        locale: vue.ref('en'),
                    }),
                };
            if (name.includes('/session')) return { signInWithWallet: login };
            if (name.includes('/social'))
                return { fetchDao: async () => ({ daos: [], proposals: [] }) };
            if (name.includes('/format')) return {};
            if (name.includes('walletMessages')) return { walletMessages: {} };
            if (name === '@/routes/dao')
                return { store: { url: () => '/dao' } };
            throw new Error(`Unexpected import: ${name}`);
        },
    });
    const signatures = [];
    const wallet = {
        accounts: vue.ref([{ chain: 'cyberia', address: '0xwallet' }]),
        activeAccountId: vue.ref('primary'),
        activeAccount: vue.ref({ kind: 'seed' }),
        signMessage: async (chain, message) => {
            signatures.push({ chain, message });
            return 'signature';
        },
    };
    const renderer = vue.createRenderer({
        createComment: () => ({}),
        insert() {},
        remove() {},
        parentNode: () => null,
        nextSibling: () => null,
    });
    const app = renderer.createApp(
        { ...exports.default, render: () => null },
        { wallet },
    );
    const instance = app.mount({});
    return { state: instance.$.setupState, wallet, calls, signatures, app };
}

test('DAO registration signs the site login challenge before posting the form', async () => {
    const panel = mountDao();
    try {
        await panel.state.createDao();
        assert.deepEqual(panel.signatures, [
            { chain: 'cyberia', message: 'login challenge' },
        ]);
        assert.equal(panel.calls.length, 1);
        assert.equal(panel.calls[0].url, '/dao');
        assert.equal(panel.calls[0].name, 'Test DAO');
        assert.equal(panel.calls[0].address, '0x1234');
    } finally {
        panel.app.unmount();
    }
});

test('watch-only accounts cannot create a DAO', async () => {
    const panel = mountDao();
    try {
        panel.wallet.activeAccount.value = { kind: 'watch' };
        await panel.state.createDao();
        assert.equal(panel.signatures.length, 0);
        assert.equal(panel.calls.length, 0);
    } finally {
        panel.app.unmount();
    }
});

test('a failed login never registers a DAO', async () => {
    const panel = mountDao(async () => {
        throw new Error('Login failed');
    });
    try {
        await panel.state.createDao();
        assert.equal(panel.calls.length, 0);
        assert.match(panel.state.createError, /Login failed/);
        assert.equal(panel.state.signing, false);
    } finally {
        panel.app.unmount();
    }
});

test('switching account during login cannot register the previous account draft', async () => {
    let resume;
    const panel = mountDao(
        () =>
            new Promise((resolve) => {
                resume = resolve;
            }),
    );
    try {
        const pending = panel.state.createDao();
        panel.wallet.activeAccountId.value = 'another';
        resume();
        await pending;
        assert.equal(panel.calls.length, 0);
        assert.equal(panel.state.form.name, '');
    } finally {
        panel.app.unmount();
    }
});
