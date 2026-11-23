<?php

declare(strict_types=1);

use App\Tantra\Crm\CrmPaths;
use App\Tantra\Http\Middleware\EngineUiOff;
use App\Tantra\UiOff\UiRoutes;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// tantra: One Content app PRD 4.1 (critic B6). The allowlist test, generated from the route list (the same routes as
// `php artisan route:list`): every web route is on the UI list or the allowlist, and with the switch on each one does what its
// list says. A new web route fails here until it is put on one of the lists in App\Tantra\UiOff\UiRoutes.

/** @return list<RoutingRoute> */
function uiOffWebRoutes(): array
{
    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $r): bool => UiRoutes::isWeb($r)));
}

/** A concrete path for a route URI template (each parameter a UUID, optional ones left out). */
function uiOffPath(RoutingRoute $route): string
{
    $path = preg_replace('/\{\w+\?\}/', '', $route->uri());
    $path = preg_replace('/\{\w+\}/', '9b1d6a3e-1f0a-4c55-9d1e-3f1c2b7a8e01', (string) $path);

    return '/'.trim((string) $path, '/');
}

it('puts every web route on the UI list or the allowlist', function () {
    $rows = UiRoutes::classify(Route::getRoutes()->getRoutes());
    $none = array_values(array_map(fn (array $r): string => "{$r['method']} {$r['uri']}", array_filter($rows, fn (array $r): bool => $r['list'] === 'none')));

    expect($rows)->not->toBeEmpty()
        ->and($none)->toBe([], "Web routes on neither list (add each to UiRoutes::UI or UiRoutes::ALLOW):\n".implode("\n", $none));
});

it('has no list entry that matches no route', function () {
    $rows = UiRoutes::classify(Route::getRoutes()->getRoutes());
    $used = array_unique(array_column($rows, 'entry'));

    $dead = array_values(array_diff([...UiRoutes::ALLOW, ...array_keys(UiRoutes::UI)], $used));

    expect($dead)->toBe([], "Entries no web route reaches:\n".implode("\n", $dead));
});

it('keeps the routes PRD 4.1 names out of the web group, so the switch never sees them', function (string $uri) {
    $routes = array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $r): bool => Str::is($uri, $r->uri()));

    expect($routes)->not->toBeEmpty();
    foreach ($routes as $route) {
        expect(UiRoutes::isWeb($route))->toBeFalse("{$route->uri()} is in the web group");
    }
})->with([
    'api/*', 'api/gateway/*', 'mcp/*', 'tantra/webhooks/*', 'tantra/billing/dodo/webhook', 'tantra/health', 'up',
    '.well-known/oauth-*', 'tantra/mail-image/*', 'tantra/media/{media}/file', 'tantra/digest/unsubscribe/*',
    'accounts/threads/uninstall', 'accounts/threads/delete', 'accounts/threads/deletion/*',
    'storage/*',
]);

it('lets every allowlisted route through with the switch on, and leaves everything alone with it off', function () {
    $middleware = new EngineUiOff;
    $passed = fn (): Response => response('passed');
    $checked = 0;

    foreach ([true, false] as $on) {
        config(['tantra.ui_off.enabled' => $on]);
        foreach (uiOffWebRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                if (! $on || UiRoutes::allowed($route, $method)) {
                    $request = Request::create(uiOffPath($route), $method);
                    $request->setRouteResolver(fn (): RoutingRoute => $route);
                    expect($middleware->handle($request, $passed)->getContent())->toBe('passed', "{$method} {$route->uri()} (switch ".($on ? 'on' : 'off').')');
                    $checked++;
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(300);
});

it('sends every UI GET to its CRM page and refuses every other UI request with 410', function () {
    config(['tantra.ui_off.enabled' => true]);
    $middleware = new EngineUiOff;
    $next = fn (): never => throw new RuntimeException('a UI route reached its controller');
    $ui = 0;

    foreach (uiOffWebRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            if (UiRoutes::allowed($route, $method)) {
                continue;
            }
            $ui++;
            $request = Request::create(uiOffPath($route), $method);
            $route = clone $route;
            $route->bind($request);
            $request->setRouteResolver(fn (): RoutingRoute => $route);
            $response = $middleware->handle($request, $next);

            if ($method === 'GET') {
                $entry = UiRoutes::uiEntry($route, 'GET');
                expect($response->getStatusCode())->toBe(302, "GET {$route->uri()}")
                    ->and($response->headers->get('Location'))->toStartWith(CrmPaths::base().'/');
                if (! str_contains(UiRoutes::UI[$entry], '{')) {
                    expect($response->headers->get('Location'))->toBe(CrmPaths::base().UiRoutes::UI[$entry]);
                }
            } else {
                expect($response->getStatusCode())->toBe(410, "{$method} {$route->uri()}");
            }
        }
    }

    expect($ui)->toBeGreaterThan(250);
});
