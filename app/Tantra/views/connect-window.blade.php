{{-- tantra: the connect popup's first page (PRD 2026-11-15, 4.4; resources/js/lib/tantra/embed.ts). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>Connect</title>
    <style>
        html, body { height: 100%; margin: 0; background: #ffffff; }
        body { display: flex; align-items: center; justify-content: center; }
        .spinner { width: 22px; height: 22px; border-radius: 50%; border: 2px solid #eae8e5; border-top-color: #5a5a59; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spinner { animation: none; } }
    </style>
</head>
<body>
    <div class="spinner" role="status" aria-label="Loading"></div>
    <script>
        (function () {
            var nonce = {{ Js::from($nonce) }};
            var to = {{ Js::from($to) }};

            // sessionStorage is per tab and outlives the trip through the
            // network's pages (and a Cross-Origin-Opener-Policy swap);
            // window.name is the marker only when storage is blocked.
            // window.name stays "tantra-connect" when storage works, so the
            // next connect reuses this window instead of opening another.
            try {
                window.sessionStorage.setItem('tantra.connect', nonce);
            } catch (e) {
                window.name = 'tantra-connect:' + nonce;
            }
            window.location.replace(to);
        })();
    </script>
</body>
</html>
