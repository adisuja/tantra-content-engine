{{-- tantra: the public preview of one post's asset (PreviewLinkController). --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · Tantra</title>
    <style>
        :root { --ink: #0b0d12; --muted: #5b6172; --line: #e4e6ee; --paper: #f7f8fb; --accent: #6d4aff; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--paper); color: var(--ink); font: 16px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 860px; margin: 0 auto; padding: 32px 16px 64px; }
        .brand { font-weight: 800; letter-spacing: .14em; font-size: 13px; color: var(--accent); text-transform: uppercase; }
        h1 { font-size: clamp(24px, 4vw, 34px); line-height: 1.15; margin: 8px 0 8px; }
        p.what { color: var(--muted); margin: 0 0 20px; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 24px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 18px; border-radius: 999px; font-weight: 650; text-decoration: none; border: 1px solid var(--line); color: var(--ink); background: #fff; }
        .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .pages { display: flex; flex-direction: column; gap: 16px; }
        .page { background: #fff; border: 1px solid var(--line); border-radius: 14px; overflow: hidden; box-shadow: 0 8px 24px rgba(11, 13, 18, .06); }
        .page img { display: block; width: 100%; height: auto; }
        .count { color: var(--muted); font-size: 13px; margin: 0 0 10px; }
        .empty { padding: 40px; text-align: center; color: var(--muted); border: 1px dashed var(--line); border-radius: 14px; background: #fff; }
    </style>
</head>
<body>
<main>
    <div class="brand">Tantra</div>
    <h1>{{ $title }}</h1>
    @if (filled($what))
        <p class="what">{{ $what }}</p>
    @endif

    @if (filled($pageUrl) || $files->isNotEmpty())
        <div class="actions">
            @if (filled($pageUrl))
                <a class="btn primary" href="{{ $pageUrl }}" target="_blank" rel="noopener">Open the full page</a>
            @endif
            @foreach ($files as $file)
                <a class="btn" href="{{ $file['href'] }}" target="_blank" rel="noopener">{{ $file['label'] === 'PDF' ? 'Download the PDF' : $file['label'] }}</a>
            @endforeach
        </div>
    @endif

    @if ($pages->isNotEmpty())
        <p class="count">{{ $pages->count() === 1 ? '1 page' : $pages->count().' pages' }}</p>
        <div class="pages">
            @foreach ($pages as $i => $src)
                <div class="page"><img src="{{ $src }}" alt="Page {{ $i + 1 }} of {{ $title }}" loading="{{ $i < 2 ? 'eager' : 'lazy' }}"></div>
            @endforeach
        </div>
    @elseif (blank($pageUrl) && $files->isEmpty())
        <div class="empty">Nothing to preview for this post yet.</div>
    @endif
</main>
</body>
</html>
