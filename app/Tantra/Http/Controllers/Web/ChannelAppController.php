<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Actions\SocialAccount\RegisterTelegramWebhook;
use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tantra\Channels\ChannelApp;
use App\Tantra\Channels\ChannelApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * tantra: the Channels section of the Connectors tab. The Account owner saves
 * each developer app's keys here; the network they unlock is connectable at
 * once. Secrets are write only: the page only ever sees their last four.
 */
class ChannelAppController extends Controller
{
    /**
     * The section's data, also rendered by ConnectorController::index.
     *
     * @return list<array<string, mixed>>
     */
    public static function present(bool $canManage): array
    {
        $saved = ChannelApps::saved() ?? [];
        $base = rtrim((string) config('app.url'), '/');

        return collect(ChannelApps::catalog())->map(function (array $entry, string $app) use ($saved, $canManage, $base): array {
            $fields = [];
            foreach ($entry['fields'] as $key => $spec) {
                if ($spec['generated'] ?? false) {
                    continue;
                }
                $value = $saved[$app][$key] ?? null;
                $source = ($spec['switch'] ?? false)
                    ? (ChannelApps::filled($app, $key, $saved) ? 'app' : null)
                    : ChannelApps::source($app, $key, $saved);
                $fields[] = [
                    'key' => $key,
                    'label' => $spec['label'],
                    'secret' => (bool) ($spec['secret'] ?? false),
                    'switch' => (bool) ($spec['switch'] ?? false),
                    'source' => $source,
                    // Secrets never leave the server; plain values only to the owner.
                    'last_four' => ($spec['secret'] ?? false) && $source === 'app' && $canManage && Str::length((string) $value) > 8 ? Str::substr((string) $value, -4) : null,
                    'value' => ! ($spec['secret'] ?? false) && ! ($spec['switch'] ?? false) && $canManage && $source === 'app' ? (string) $value : null,
                    'on' => ($spec['switch'] ?? false) ? ChannelApps::filled($app, $key, $saved) : null,
                ];
            }

            $networks = [];
            foreach (array_keys($entry['networks']) as $network) {
                $networks[] = [
                    'value' => $network,
                    'label' => Platform::from($network)->label(),
                    'ready' => ChannelApps::isReady($network, $saved),
                ];
            }

            return [
                'key' => $app,
                'label' => $entry['label'],
                'portal' => $entry['portal'],
                'help' => $entry['help'],
                'catch' => $entry['catch'],
                'callbacks' => array_map(fn (string $path): string => "{$base}/{$path}", $entry['callbacks']),
                'networks' => $networks,
                'fields' => $fields,
                'saved_in_app' => array_key_exists($app, $saved),
            ];
        })->values()->all();
    }

    public function update(Request $request, string $app): JsonResponse
    {
        $this->authorizeManage($request->user());
        $entry = ChannelApps::catalog()[$app] ?? abort(HttpResponse::HTTP_NOT_FOUND);

        $rules = [];
        foreach ($entry['fields'] as $key => $spec) {
            if ($spec['generated'] ?? false) {
                continue;
            }
            $rules[$key] = ($spec['switch'] ?? false)
                ? ['sometimes', 'boolean']
                : ['nullable', 'string', 'max:4000', 'regex:/^\S+$/'];
        }
        $data = $request->validate($rules, ['regex' => 'Paste the value without spaces.']);

        $row = ChannelApp::query()->firstOrNew(['app' => $app]);
        $credentials = $row->exists ? (array) $row->credentials : [];
        foreach ($entry['fields'] as $key => $spec) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            if ($spec['switch'] ?? false) {
                $credentials[$key] = (bool) $data[$key];
            } elseif (filled($data[$key])) {
                // An empty secret box means "keep the saved one".
                $credentials[$key] = trim((string) $data[$key]);
            } elseif (! ($spec['secret'] ?? false)) {
                unset($credentials[$key]);
            }
        }
        if ($app === 'telegram' && blank($credentials['webhook_secret'] ?? null)) {
            $credentials['webhook_secret'] = Str::random(48);
        }

        $row->fill(['credentials' => $credentials, 'updated_by' => $request->user()->id])->save();
        ChannelApps::forget();
        ChannelApps::apply();

        $message = $entry['label'].' saved.';
        if ($app === 'telegram' && ChannelApps::isReady(Platform::Telegram->value)) {
            try {
                RegisterTelegramWebhook::execute();
            } catch (Throwable $e) {
                // The message can hold the bot URL, token included: log the type only.
                Log::warning('tantra: Telegram webhook registration failed', ['error' => $e::class]);
                $message .= ' The Telegram webhook could not be registered: check the bot token.';
            }
        }

        return response()->json(['message' => $message, 'apps' => self::present(true)]);
    }

    public function destroy(Request $request, string $app): JsonResponse
    {
        $this->authorizeManage($request->user());
        abort_unless(array_key_exists($app, ChannelApps::catalog()), HttpResponse::HTTP_NOT_FOUND);

        ChannelApp::query()->where('app', $app)->delete();
        ChannelApps::forget();
        ChannelApps::apply();

        return response()->json(['message' => 'Keys removed.', 'apps' => self::present(true)]);
    }

    private function authorizeManage(User $user): void
    {
        abort_unless(ChannelApps::canManage($user), HttpResponse::HTTP_FORBIDDEN, 'Only the Account owner can change channel keys.');
    }
}
