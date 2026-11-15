{{-- tantra: Tantra frames this page, hidden, when someone signs out of Tantra (S-12). It ends the Engine session in this browser. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>Signing out</title>
</head>
<body>
    <script>
        (function () {
            var parents = {{ Js::from($parents) }};
            var signOutUrl = {{ Js::from($signOutUrl) }};
            var csrf = {{ Js::from(csrf_token()) }};

            function tell() {
                if (window.parent === window) {
                    return;
                }
                parents.forEach(function (origin) {
                    try {
                        window.parent.postMessage({ v: 1, type: 'tantra:signed-out' }, origin);
                    } catch (e) {
                        // Not the real parent origin: dropped.
                    }
                });
            }

            fetch(signOutUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (response.ok) {
                    tell();
                }
            });
        })();
    </script>
</body>
</html>
