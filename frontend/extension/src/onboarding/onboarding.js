/**
 * Setting up the vault — the only screen that ever shows a phrase.
 *
 * The order is the one the wallet on the site uses, and it is not decoration:
 * the risk notice comes before the phrase exists, the phrase is blurred until
 * it is asked for, and the backup is checked before a password is chosen. A
 * wallet that hands over twelve words and a Continue button is a wallet whose
 * users find out they never wrote them down on the day the disk dies.
 *
 * Nothing here is sent anywhere. The phrase is generated in the service worker
 * on this machine, shown once, and sealed with the password on the last step.
 */
import { POPUP } from '../shared/protocol.js';

const app = document.getElementById('app');
const ask = (type, payload = {}) => chrome.runtime.sendMessage({ type, payload });

const esc = (value) =>
    String(value ?? '').replace(
        /[&<>"']/g,
        (character) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character],
    );

const STEPS = ['welcome', 'risk', 'phrase', 'confirm', 'password', 'done'];

let step = 'welcome';
let mode = 'create';
let phrase = '';
let revealed = false;
let acknowledged = false;
let targets = [];
let slots = [];
let chips = [];
let typed = { phrase: '', password: '', repeat: '' };
let notice = '';
/** Whether this browser has let the wallet reach the chains at all. */
let network = { granted: true, origins: [] };

const words = () => phrase.split(' ').filter(Boolean);

/** Three positions to check, spread across the phrase rather than clustered. */
const pickTargets = () => {
    const total = words().length;
    const picked = new Set();

    while (picked.size < 3) {
        picked.add(Math.floor(Math.random() * total));
    }

    targets = [...picked].sort((a, b) => a - b);
    slots = [null, null, null];
    chips = shuffle([
        ...targets.map((at) => words()[at]),
        ...shuffle(words().filter((_, at) => !targets.includes(at))).slice(0, 6),
    ]);
};

const shuffle = (list) => {
    const copy = [...list];

    for (let at = copy.length - 1; at > 0; at -= 1) {
        const swap = Math.floor(Math.random() * (at + 1));
        [copy[at], copy[swap]] = [copy[swap], copy[at]];
    }

    return copy;
};

const strength = (password) =>
    [/.{12,}/, /[a-z]/, /[A-Z0-9]/, /[^\w\s]/].filter((rule) => rule.test(password)).length;

const progress = () =>
    `<div class="cw-steps">${STEPS.map(
        (name) =>
            `<span class="cw-step${STEPS.indexOf(name) <= STEPS.indexOf(step) ? ' is-done' : ''}"></span>`,
    ).join('')}</div>`;

const noticeLine = () => (notice ? `<div class="cw-error">${esc(notice)}</div>` : '');

/* ------------------------------------------------------------------ views --- */

const welcome = () => `
    <div class="cw-body">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:44px">
            <span class="cw-mark"></span>
            <span class="cw-label" style="letter-spacing:.26em;color:var(--cw-text)">CYBERIA WALLET</span>
        </div>
        <h1 class="cw-lede">one key.<br />every EVM chain.<br />your custody.</h1>
        <p class="cw-copy">
            One seed phrase derives every account this extension signs with. The keys are
            generated on this device, encrypted with your password, and never leave the browser.
        </p>
        <button class="cw-btn is-primary" data-do="create">CREATE WALLET</button>
        <button class="cw-btn" style="margin-top:10px" data-do="import">IMPORT PHRASE</button>
        <p class="cw-sub" style="margin:16px 0 0;text-align:center">NO ACCOUNT · NO EMAIL · NO RECOVERY SERVICE</p>
    </div>`;

const risk = () => `
    <div class="cw-body">
        <div class="cw-label is-warn">BEFORE THE PHRASE EXISTS</div>
        <h1 class="cw-title" style="font-size:20px;margin:14px 0 18px">Nobody can give it back to you</h1>
        <div class="cw-rows">
            <div class="cw-ask"><span class="cw-ask-no">✕</span><span class="cw-ask-text">There is no password reset, and no support line that can restore this wallet</span></div>
            <div class="cw-ask"><span class="cw-ask-no">✕</span><span class="cw-ask-text">Anyone who reads your phrase owns every account it derives, on every chain</span></div>
            <div class="cw-ask"><span class="cw-ask-yes">✓</span><span class="cw-ask-text">Written on paper, kept off this machine, it survives the machine</span></div>
        </div>
        <button class="cw-pick" style="margin-top:18px" data-do="ack">
            <span class="cw-box${acknowledged ? ' is-on' : ''}"></span>
            <span class="cw-pick-name">I understand that losing the phrase loses the funds</span>
        </button>
        <button class="cw-btn is-primary" style="margin-top:14px" data-do="to-phrase" ${acknowledged ? '' : 'disabled'}>SHOW MY PHRASE</button>
        <button class="cw-btn" style="margin-top:8px" data-do="back-welcome">BACK</button>
    </div>`;

