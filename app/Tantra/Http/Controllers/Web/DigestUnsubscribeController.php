<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Tantra\Mail\Kit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * tantra: turns the daily digest off from the email, no sign in. The link is
 * signed. A GET only shows the button (link scanners open links); the POST,
 * from that button or a mail client's one-click unsubscribe, turns it off.
 */
class DigestUnsubscribeController extends Controller
{
    public function __invoke(Request $request, User $user): Response
    {
        $done = $request->isMethod('post');
        if ($done) {
            NotificationPreference::query()->updateOrCreate(['user_id' => $user->id], ['daily_digest' => false]);
        }

        $body = $done
            ? '<h1>The daily digest is off</h1><p>You will not get it again. Turn it back on any time in <a href="'.e(Kit::settingsUrl()).'">email settings</a>.</p>'
            : '<h1>Turn off the daily digest?</h1><p>You will still get review requests and alerts about your posts.</p><form method="post" action="'.e($request->fullUrl()).'"><button type="submit">Turn it off</button></form>';

        return response('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Daily digest</title>'
            .'<style>body{font-family:Inter,-apple-system,Segoe UI,Arial,sans-serif;background:#f3f4f8;color:#12131a;display:grid;place-items:center;min-height:100vh;margin:0}main{background:#fff;border:1px solid #e5e7ef;border-radius:16px;padding:32px;max-width:420px}h1{font-size:22px;margin:0 0 10px}p{color:#3a3f4e;line-height:1.5}a{color:#6d4aff}button{background:#6d4aff;color:#fff;border:0;border-radius:10px;padding:12px 20px;font-size:15px;font-weight:600;cursor:pointer}</style>'
            .'</head><body><main><img src="'.e(Kit::asset('tantra/email/logo.png')).'" width="154" height="40" alt="Tantra">'.$body.'</main></body></html>');
    }
}
