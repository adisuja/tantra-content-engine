<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="index, follow">
        <meta name="theme-color" content="#f7f8fb">

        <title>{{ $seo['title'] }}</title>
        <meta name="description" content="{{ $seo['description'] }}">
        <link rel="canonical" href="{{ $seo['url'] }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="Tantra">
        <meta property="og:title" content="{{ $seo['title'] }}">
        <meta property="og:description" content="{{ $seo['description'] }}">
        <meta property="og:url" content="{{ $seo['url'] }}">
        <meta property="og:image" content="{{ $seo['image'] }}">
        <meta name="twitter:card" content="summary">
        <meta name="twitter:title" content="{{ $seo['title'] }}">
        <meta name="twitter:description" content="{{ $seo['description'] }}">

        <link rel="alternate" type="application/atom+xml" title="Tantra roadmap" href="{{ url('/roadmap/feed') }}">
        <link rel="alternate" type="application/json" title="Tantra roadmap JSON" href="{{ url('/roadmap.json') }}">
        <link rel="help" type="text/plain" title="Roadmap for AI agents" href="{{ url('/roadmap/llms.txt') }}">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&display=swap" rel="stylesheet">

        <style>
            html { background-color: #f7f8fb; color: #0b0d12; color-scheme: light; }
            body { margin: 0; font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, sans-serif; }
            .rm-noscript { max-width: 960px; margin: 0 auto; padding: 32px 20px; line-height: 1.5; }
        </style>

        <script type="application/ld+json">{!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $seo['title'],
            'description' => $seo['description'],
            'url' => $seo['url'],
            'publisher' => ['@type' => 'Organization', 'name' => 'Tantra', 'url' => 'https://usetantra.com'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia

        {{-- The board in plain HTML for crawlers and readers without JavaScript. --}}
        <noscript>
            <main class="rm-noscript">
                <h1>Tantra product roadmap</h1>
                <p>{{ $seo['description'] }}</p>
                @foreach ($columns as $column)
                    <section>
                        <h2>{{ $column['label'] }}</h2>
                        <ul>
                            @foreach ($column['items'] as $item)
                                <li id="item-{{ $item['id'] }}"><strong>{{ $item['title'] }}</strong>@if ($item['summary']). {{ $item['summary'] }}@endif</li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </main>
        </noscript>
    </body>
</html>
