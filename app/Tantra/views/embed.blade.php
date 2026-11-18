{{-- tantra: the boot page the Tantra CRM frames on its Content tab (PRD 2026-11-15, 4.2). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Content</title>
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
            var to = {{ Js::from($to) }};
            var parents = {{ Js::from($parents) }};
            var sessionUrl = {{ Js::from($sessionUrl) }};
            var csrf = {{ Js::from(csrf_token()) }};

            // Opened in its own tab: nothing to hand over, just go.
            if (window.parent === window) {
                window.location.replace(to);
                return;
            }

            var retried = false;
            var busy = false;

            function post(origin, message) {
                message.v = 1;
                try {
                    window.parent.postMessage(message, origin);
                } catch (e) {
                    // A parent origin that is not the real one: dropped.
                }
            }

            function ready() {
                parents.forEach(function (origin) {
                    post(origin, { type: 'tantra:ready' });
                });
            }

            window.addEventListener('message', function (event) {
                if (event.source !== window.parent || parents.indexOf(event.origin) === -1) {
                    return;
                }
                var data = event.data;
                if (!data || data.v !== 1 || data.type !== 'tantra:auth' || typeof data.token !== 'string' || busy) {
                    return;
                }
                busy = true;
                var origin = event.origin;
                try {
                    window.sessionStorage.setItem('tantra.parent', origin);
                } catch (e) {
                    // Storage blocked: embed.ts falls back to the configured parent.
                }

                fetch(sessionUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(typeof data.timezone === 'string' && data.timezone.length <= 64 ? { token: data.token, timezone: data.timezone } : { token: data.token })
                }).then(function (response) {
                    return response.json().catch(function () {
                        return {};
                    }).then(function (body) {
                        return { status: response.status, body: body || {} };
                    });
                }).then(function (result) {
                    busy = false;
                    if (result.status === 200 && result.body.ok === true) {
                        post(origin, { type: 'tantra:session', ok: true, email: result.body.email });
                        window.location.replace(to);
                        return;
                    }
                    if (result.status === 403) {
                        post(origin, { type: 'tantra:no-access' });
                        return;
                    }
                    if (result.status === 401 && !retried) {
                        // The token may have expired on the way: ask for a fresh one, once.
                        retried = true;
                        post(origin, { type: 'tantra:ready' });
                        return;
                    }
                    var code = result.status === 401 ? 'token_invalid' : (result.status === 419 ? 'csrf' : 'server');
                    post(origin, { type: 'tantra:error', code: code });
                }).catch(function () {
                    busy = false;
                    post(origin, { type: 'tantra:error', code: 'server' });
                });
            });

            // Ask until the CRM answers: its listener may attach after this frame loads.
            var asks = 0;
            ready();
            var asking = window.setInterval(function () {
                asks += 1;
                if (busy || asks >= 14) {
                    window.clearInterval(asking);
                    return;
                }
                ready();
            }, 1000);
        })();
    </script>
</body>
</html>
