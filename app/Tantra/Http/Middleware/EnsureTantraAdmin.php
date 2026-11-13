<?php

declare(strict_types=1);

namespace App\Tantra\Http\Middleware;

use App\Tantra\Agent\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** tantra: Studio ops is for TANTRA_ADMIN_EMAILS only; to anyone else it does not exist (PRD 25.4). */
class EnsureTantraAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Admin::is($request->user()), 404);

        return $next($request);
    }
}
