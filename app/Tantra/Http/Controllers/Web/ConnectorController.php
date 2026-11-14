<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tantra\Ai\AiAlerts;
use App\Tantra\Ai\AiConnector;
use App\Tantra\Ai\AiFailure;
use App\Tantra\Ai\ConnectionTester;
use App\Tantra\Ai\EndpointGuard;
use App\Tantra\Ai\ProviderCatalog;
use App\Tantra\Ai\TantraAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * tantra: the Connectors tab, modelled on the LLM Providers page of the main
 * Tantra app: one card per provider with a saved key (shown as its last four),
 * Test connection, a model picker and a Default switch. Connectors belong to
 * the Account, so every workspace and teammate uses the same ones; only the
 * Account owner changes them.
 */
class ConnectorController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $saved = AiConnector::query()->where('account_id', $user->account_id)->get()->keyBy('provider');

        $canManage = $this->canManage($user);
        $providers = collect(ProviderCatalog::all())->map(function (array $p, string $key) use ($saved, $canManage): array {
            $c = $saved->get($key);

            return [
                'key' => $key,
                'label' => $p['label'],
                'group' => $p['group'],
                'kind' => $p['kind'],
                'is_ai' => ProviderCatalog::isAi($key),
                'default_base_url' => $p['base_url'],
                'base_url_required' => $p['base_url_required'],
                'default_model' => $p['default_model'],
                'has_model_list' => $p['models_path'] !== null,
                'key_url' => $p['key_url'],
                'key_label' => $p['key_label'],
                'key_placeholder' => $p['key_placeholder'],
                'free_note' => $p['free_note'],
                'supports_images' => $p['supports_images'],
                'setup' => $p['setup'],
                'connector' => $c === null ? null : self::present($c, $canManage),
            ];
        })->values();

        return Inertia::render('tantra/connectors/Index', [
            'providers' => $providers,
            'canManage' => $canManage,
            'serverFallback' => TantraAi::serverProviderConfigured(),
            // Social channels show as the logo grid (shared tantraChannels.networks);
            // their developer app keys live on the owner's Developer apps page.
        ]);
    }

    public function update(Request $request, string $provider): JsonResponse
    {
        $this->authorizeManage($request->user());
        $this->knownProvider($provider);

        $data = $request->validate([
            'secret' => ['nullable', 'string', 'max:20000'],
            'base_url' => ['nullable', 'string', 'max:255', 'regex:/^https?:\/\//'],
            'selected_model' => ['nullable', 'string', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $connector = AiConnector::query()->firstOrNew(['account_id' => $request->user()->account_id, 'provider' => $provider]);
        if (! $connector->exists && blank($data['secret'] ?? null)) {
            return response()->json(['message' => 'Paste the key first.'], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (filled($data['secret'] ?? null)) {
            $connector->secret = trim((string) $data['secret']);
            $connector->secret_last_four = AiConnector::lastFour($connector->secret);
            $connector->last_failed_at = null;
            $connector->last_error = null;
        }
        if (ProviderCatalog::find($provider)['base_url_required'] && blank($data['base_url'] ?? $connector->base_url) && str_contains((string) ProviderCatalog::find($provider)['base_url'], '{')) {
            return response()->json(['message' => 'Fill in the endpoint.'], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        $baseUrl = array_key_exists('base_url', $data) ? $data['base_url'] : $connector->base_url;
        $model = array_key_exists('selected_model', $data) ? $data['selected_model'] : $connector->selected_model;
        $endpointChanged = $connector->exists && rtrim((string) ($baseUrl ?: ProviderCatalog::find($provider)['base_url']), '/') !== rtrim((string) $connector->effectiveBaseUrl(), '/');
        if ($endpointChanged && blank($data['secret'] ?? null)) {
            return response()->json(['message' => 'Type the key again to use it with a different endpoint.'], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($error = self::endpointError($baseUrl)) {
            return response()->json(['message' => $error], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (ProviderCatalog::isAi($provider) && blank($model) && blank(ProviderCatalog::find($provider)['default_model'])) {
            return response()->json(['message' => 'Type the model name.'], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        $connector->fill([
            'base_url' => $baseUrl,
            'selected_model' => $model,
            'is_active' => $data['is_active'] ?? $connector->is_active ?? true,
            'updated_by' => $request->user()->id,
        ]);
        $connector->save();

        // Like the main app: the first AI connector saved becomes the default.
        if (ProviderCatalog::isAi($provider) && ! AiConnector::query()->where('account_id', $connector->account_id)->where('is_default', true)->exists()) {
            $connector->forceFill(['is_default' => true])->save();
        }

        AiAlerts::forget($connector->account_id);

        return response()->json(['message' => 'Saved.', 'connector' => self::present($connector->fresh(), true)]);
    }

    public function destroy(Request $request, string $provider): JsonResponse
    {
        $this->authorizeManage($request->user());
        $connector = $this->connector($request, $provider);
        $wasDefault = $connector->is_default;
        $connector->delete();

        if ($wasDefault) {
            AiConnector::query()->forAi()->where('account_id', $connector->account_id)->whereNotNull('secret')
                ->orderByRaw('last_verified_at is null')->orderByDesc('last_verified_at')->first()?->forceFill(['is_default' => true])->save();
        }
        AiAlerts::forget($connector->account_id);

        return response()->json(['message' => 'Removed.']);
    }

    public function makeDefault(Request $request, string $provider): JsonResponse
    {
        $this->authorizeManage($request->user());
        $connector = $this->connector($request, $provider);
        if (! ProviderCatalog::isAi($provider)) {
            return response()->json(['message' => 'Only AI providers can be the default.'], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        AiConnector::query()->where('account_id', $connector->account_id)->update(['is_default' => false]);
        $connector->forceFill(['is_default' => true, 'is_active' => true])->save();
        AiAlerts::forget($connector->account_id);

        return response()->json(['message' => $connector->catalog()['label'].' now runs every AI feature.']);
    }

    public function test(Request $request, string $provider): JsonResponse
    {
        $this->authorizeManage($request->user());
        $this->knownProvider($provider);
        $data = $request->validate([
            'secret' => ['nullable', 'string', 'max:20000'],
            'base_url' => ['nullable', 'string', 'max:255', 'regex:/^https?:\/\//'],
            'selected_model' => ['nullable', 'string', 'max:200'],
        ]);

        $saved = AiConnector::query()->where('account_id', $request->user()->account_id)->where('provider', $provider)->first();
        $probe = self::probe($request, $provider, $saved, $data);
        if (is_string($probe)) {
            return response()->json(['ok' => false, 'message' => $probe], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = ConnectionTester::test($probe);
        $result['models_available'] = $result['ok'] ? count(ConnectionTester::models($probe)) ?: null : null;

        // Testing the saved settings updates the card's status and the banner.
        $testedSaved = $saved !== null
            && blank($data['secret'] ?? null)
            && (blank($data['base_url'] ?? null) || rtrim((string) $data['base_url'], '/') === rtrim((string) ($saved->base_url ?? $saved->effectiveBaseUrl()), '/'))
            && (blank($data['selected_model'] ?? null) || $data['selected_model'] === $saved->effectiveModel());
        if ($testedSaved) {
            if ($result['ok']) {
                TantraAi::recordSuccess($saved);
            } else {
                $f = $result['failure'];
                TantraAi::recordFailure($saved, new AiFailure($f['provider'], $f['label'], $f['reason'], $f['detail']));
            }
            $result['connector'] = self::present($saved->fresh(), true);
        }

        return response()->json($result);
    }

    public function models(Request $request, string $provider): JsonResponse
    {
        $this->authorizeManage($request->user());
        $this->knownProvider($provider);
        $saved = AiConnector::query()->where('account_id', $request->user()->account_id)->where('provider', $provider)->first();
        $probe = self::probe($request, $provider, $saved, $request->only(['secret', 'base_url']));
        if (is_string($probe)) {
            return response()->json(['models' => [], 'message' => $probe], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['models' => ConnectionTester::models($probe)]);
    }

    /**
     * The connector as it would be with the typed values. A saved key is never
     * sent to an endpoint other than the saved one: changing the endpoint
     * needs the key typed again.
     *
     * @param  array<string, mixed>  $data
     */
    private static function probe(Request $request, string $provider, ?AiConnector $saved, array $data): AiConnector|string
    {
        $probe = $saved !== null ? $saved->replicate() : new AiConnector(['account_id' => $request->user()->account_id, 'provider' => $provider]);
        if ($saved !== null) {
            $probe->id = $saved->id; // so a Codex sign-in refreshed during the test is kept
        }

        $typedSecret = filled($data['secret'] ?? null);
        if ($typedSecret) {
            $probe->secret = trim((string) $data['secret']);
        }
        if (filled($data['base_url'] ?? null)) {
            $newUrl = rtrim((string) $data['base_url'], '/');
            if ($saved !== null && ! $typedSecret && $newUrl !== rtrim((string) $saved->effectiveBaseUrl(), '/')) {
                return 'Type the key again to use it with a different endpoint.';
            }
            $probe->base_url = $newUrl;
        }
        if (filled($data['selected_model'] ?? null)) {
            $probe->selected_model = (string) $data['selected_model'];
        }
        if (blank($probe->secret)) {
            return 'Paste the key first.';
        }

        return self::endpointError($probe->effectiveBaseUrl()) ?? $probe;
    }

    private static function endpointError(?string $url): ?string
    {
        if (blank($url) || str_contains((string) $url, '{')) {
            return null;
        }
        try {
            EndpointGuard::check($url);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function present(AiConnector $c, bool $withDetail): array
    {
        $failing = $c->last_failed_at !== null && ($c->last_verified_at === null || $c->last_failed_at->gt($c->last_verified_at));
        ['reason' => $reason, 'message' => $message, 'detail' => $detail] = AiFailure::parseStored($c->last_error);

        return [
            'secret_last_four' => $c->secret_last_four,
            'base_url' => $c->base_url,
            'selected_model' => $c->selected_model,
            'effective_model' => $c->effectiveModel(),
            'is_default' => $c->is_default,
            'is_active' => $c->is_active,
            'status' => ! $c->is_active ? 'paused' : ($failing ? 'failing' : ($c->last_verified_at !== null ? 'connected' : 'untested')),
            'last_verified_at' => $c->last_verified_at?->toIso8601String(),
            'last_failed_at' => $c->last_failed_at?->toIso8601String(),
            'failure_reason' => $failing ? $reason : null,
            'failure_message' => $failing ? trim($message.($withDetail && $detail !== '' ? ' '.$detail : '')) : null,
        ];
    }

    private function connector(Request $request, string $provider): AiConnector
    {
        return AiConnector::query()->where('account_id', $request->user()->account_id)->where('provider', $provider)->firstOrFail();
    }

    private function knownProvider(string $provider): void
    {
        abort_if(ProviderCatalog::find($provider) === null, HttpResponse::HTTP_NOT_FOUND);
    }

    private function canManage(User $user): bool
    {
        return $user->account?->owner_id === $user->id;
    }

    private function authorizeManage(User $user): void
    {
        abort_unless($this->canManage($user), HttpResponse::HTTP_FORBIDDEN, 'Only the Account owner can change connectors.');
    }
}