const phraseView = () => `
    <div class="cw-body">
        <div class="cw-label">YOUR RECOVERY PHRASE · ${esc(words().length)} WORDS</div>
        <div class="cw-seed${revealed ? '' : ' cw-blur'}">
            ${words()
                .map(
                    (word, at) =>
                        `<span class="cw-word"><span class="cw-word-n">${String(at + 1).padStart(2, '0')}</span>${esc(word)}</span>`,
                )
                .join('')}
        </div>
        ${
            revealed
                ? '<div class="cw-note">Write it down now. This screen is the only time the extension shows it without your password.</div>'
                : '<button class="cw-btn" style="margin-top:12px" data-do="reveal">REVEAL PHRASE</button>'
        }
        <button class="cw-btn is-primary" style="margin-top:10px" data-do="to-confirm" ${revealed ? '' : 'disabled'}>I WROTE IT DOWN</button>
    </div>`;

const confirm = () => {
    const filled = slots.every(Boolean);

    return `
        <div class="cw-body">
            <div class="cw-label">CHECK THE BACKUP</div>
            <p class="cw-copy" style="margin:14px 0 12px">
                Three words from the phrase you just wrote down.
            </p>
            <div class="cw-rows">
                ${targets
                    .map(
                        (at, slot) => `
                    <button class="cw-row is-tight" data-clear="${slot}">
                        <span class="cw-key">WORD ${String(at + 1).padStart(2, '0')}</span>
                        <span class="cw-val" style="color:${
                            slots[slot] ? (slots[slot] === words()[at] ? 'var(--cw-text)' : 'var(--cw-bad)') : 'var(--cw-dim)'
                        }">${esc(slots[slot] ?? 'pick a word')}</span>
                    </button>`,
                    )
                    .join('')}
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:14px">
                ${chips
                    .map(
                        (word) => `
                    <button class="cw-mode${slots.includes(word) ? '' : ''}" style="flex:0 0 auto;padding:8px 10px${slots.includes(word) ? ';opacity:.35' : ''}" data-chip="${esc(word)}">
                        <span class="cw-mode-label" style="letter-spacing:.04em">${esc(word)}</span>
                    </button>`,
                    )
                    .join('')}
            </div>
            <button class="cw-btn is-primary" style="margin-top:16px" data-do="to-password" ${filled ? '' : 'disabled'}>CONTINUE</button>
            ${noticeLine()}
        </div>`;
};

const importView = () => `
    <div class="cw-body">
        <div class="cw-label">IMPORT A PHRASE</div>
        <p class="cw-copy" style="margin:14px 0 12px">
            The same 12 or 24 words you use in the wallet on cyberia.church. The accounts
            derive identically, so both surfaces show the same addresses.
        </p>
        <textarea class="cw-input is-area" data-field="phrase" placeholder="word 01   word 02   word 03 …">${esc(typed.phrase)}</textarea>
        <button class="cw-btn is-primary" style="margin-top:12px" data-do="to-password">CONTINUE</button>
        <button class="cw-btn" style="margin-top:8px" data-do="back-welcome">BACK</button>
        ${noticeLine()}
    </div>`;

const password = () => {
    const score = strength(typed.password);
    const mismatch = typed.repeat !== '' && typed.repeat !== typed.password;

    return `
        <div class="cw-body">
            <div class="cw-label">VAULT PASSWORD</div>
            <p class="cw-copy" style="margin:14px 0 12px">
                This password encrypts the phrase on this device. It is not a login — there is
                no account to log into, and nothing to reset it against.
            </p>
            <div class="cw-field">
                <label class="cw-field-label">PASSWORD</label>
                <input class="cw-input" type="password" data-field="password" value="${esc(typed.password)}" />
                <div class="cw-strength">
                    ${[0, 1, 2, 3]
                        .map(
                            (at) =>
                                `<span class="cw-bar${at < score ? (score >= 3 ? ' is-strong' : ' is-weak') : ''}"></span>`,
                        )
                        .join('')}
                </div>
            </div>
            <div class="cw-field">
                <label class="cw-field-label">REPEAT</label>
                <input class="cw-input${mismatch ? ' is-bad' : ''}" type="password" data-field="repeat" value="${esc(typed.repeat)}" />
            </div>
            <button class="cw-btn is-primary" style="margin-top:16px" data-do="finish" ${
                typed.password.length >= 8 && typed.password === typed.repeat ? '' : 'disabled'
            }>SEAL THE VAULT</button>
            ${noticeLine()}
        </div>`;
};

