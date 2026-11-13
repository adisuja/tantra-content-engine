<?php

declare(strict_types=1);

namespace App\Tantra\Ai;

/**
 * tantra: every connector the Connectors tab offers, in the order it shows
 * them. "ai" connectors can run the app's AI features, "data" connectors
 * (the Tantra API) cannot.
 *
 * kind:      api_key (paste a key), subscription (Claude or ChatGPT plan through the official CLI), data
 * driver:    the laravel/ai driver the connector runs on
 * base_url:  the default endpoint; base_url_required asks the user for one
 */
final class ProviderCatalog
{
    public const GROUP_FREE = 'free';

    public const GROUP_SUBSCRIPTION = 'subscription';

    public const GROUP_PAID = 'paid';

    public const GROUP_TANTRA = 'tantra';

    /**
     * @return array<string, array{
     *     label: string, group: string, kind: string, driver: string|null, base_url: string|null,
     *     base_url_required: bool, default_model: string|null, models_path: string|null,
     *     key_url: string|null, key_label: string, key_placeholder: string, free_note: string|null,
     *     supports_images: bool, setup: list<string>
     * }>
     */
    public static function all(): array
    {
        return [
            'omniroute' => self::entry(
                label: 'OmniRoute',
                group: self::GROUP_FREE,
                driver: 'openai-compatible',
                baseUrl: 'https://your-omniroute-host/v1',
                baseUrlRequired: true,
                defaultModel: 'auto',
                keyUrl: 'https://github.com/diegosouzapw/OmniRoute',
                keyLabel: 'OmniRoute API key',
                keyPlaceholder: 'sk-...',
                freeNote: 'One endpoint that routes to the free tiers of many providers and falls back when one runs out.',
                setup: [
                    'Run OmniRoute on a public https address the engine can reach and add your free provider accounts in its dashboard.',
                    'Copy its endpoint (it ends in /v1) and an API key from the OmniRoute dashboard.',
                    'Paste both here, test, and pick a model or combo.',
                ],
            ),
            'cloudflare' => self::entry(
                label: 'Cloudflare Workers AI',
                group: self::GROUP_FREE,
                driver: 'openai-compatible',
                baseUrl: 'https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/v1',
                baseUrlRequired: true,
                defaultModel: '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
                modelsPath: null,
                keyUrl: 'https://dash.cloudflare.com/profile/api-tokens',
                keyLabel: 'API token with Workers AI read and edit',
                keyPlaceholder: 'Cloudflare API token',
                freeNote: '10,000 neurons a day free.',
                setup: [
                    'In Cloudflare, create an API token with the Workers AI permission.',
                    'Replace {account_id} in the endpoint with your Cloudflare account ID.',
                ],
            ),
            'gemini' => self::entry(
                label: 'Google Gemini',
                group: self::GROUP_FREE,
                driver: 'gemini',
                baseUrl: 'https://generativelanguage.googleapis.com/v1beta/',
                defaultModel: 'gemini-2.5-flash',
                modelsPath: null,
                keyUrl: 'https://aistudio.google.com/apikey',
                keyLabel: 'Gemini API key',
                keyPlaceholder: 'AIza...',
                freeNote: 'Free tier in Google AI Studio, rate limited.',
                supportsImages: true,
            ),
            'groq' => self::entry(
                label: 'Groq',
                group: self::GROUP_FREE,
                driver: 'groq',
                baseUrl: 'https://api.groq.com/openai/v1',
                defaultModel: 'openai/gpt-oss-120b',
                keyUrl: 'https://console.groq.com/keys',
                keyLabel: 'Groq API key',
                keyPlaceholder: 'gsk_...',
                freeNote: 'Free tier with daily request limits.',
            ),
            'openrouter' => self::entry(
                label: 'OpenRouter',
                group: self::GROUP_FREE,
                driver: 'openrouter',
                baseUrl: 'https://openrouter.ai/api/v1',
                defaultModel: 'openai/gpt-oss-20b:free',
                keyUrl: 'https://openrouter.ai/keys',
                keyLabel: 'OpenRouter API key',
                keyPlaceholder: 'sk-or-...',
                freeNote: 'Models ending in :free cost nothing.',
            ),
            'cerebras' => self::entry(
                label: 'Cerebras',
                group: self::GROUP_FREE,
                driver: 'openai-compatible',
                baseUrl: 'https://api.cerebras.ai/v1',
                defaultModel: 'gpt-oss-120b',
                keyUrl: 'https://cloud.cerebras.ai',
                keyLabel: 'Cerebras API key',
                keyPlaceholder: 'csk-...',
                freeNote: 'Free tier with daily token limits.',
            ),
            'mistral' => self::entry(
                label: 'Mistral',
                group: self::GROUP_FREE,
                driver: 'mistral',
                baseUrl: 'https://api.mistral.ai/v1',
                defaultModel: 'mistral-small-latest',
                keyUrl: 'https://console.mistral.ai/api-keys',
                keyLabel: 'Mistral API key',
                keyPlaceholder: 'Mistral API key',
                freeNote: 'Free Experiment plan.',
            ),
            'claude_cli' => self::entry(
                label: 'Claude (Claude Code)',
                group: self::GROUP_SUBSCRIPTION,
                kind: 'subscription',
                driver: 'tantra-claude-cli',
                defaultModel: 'sonnet',
                modelsPath: null,
                keyUrl: 'https://docs.claude.com/en/docs/claude-code/setup',
                keyLabel: 'Claude Code token',
                keyPlaceholder: 'sk-ant-oat01-...',
                freeNote: 'Runs on your Claude Pro or Max plan through the official Claude Code CLI.',
                setup: [
                    'On your own computer, install Claude Code and run: claude setup-token',
                    'Sign in with your Claude account in the browser window it opens.',
                    'Copy the token it prints (it starts with sk-ant-oat01-) and paste it here.',
                ],
            ),
            'codex_cli' => self::entry(
                label: 'ChatGPT (Codex)',
                group: self::GROUP_SUBSCRIPTION,
                kind: 'subscription',
                driver: 'tantra-codex-cli',
                defaultModel: 'gpt-5-codex',
                modelsPath: null,
                keyUrl: 'https://developers.openai.com/codex/cli',
                keyLabel: 'Codex sign-in file (auth.json)',
                keyPlaceholder: '{"OPENAI_API_KEY": null, "tokens": {...}}',
                freeNote: 'Runs on your ChatGPT Plus, Pro or Business plan through the official Codex CLI.',
                setup: [
                    'On your own computer, install the Codex CLI and run: codex login',
                    'Sign in with your ChatGPT account in the browser window it opens.',
                    'Open ~/.codex/auth.json, copy everything in it and paste it here.',
                ],
            ),
            'openai' => self::entry(
                label: 'OpenAI',
                group: self::GROUP_PAID,
                driver: 'openai',
                baseUrl: 'https://api.openai.com/v1',
                defaultModel: 'gpt-4.1-mini',
                keyUrl: 'https://platform.openai.com/api-keys',
                keyLabel: 'OpenAI API key',
                keyPlaceholder: 'sk-...',
                supportsImages: true,
            ),
            'anthropic' => self::entry(
                label: 'Anthropic API',
                group: self::GROUP_PAID,
                driver: 'anthropic',
                baseUrl: 'https://api.anthropic.com/v1',
                defaultModel: 'claude-sonnet-4-5',
                keyUrl: 'https://console.anthropic.com/settings/keys',
                keyLabel: 'Anthropic API key',
                keyPlaceholder: 'sk-ant-api03-...',
                supportsImages: true,
            ),
            'novita' => self::entry(
                label: 'Novita AI',
                group: self::GROUP_PAID,
                driver: 'openai-compatible',
                baseUrl: 'https://api.novita.ai/v3/openai',
                defaultModel: 'meta-llama/llama-3.3-70b-instruct',
                keyUrl: 'https://novita.ai/settings/key-management',
                keyLabel: 'Novita API key',
                keyPlaceholder: 'sk_...',
            ),
            'custom' => self::entry(
                label: 'Any OpenAI-compatible endpoint',
                group: self::GROUP_PAID,
                driver: 'openai-compatible',
                baseUrlRequired: true,
                keyLabel: 'API key',
                keyPlaceholder: 'API key',
                setup: ['Paste the endpoint (usually ending in /v1), its key and the model name.'],
            ),
            'tantra_api' => self::entry(
                label: 'Tantra API',
                group: self::GROUP_TANTRA,
                kind: 'data',
                driver: null,
                baseUrl: 'https://api.usetantra.com/api/v1',
                modelsPath: null,
                keyUrl: 'https://usetantra.com/api-keys',
                keyLabel: 'Tantra API key',
                keyPlaceholder: 'tk_live_...',
                setup: [
                    'In Tantra, open Connectors, then API Keys, and create a key.',
                    'Paste it here. The engine reads your Tantra campaigns and contacts, and every social account you connected in Tantra shows up as a channel within 15 minutes.',
                ],
            ),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $provider): ?array
    {
        return self::all()[$provider] ?? null;
    }

    /** @return list<string> */
    public static function aiProviders(): array
    {
        return array_values(array_filter(array_keys(self::all()), self::isAi(...)));
    }

    public static function isAi(string $provider): bool
    {
        return in_array(self::find($provider)['kind'] ?? null, ['api_key', 'subscription'], true);
    }

    /**
     * @param  list<string>  $setup
     * @return array<string, mixed>
     */
    private static function entry(
        string $label,
        string $group,
        ?string $driver,
        string $kind = 'api_key',
        ?string $baseUrl = null,
        bool $baseUrlRequired = false,
        ?string $defaultModel = null,
        ?string $modelsPath = '/models',
        ?string $keyUrl = null,
        string $keyLabel = 'API key',
        string $keyPlaceholder = '',
        ?string $freeNote = null,
        bool $supportsImages = false,
        array $setup = [],
    ): array {
        return [
            'label' => $label,
            'group' => $group,
            'kind' => $kind,
            'driver' => $driver,
            'base_url' => $baseUrl,
            'base_url_required' => $baseUrlRequired,
            'default_model' => $defaultModel,
            'models_path' => $modelsPath,
            'key_url' => $keyUrl,
            'key_label' => $keyLabel,
            'key_placeholder' => $keyPlaceholder,
            'free_note' => $freeNote,
            'supports_images' => $supportsImages,
            'setup' => $setup,
        ];
    }
}
