<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>NO CARRIER</title>
<style>
* { box-sizing: border-box; }
html, body { margin: 0; height: 100%; background: #000; color: #b8ffd0; }
body {
    display: grid;
    place-items: center;
    padding: 24px 16px;
    font: 14px/1.6 ui-monospace, "JetBrains Mono", Menlo, Consolas, monospace;
    background-image: repeating-linear-gradient(180deg, rgba(255, 255, 255, 0.03) 0 1px, transparent 1px 3px);
}
main { max-width: 640px; width: 100%; }
.log { color: #5f8f6f; white-space: pre-wrap; }
.log span { opacity: 0; animation: line 0.01s forwards; }
@keyframes line { to { opacity: 1; } }
.word {
    margin: 26px 0 8px;
    font-size: clamp(7px, 2.6vw, 18px);
    line-height: 1.05;
    white-space: pre;
    overflow: hidden;
    color: #e8e4dc;
    text-shadow: 0 0 14px rgba(114, 220, 207, 0.6);
    animation: flicker 3.7s infinite;
}
@keyframes flicker { 0%, 100% { opacity: 1; } 47% { opacity: 1; } 48% { opacity: 0.2; } 49% { opacity: 1; } 71% { opacity: 0.6; } 72% { opacity: 1; } }
h1 { font: 400 22px/1.3 Georgia, "Times New Roman", serif; color: #e8e4dc; margin: 18px 0 6px; }
p { color: #9aa3ad; }
a { color: #72dccf; }
.cursor { display: inline-block; width: 9px; height: 16px; background: #b8ffd0; vertical-align: text-bottom; animation: blink 1s steps(1) infinite; }
@keyframes blink { 50% { visibility: hidden; } }
@media (prefers-reduced-motion: reduce) { .word, .cursor, .log span { animation: none; opacity: 1; } }
</style>
</head>
<body>
<main>
<div class="log" aria-hidden="true">@foreach (['ATZ', 'OK', 'ATDT 0-13-07-1996', 'RINGING', 'RINGING', 'CONNECT 2400', '> GET /layer/13', '> ...', '> ...'] as $index => $line)<span style="animation-delay: {{ 0.4 + $index * 0.45 }}s">{{ $line }}
</span>@endforeach</div>
<pre class="word" aria-label="NO CARRIER">░█▀█░█▀█░░░█▀▀░█▀█░█▀▄░█▀▄░▀█▀░█▀▀░█▀▄
░█░█░█░█░░░█░░░█▀█░█▀▄░█▀▄░░█░░█▀▀░█▀▄
░▀░▀░▀▀▀░░░▀▀▀░▀░▀░▀░▀░▀░▀░▀▀▀░▀▀▀░▀░▀</pre>
<h1>{{ $copy['lost_title'] }}</h1>
<p>{{ $copy['lost_body'] }}</p>
<p><a href="{{ $next }}">{{ $copy['lost_back'] }} →</a> <span class="cursor" aria-hidden="true"></span></p>
</main>
</body>
</html>
