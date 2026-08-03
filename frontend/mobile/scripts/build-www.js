'use strict';

/**
 * Generates the local `www/` payload that ships inside the native app.
 *
 * It is deliberately tiny: `index.html` hands the WebView over to the live
 * site, `error.html` is what Capacitor shows when that site cannot be reached
 * (`server.errorPath`). Both need the configured URL baked in, which is why
 * they are generated instead of committed.
 */

const fs = require('node:fs');
const path = require('node:path');
const { resolveAppUrl } = require('../src/app-url');

const appUrl = resolveAppUrl();
const wwwDir = path.join(__dirname, '..', 'www');

function escapeHtml(value) {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

const LOGO = `<svg viewBox="0 0 64 64" role="img" aria-label="Cyberia" xmlns="http://www.w3.org/2000/svg">
                <rect width="64" height="64" rx="12" fill="#0b0f10" />
                <g fill="none" stroke="#00e5d1" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M32 10 51 20.75v21.5L32 53 13 42.25v-21.5L32 10Z" />
                    <path d="M13 20.75 32 31.5l19-10.75" />
                    <path d="M32 31.5V53" />
                </g>
            </svg>`;

const STYLE = `:root {
                color-scheme: dark;
                font-family: Manrope, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                background: #0b0f10;
                color: #eef7f6;
            }

            * { box-sizing: border-box; }

            body {
                min-height: 100vh;
                margin: 0;
                display: grid;
                place-items: center;
                padding: calc(24px + env(safe-area-inset-top)) 24px calc(24px + env(safe-area-inset-bottom));
                background: radial-gradient(circle at 50% 20%, rgb(0 229 209 / 12%), transparent 35%), #0b0f10;
                -webkit-user-select: none;
                user-select: none;
            }

            main {
                width: min(100%, 430px);
                padding: 32px;
                border: 1px solid rgb(0 229 209 / 35%);
                border-radius: 18px;
                background: rgb(13 20 21 / 92%);
                text-align: center;
            }

            svg { width: 72px; height: 72px; margin-bottom: 20px; }

            h1 { margin: 0 0 12px; font-size: clamp(24px, 8vw, 32px); letter-spacing: -0.035em; }

            p { margin: 0 0 24px; color: #a7b8b6; line-height: 1.6; }

            code { display: block; margin-top: 8px; font-size: 13px; color: #7f918f; word-break: break-all; }

            button {
                display: inline-flex;
                min-height: 48px;
                align-items: center;
                justify-content: center;
                padding: 0 24px;
                border: 0;
                border-radius: 9px;
                background: #00e5d1;
                color: #07100f;
                font: inherit;
                font-weight: 800;
            }`;

function page({ title, heading, body, script }) {
    return `<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
        <meta name="theme-color" content="#0b0f10" />
        <title>${escapeHtml(title)}</title>
        <style>
            ${STYLE}
        </style>
    </head>
    <body>
        <main>
            ${LOGO}
            <h1>${heading}</h1>
            ${body}
        </main>
        <script>
            ${script}
        </script>
    </body>
</html>
`;
}

const indexHtml = page({
    title: 'Cyberia',
    heading: 'Connecting to Cyberia…',
    body: `<p>Opening <code>${escapeHtml(appUrl)}</code></p>
            <button type="button" id="open">Open now</button>`,
    script: `(function () {
                var target = ${JSON.stringify(appUrl)};
                var open = function () { window.location.replace(target); };
                document.getElementById('open').addEventListener('click', open);
                open();
            })();`,
});

const errorHtml = page({
    title: 'Cyberia is offline',
    heading: 'You are outside the Wired.',
    body: `<p>
                Cyberia cannot reach the network right now. Check your
                connection and try again.
                <code>${escapeHtml(appUrl)}</code>
            </p>
            <button type="button" id="retry">Try again</button>`,
    script: `(function () {
                var target = ${JSON.stringify(appUrl)};
                var retry = function () { window.location.replace(target); };
                document.getElementById('retry').addEventListener('click', retry);
                window.addEventListener('online', retry);
            })();`,
});

fs.mkdirSync(wwwDir, { recursive: true });
fs.writeFileSync(path.join(wwwDir, 'index.html'), indexHtml);
fs.writeFileSync(path.join(wwwDir, 'error.html'), errorHtml);

process.stdout.write(`www/ generated for ${appUrl}\n`);
