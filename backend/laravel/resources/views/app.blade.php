@php
    $isGrowthPage = str_starts_with($page['component'] ?? '', 'Growth/');
    $seo = $page['props']['seo'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="{{ $isGrowthPage ? 'en' : str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'dark') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "dark" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: hsl(0 0% 100%);
            }

            html.dark {
                background-color: hsl(210 15% 4%);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=manrope:300,400,500,600,700,800" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        @if (is_array($seo))
            <title data-inertia="">{{ $seo['title'] }}</title>
            <meta data-inertia="description" name="description" content="{{ $seo['description'] }}">
            <link data-inertia="canonical" rel="canonical" href="{{ $seo['canonical'] }}">
            <meta data-inertia="og:title" property="og:title" content="{{ $seo['title'] }}">
            <meta data-inertia="og:description" property="og:description" content="{{ $seo['description'] }}">
            <meta data-inertia="og:url" property="og:url" content="{{ $seo['canonical'] }}">
            <meta data-inertia="og:type" property="og:type" content="website">
            <meta data-inertia="og:image" property="og:image" content="{{ $seo['image'] }}">
            <meta data-inertia="twitter:card" name="twitter:card" content="summary_large_image">
            <meta data-inertia="twitter:title" name="twitter:title" content="{{ $seo['title'] }}">
            <meta data-inertia="twitter:description" name="twitter:description" content="{{ $seo['description'] }}">
            <script data-inertia="faq-json" type="application/ld+json">{!! json_encode($seo['structuredData'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
        @else
            <x-inertia::head>
                <title>{{ config('app.name', 'Laravel') }}</title>
            </x-inertia::head>
        @endif
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
