<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $copy['title'] }}</title>
<meta name="description" content="{{ $copy['description'] }}">
<meta property="og:title" content="{{ $copy['title'] }}">
<meta property="og:description" content="{{ $copy['description'] }}">
<meta name="theme-color" content="#050607">
<!--
    this page has been under construction since 1996.
    view-source is a door. the word is: geocities
-->
<style>
:root {
    --void: #050607;
    --panel: #0b0d10;
    --ink: #e8e4dc;
    --dim: #9aa3ad;
    --aqua: #72dccf;
    --red: #d7263d;
    --violet: #8b7bf7;
    --amber: #e8b44a;
    --line: #1f242b;
    --mono: ui-monospace, "JetBrains Mono", Menlo, Consolas, monospace;
    --serif: Georgia, "Times New Roman", "Noto Serif", serif;
}
* { box-sizing: border-box; }
html, body { margin: 0; background: var(--void); color: var(--ink); }
body {
    font: 15px/1.6 var(--mono);
    background-image:
        radial-gradient(rgba(114, 220, 207, 0.08) 1px, transparent 1.2px),
        radial-gradient(rgba(215, 38, 61, 0.05) 1px, transparent 1.2px);
    background-size: 22px 22px, 37px 37px;
    background-position: 0 0, 11px 13px;
}
a { color: var(--aqua); }
a:hover { color: var(--ink); background: var(--red); }
.ticker {
    overflow: hidden;
    white-space: nowrap;
    border-bottom: 1px solid var(--line);
    background: #000;
    color: var(--amber);
    font-size: 13px;
    padding: 6px 0;
}
.ticker span { display: inline-block; padding-left: 100%; animation: tick 48s linear infinite; }
@keyframes tick { to { transform: translateX(-100%); } }
.wrap { max-width: 1080px; margin: 0 auto; padding: 28px 16px 64px; }
.top { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; flex-wrap: wrap; }
.langs a { margin-left: 10px; text-decoration: none; }
.langs a[aria-current] { color: var(--ink); border-bottom: 1px solid var(--red); }
.logo {
    margin: 22px 0 6px;
    font: 400 clamp(7px, 2.9vw, 22px)/1.05 var(--mono);
    color: var(--aqua);
    text-shadow: 2px 0 rgba(215, 38, 61, 0.55), -2px 0 rgba(139, 123, 247, 0.4);
    white-space: pre;
    overflow: hidden;
}
.tagline { color: var(--dim); font-size: 13px; letter-spacing: 0.06em; }
.lead {
    margin: 34px 0 10px;
    font: 400 clamp(28px, 5vw, 52px)/1.12 var(--serif);
    max-width: 22ch;
}
.osc { display: inline-grid; color: var(--red); font-style: italic; }
.osc span { grid-area: 1 / 1; opacity: 0; animation: osc 9s infinite; }
.osc span:nth-child(2) { animation-delay: 3s; }
.osc span:nth-child(3) { animation-delay: 6s; }
@keyframes osc { 0%, 30% { opacity: 1; } 33.4%, 100% { opacity: 0; } }
.intro { max-width: 62ch; color: var(--ink); }
.creed { margin: 30px 0 8px; padding: 20px 22px; border: 1px solid var(--line); background: rgba(11, 13, 16, 0.85); max-width: 760px; }
.creed h2 { margin: 0 0 10px; font: 600 13px/1.4 var(--mono); letter-spacing: 0.14em; text-transform: uppercase; color: var(--amber); }
.creed ol { margin: 0; padding-left: 22px; font: 400 19px/1.5 var(--serif); }
.creed li { margin: 6px 0; }
.creed .sig { margin: 12px 0 0; color: var(--dim); font-size: 13px; }
h2.section {
    margin: 52px 0 6px;
    font: 600 14px/1.3 var(--mono);
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--aqua);
}
h2.section::before { content: "▓▒░ "; color: var(--red); }
.sub { margin: 0 0 18px; color: var(--dim); max-width: 64ch; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; }
.card { position: relative; border: 1px solid var(--line); background: var(--panel); transition: transform 0.12s, box-shadow 0.12s, border-color 0.12s; }
.card:hover { border-color: var(--aqua); transform: translate(-2px, -2px); box-shadow: 4px 4px 0 var(--red); }
.card > a.home { display: block; color: var(--ink); text-decoration: none; }
.card > a.home:hover { background: none; }
.cover { display: block; width: 100%; height: auto; aspect-ratio: 4 / 3; object-fit: cover; background: #000; }
.meta { padding: 10px 12px 12px; }
.sym { color: var(--aqua); letter-spacing: 0.12em; font-weight: 700; }
.name { font-family: var(--serif); font-size: 18px; line-height: 1.25; margin-top: 2px; }
.host { color: var(--dim); font-size: 12px; word-break: break-all; }
.desc { color: var(--dim); font-size: 13px; margin-top: 6px; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.row { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: 10px; font-size: 12px; }
.row img { height: 22px; width: auto; image-rendering: pixelated; }
.links { display: flex; gap: 12px; padding: 0 12px 12px; font-size: 13px; }
.lot .cover { filter: grayscale(0.65) brightness(0.62); }
.grid.lots { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
.lots .name { font-size: 15px; }
.vacant { position: absolute; z-index: 2; top: 10px; left: 10px; padding: 2px 8px; background: var(--red); color: #fff; font-size: 11px; letter-spacing: 0.14em; text-transform: uppercase; }
.empty { padding: 22px; border: 1px dashed var(--line); color: var(--dim); }
.ring { display: flex; flex-wrap: wrap; gap: 10px; }
.ring a { border: 1px solid var(--aqua); padding: 10px 16px; text-decoration: none; }
.ring a.rnd { border-color: var(--red); color: var(--ink); }
.uc { height: 14px; margin: 54px 0 18px; background: repeating-linear-gradient(45deg, #000 0 10px, var(--amber) 10px 20px); }
ol.steps { padding-left: 22px; max-width: 72ch; }
ol.steps li { margin: 8px 0; }
pre.snippet { white-space: pre-wrap; word-break: break-all; margin: 10px 0 0; padding: 12px 14px; border: 1px dashed var(--line); background: #000; color: var(--ink); font-size: 12px; }
.label { color: var(--dim); font-size: 12px; }
footer { margin-top: 40px; color: var(--dim); font-size: 12px; text-align: center; }
footer .close { color: var(--ink); font-family: var(--serif); font-size: 18px; margin-bottom: 8px; }
.blink { animation: blink 1.2s steps(1) infinite; color: var(--red); }
@keyframes blink { 50% { visibility: hidden; } }
@media (prefers-reduced-motion: reduce) {
    .ticker span, .osc span, .blink { animation: none; }
    .osc span:first-child { opacity: 1; }
}
</style>
</head>
<body>
@php
    $homes = $directory['homes'];
    $lots = $directory['lots'];
    $tickerText = collect(array_merge($homes, $lots))
        ->map(fn ($entry) => '$'.strtoupper((string) $entry['symbol']))
        ->filter(fn ($symbol) => $symbol !== '$')
        ->implode(' ✶ ');
    $ago = fn (?string $at) => $at ? \Illuminate\Support\Carbon::parse($at)->locale($locale)->diffForHumans() : '—';
@endphp

<div class="ticker" aria-hidden="true"><span>{{ $copy['close'] }} ✶ {{ $tickerText }} ✶ {{ $copy['lead'] }} ✶ {{ $copy['close'] }}</span></div>

<div class="wrap">
    <div class="top">
        <span class="tagline">{{ str_replace([':homes', ':lots'], [count($homes), count($lots)], $copy['tagline']) }}</span>
        <nav class="langs">
            @foreach (\App\Support\UndergroundCopy::LOCALES as $option)
                <a href="?lang={{ $option }}" @if ($option === $locale) aria-current="true" @endif>{{ strtoupper($option) }}</a>
            @endforeach
        </nav>
    </div>

<pre class="logo" aria-label="UNDERGROUND">░█░█░█▀█░█▀▄░█▀▀░█▀▄░█▀▀░█▀▄░█▀█░█░█░█▀█░█▀▄
░█░█░█░█░█░█░█▀▀░█▀▄░█░█░█▀▄░█░█░█░█░█░█░█░█
░▀▀▀░▀░▀░▀▀░░▀▀▀░▀░▀░▀▀▀░▀░▀░▀▀▀░▀▀▀░▀░▀░▀▀░</pre>

    <h1 class="lead">{{ $copy['lead'] }} <span class="osc">@foreach ($copy['oscillation'] as $phrase)<span>{{ $phrase }}</span>@endforeach</span></h1>
    <p class="intro">{{ $copy['intro'] }}</p>

    <section class="creed">
        <h2>{{ $copy['creed_title'] }}</h2>
        <ol>
            @foreach ($copy['creed'] as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ol>
        <p class="sig">{{ $copy['signature'] }}</p>
    </section>

    <h2 class="section">{{ $copy['ring'] }}</h2>
    <p class="sub">{{ $copy['ring_body'] }}</p>
    <nav class="ring">
        <a href="{{ $directory['underground_url'] }}/ring/prev">{{ $copy['prev'] }}</a>
        <a class="rnd" href="{{ $directory['underground_url'] }}/ring/random">✶ {{ $copy['random'] }} ✶</a>
        <a href="{{ $directory['underground_url'] }}/ring/next">{{ $copy['next'] }}</a>
    </nav>

    <h2 class="section">{{ $copy['homes'] }} · {{ count($homes) }}</h2>
    @if (count($homes) === 0)
        <p class="empty">{{ $copy['homes_empty'] }}</p>
    @else
        <div class="grid">
            @foreach ($homes as $home)
                <article class="card">
                    <a class="home" href="{{ $home['site_url'] }}" target="_blank" rel="noopener">
                        <img class="cover" src="{{ $home['cover_url'] }}" alt="" loading="lazy" width="400" height="300">
                        <div class="meta">
                            <div class="sym">${{ strtoupper((string) $home['symbol']) }}</div>
                            <div class="name">{{ $home['name'] }}</div>
                            <div class="host">{{ preg_replace('#^https?://|/$#', '', (string) $home['site_url']) }}</div>
                            @if ($home['description'])
                                <div class="desc">{{ $home['description'] }}</div>
                            @endif
                            <div class="row">
                                <span>{{ $copy['updated'] }} {{ $ago($home['site_updated_at']) }}</span>
                                @if ($home['counter_url'])
                                    <img src="{{ $home['counter_url'] }}" alt="{{ $home['site_hits'] }} {{ $copy['visitors'] }}" loading="lazy">
                                @endif
                            </div>
                        </div>
                    </a>
                    <div class="links">
                        <a href="{{ $home['buy_url'] }}" target="_blank" rel="noopener">{{ $copy['buy'] }} ${{ strtoupper((string) $home['symbol']) }}</a>
                        @if ($home['dao_url'])
                            <a href="{{ $home['dao_url'] }}">{{ $copy['dao'] }}</a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    <h2 class="section">{{ $copy['lots'] }} · {{ count($lots) }}</h2>
    <p class="sub">{{ $copy['lots_body'] }}</p>
    <div class="grid lots">
        @foreach ($lots as $lot)
            <article class="card lot">
                <span class="vacant">{{ $copy['vacant'] }}</span>
                <img class="cover" src="{{ $lot['cover_url'] }}" alt="" loading="lazy" width="400" height="300">
                <div class="meta">
                    <div class="sym">${{ strtoupper((string) $lot['symbol']) }}</div>
                    <div class="name">{{ $lot['name'] }}</div>
                </div>
                <div class="links">
                    <a href="{{ $lot['buy_url'] }}" target="_blank" rel="noopener">{{ $copy['buy'] }}</a>
                    @if ($lot['dao_url'])
                        <a href="{{ $lot['dao_url'] }}">{{ $copy['dao'] }}</a>
                    @endif
                </div>
            </article>
        @endforeach
    </div>

    <div class="uc" aria-hidden="true"></div>

    <h2 class="section">{{ $copy['move'] }}</h2>
    <ol class="steps">
        @foreach ($copy['steps'] as $step)
            <li>{{ $step }}</li>
        @endforeach
    </ol>
    <div class="label">{{ $copy['snippet'] }}:</div>
    <pre class="snippet">{{ $snippetFor('yourname') }}</pre>

    <footer>
        <div class="close">{{ $copy['close'] }}</div>
        <div>{{ $copy['footer'] }} <span class="blink">▮</span></div>
    </footer>
</div>
</body>
</html>