const done = () => `
    <div class="cw-body cw-center">
        <div class="cw-mark" style="margin-bottom:18px"></div>
        <h1 class="cw-title">The wallet is ready</h1>
        <div class="cw-sub">PIN IT TO THE TOOLBAR — THAT ICON IS THE WHOLE WALLET</div>
        <div class="cw-note" style="text-align:left">
            No site can see an account until you grant it one, and the provider is not injected
            anywhere until you do. Open a dapp and press Connect.
        </div>
        ${
            network.granted
                ? ''
                : `<div class="cw-note is-warn" style="text-align:left">
                        Firefox asks separately before an extension may reach a network. Until you
                        allow it, the wallet cannot read a single balance.
                        <button class="cw-btn is-primary" style="margin-top:10px" data-do="grant">ALLOW RPC ACCESS</button>
                   </div>`
        }
        <button class="cw-btn" style="margin-top:12px" data-do="close">CLOSE THIS TAB</button>
    </div>`;

const render = () => {
    const views = { welcome, risk, phrase: phraseView, confirm, import: importView, password, done };
    app.innerHTML = `${progress()}${(views[step] ?? welcome)()}`;
};

/* ------------------------------------------------------------ interaction --- */

app.addEventListener('input', (event) => {
    const field = event.target.dataset?.field;

    if (field) {
        typed = { ...typed, [field]: event.target.value };

        // Re-render only where a keystroke changes the screen, so typing a
        // phrase does not fight the cursor.
        if (field !== 'phrase') {
            render();
            app.querySelector(`[data-field="${field}"]`)?.focus();
        }
    }
});

app.addEventListener('click', async (event) => {
    const target = event.target.closest('[data-do],[data-chip],[data-clear]');

    if (!target) {
        return;
    }

    const data = target.dataset;
    notice = '';

    if (data.chip) {
        const free = slots.indexOf(null);

        if (free >= 0 && !slots.includes(data.chip)) {
            slots[free] = data.chip;
        }

        render();
        return;
    }

    if (data.clear !== undefined) {
        slots[Number(data.clear)] = null;
        render();
        return;
    }

    switch (data.do) {
        case 'create': {
            mode = 'create';
            step = 'risk';
            render();
            break;
        }

        case 'import':
            mode = 'import';
            step = 'import';
            render();
            break;

        case 'ack':
            acknowledged = !acknowledged;
            render();
            break;

        case 'back-welcome':
            step = 'welcome';
            render();
            break;

        case 'to-phrase': {
            const result = await ask(POPUP.newPhrase);
            phrase = result.phrase;
            revealed = false;
            step = 'phrase';
            render();
            break;
        }

        case 'reveal':
            revealed = true;
            render();
            break;

        case 'to-confirm':
            pickTargets();
            step = 'confirm';
            render();
            break;

        case 'to-password': {
            if (mode === 'import') {
                phrase = typed.phrase.trim().toLowerCase().split(/\s+/).filter(Boolean).join(' ');

                if (![12, 15, 18, 21, 24].includes(phrase.split(' ').length)) {
                    notice = 'A BIP-39 phrase is 12 or 24 words';
                    render();
                    break;
                }
            } else if (!targets.every((at, slot) => slots[slot] === words()[at])) {
                notice = 'Those are not the words at those positions';
                render();
                break;
            }

            step = 'password';
            render();
            break;
        }

        case 'finish': {
            const result = await ask(POPUP.create, {
                phrase,
                password: typed.password,
                name: 'Main account',
            });

            if (!result?.ok) {
                notice = result?.error ?? 'The vault could not be created';
                step = mode === 'import' ? 'import' : 'password';
                render();
                break;
            }

            // Nothing keeps a plaintext phrase alive past this point.
            phrase = '';
            typed = { phrase: '', password: '', repeat: '' };

            const state = await ask(POPUP.state);
            network = { granted: state.networkGranted, origins: state.networkOrigins };

            step = 'done';
            render();
            break;
        }

        case 'grant': {
            // Called straight from this click: Gecko refuses `permissions.request`
            // that arrives after an await chain, and the vault creation above is
            // exactly such a chain.
            network = {
                ...network,
                granted: await chrome.permissions.request({ origins: network.origins }),
            };
            render();
            break;
        }

        case 'close':
            window.close();
            break;

        default:
            break;
    }
});

render();
