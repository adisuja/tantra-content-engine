<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>Threads data deletion · Tantra Content Engine</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        <style>
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f7f8fb; color: #0f172a; font: 16px/1.6 Inter, ui-sans-serif, system-ui, -apple-system, sans-serif; }
            main { max-width: 480px; margin: 24px; padding: 32px; background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; }
            h1 { margin: 0 0 12px; font-size: 22px; line-height: 1.3; }
            p { margin: 0 0 12px; color: #334155; }
            code { font-size: 15px; padding: 2px 6px; background: #f1f5f9; border-radius: 6px; }
        </style>
    </head>
    <body>
        <main>
            <h1>Your Threads data deletion request</h1>
            <p>On {{ $at }} you asked Meta to delete the data Tantra Content Engine holds about your Threads account. We stopped using your account at once and removed its access token.</p>
            <p>The connected channel, the posts stored for it and its analytics are deleted automatically, usually within minutes and always within 30 days.</p>
            <p>Confirmation code: <code>{{ $code }}</code></p>
            <p>Questions? Write to <a href="mailto:privacy@usetantra.com">privacy@usetantra.com</a>.</p>
        </main>
    </body>
</html>
