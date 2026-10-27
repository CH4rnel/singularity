{{--
    Microsoft Clarity, behind every rule in App\Support\SessionReplay.

    Rendered by app.blade.php and injected into the static landing page, so the
    loader, the notice and the refusal are one implementation for both. The
    Inertia side (resources/js/lib/sessionReplay.ts) only ever talks to
    window.cyberiaReplay: it pauses recording on the screens listed in
    `paused`, resumes it elsewhere, and tags each page.

    Every call goes through `call`, which waits until clarity.js is really
    running: the tag queues a "start" behind anything called before it loads,
    so a "pause" sent too early is processed first and does nothing.
--}}
@php($replay = \App\Support\SessionReplay::for(request(), $component ?? null))
@if ($replay)
<script>
(function (settings) {
    var OPTOUT = 'cyberia.analytics.optout';
    var NOTICE = 'cyberia.replay.notice';
    var read = function (key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } };
    var write = function (key, value) { try { window.localStorage.setItem(key, value); } catch (e) { /* storage refused */ } };
    var refused = function () {
        var n = window.navigator || {};
        return read(OPTOUT) === '1' || n.doNotTrack === '1' || n.doNotTrack === 'yes' || window.doNotTrack === '1' || n.globalPrivacyControl === true;
    };

    var loaded = false;
    var stopped = false;
    var paused = false;
    var pending = [];
    var ready = function () { return !!(window.clarity && window.clarity.v); };
    var drain = function () {
        var tries = 0;
        var timer = setInterval(function () {
            tries += 1;
            if (ready()) {
                clearInterval(timer);
                while (pending.length > 0) { window.clarity.apply(null, pending.shift()); }
            } else if (tries > 240) {
                // Blocked or offline: nothing will ever record, so nothing waits.
                clearInterval(timer);
                pending = [];
            }
        }, 250);
    };

    var copy = {
        en: ['We record how the site is used — clicks, scrolling and pointer movement — to find what is broken or confusing. Inputs, phrases, keys and your addresses are hidden, and the wallet is recorded without its text. Recordings are kept by Microsoft Clarity.', 'OK', 'Don’t record'],
        ru: ['Мы записываем, как используется сайт — клики, прокрутку и движения мыши, — чтобы находить, что сломано и неудобно. Поля ввода, фразы, ключи и ваши адреса скрыты, кошелёк записывается без текста. Записи хранит Microsoft Clarity.', 'Понятно', 'Не записывать'],
        zh: ['我们会记录网站的使用方式（点击、滚动和指针移动），以发现故障和不便之处。输入内容、助记词、私钥和你的地址均被隐藏，钱包录制时不含任何文字。录制由 Microsoft Clarity 保存。', '知道了', '不要记录']
    };
    var language = function () {
        var chosen = read('locale') || (window.navigator.language || 'en');
        chosen = chosen.slice(0, 2).toLowerCase();
        return copy[chosen] ? chosen : 'en';
    };

    var hideNotice = function () {
        var box = document.getElementById('cyberia-replay-notice');
        if (box) { box.parentNode.removeChild(box); }
    };
    var notice = function () {
        if (read(NOTICE) === '1') { return; }
        var show = function () {
            if (document.getElementById('cyberia-replay-notice')) { return; }
            var text = copy[language()];
            var box = document.createElement('div');
            box.id = 'cyberia-replay-notice';
            box.setAttribute('role', 'status');
            box.style.cssText = 'position:fixed;left:12px;bottom:calc(12px + env(safe-area-inset-bottom));z-index:2147483000;max-width:min(420px,calc(100vw - 24px));box-sizing:border-box;padding:12px 14px;border:1px solid #1f2b2d;border-radius:10px;background:#0b0f10;color:#d5dfdf;font:13px/1.45 Manrope,system-ui,sans-serif;box-shadow:0 8px 28px rgba(0,0,0,.45)';
            var body = document.createElement('p');
            body.style.cssText = 'margin:0 0 10px';
            body.textContent = text[0];
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;justify-content:flex-end';
            var button = function (label, primary, onClick) {
                var b = document.createElement('button');
                b.type = 'button';
                b.textContent = label;
                b.style.cssText = 'cursor:pointer;border-radius:6px;padding:6px 12px;font:inherit;font-weight:600;' + (primary ? 'border:0;background:#00e5d1;color:#041012' : 'border:1px solid #2c3a3d;background:transparent;color:#d5dfdf');
                b.addEventListener('click', onClick);
                return b;
            };
            row.appendChild(button(text[2], false, function () { replay.stop(); }));
            row.appendChild(button(text[1], true, function () { write(NOTICE, '1'); hideNotice(); }));
            box.appendChild(body);
            box.appendChild(row);
            document.body.appendChild(box);
        };
        if (document.body) { show(); } else { document.addEventListener('DOMContentLoaded', show); }
    };

    var replay = {
        paused: settings.paused,
        recording: function () { return loaded && !stopped && !paused; },
        call: function () {
            if (!loaded || stopped) { return; }
            var args = Array.prototype.slice.call(arguments);
            if (ready()) { window.clarity.apply(null, args); return; }
            pending.push(args);
        },
        start: function () {
            if (stopped || refused()) { return; }
            if (!loaded) {
                loaded = true;
                (function (c, l, a, r, i, t, y) {
                    c[a] = c[a] || function () { (c[a].q = c[a].q || []).push(arguments); };
                    t = l.createElement(r); t.async = 1; t.src = 'https://www.clarity.ms/tag/' + i;
                    y = l.getElementsByTagName(r)[0]; y.parentNode.insertBefore(t, y);
                })(window, document, 'clarity', 'script', settings.project);
                drain();
                notice();
                return;
            }
            if (paused) { paused = false; replay.call('resume'); }
        },
        pause: function () {
            if (!loaded || paused) { return; }
            paused = true;
            replay.call('pause');
        },
        // "Do not record": remembered in this browser, under the key the
        // wallet's own statistics switch writes, so one refusal covers both.
        stop: function () {
            write(OPTOUT, '1');
            write(NOTICE, '1');
            hideNotice();
            if (loaded && !stopped) {
                replay.call('consent', false);
                replay.call('stop');
            }
            stopped = true;
            try { window.dispatchEvent(new CustomEvent('cyberia:replay-refused')); } catch (e) { /* old browser */ }
        }
    };

    window.cyberiaReplay = replay;

    if (settings.autostart) { replay.start(); }
})(@json($replay));
</script>
@endif
