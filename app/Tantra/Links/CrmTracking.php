<?php

declare(strict_types=1);

namespace App\Tantra\Links;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * tantra: the Tantra CRM's tracking API (`/api/trk`, contract in tantra-crm
 * docs/crm/attribution/SPEC_REVENUE_TRACKING.md sections 5.1, 5.2 and 5.6).
 * The key stays on the server (TANTRA_CRM_API_KEY); nothing here reaches the browser.
 */
class CrmTracking
{
    public static function configured(): bool
    {
        return filled(config('tantra.crm.api_key'));
    }

    public function campaignId(): string
    {
        return (string) config('tantra.crm.links.campaign_id');
    }

    /** The campaign channel for a platform, added (with its vocabulary) the first time. */
    public function channel(string $platform): string
    {
        $platform = LinkRules::platform($platform);
        $known = config('tantra.crm.links.channels.'.$platform);
        if (filled($known)) {
            return (string) $known;
        }

        return Cache::rememberForever('tantra:crm:channel:'.$this->campaignId().':'.$platform, function () use ($platform): string {
            $body = ['channel' => $platform, 'source' => $platform, 'medium' => 'organic_social'];
            $response = $this->http()->post('/api/trk/campaigns/'.$this->campaignId().'/channels', $body);
            if ($response->status() === 422 && $response->json('code') === 'unknown_vocab') {
                $this->addVocab('channel', $platform);
                $this->addVocab('source', $platform);
                $response = $this->http()->post('/api/trk/campaigns/'.$this->campaignId().'/channels', $body);
            }

            return (string) ($this->ok($response, 'add the '.$platform.' channel')->json('channel.id') ?? $response->json('id'));
        });
    }

    /** One CRM asset per piece and platform, so the report can split clicks and conversions by piece. */
    public function asset(string $name, string $ref): string
    {
        $existing = $this->http()->get('/api/trk/assets', ['campaign_id' => $this->campaignId(), 'ref_type' => 'engine_piece', 'ref_id' => $ref]);
        $found = $existing->successful() ? ($existing->json('assets.0.id') ?? null) : null;
        if (filled($found)) {
            return (string) $found;
        }

        $response = $this->http()->post('/api/trk/assets', [
            'campaign_id' => $this->campaignId(), 'kind' => 'post', 'name' => mb_substr($name, 0, 120),
            'ref_type' => 'engine_piece', 'ref_id' => mb_substr($ref, 0, 80),
        ]);

        return (string) $this->ok($response, 'create the asset for '.$ref)->json('asset.id');
    }

    /**
     * POST /api/trk/links. Returns null when the code is taken, so the caller
     * can try the next one.
     *
     * @param  array<string, string>  $appendParams
     * @return array{id: string, short_url: string, long_url: string, code: string}|null
     */
    public function createLink(string $destination, string $channelId, ?string $assetId, string $code, string $placement, array $appendParams): ?array
    {
        $response = $this->http()->withHeaders(['Idempotency-Key' => 'engine-'.$code.'-'.substr(sha1($destination), 0, 12)])
            ->post('/api/trk/links', array_filter([
                'destination' => $destination,
                'campaign_id' => $this->campaignId(),
                'channel_id' => $channelId,
                'asset_id' => $assetId,
                'kind' => 'social',
                'placement' => $placement,
                'code' => $code,
                'append_params' => $appendParams,
            ], fn (mixed $v): bool => $v !== null));

        if ($response->status() === 409 && $response->json('code') === 'code_taken') {
            // Ours already (a race, or a save that failed after the CRM made it): adopt it. Someone else's: try the next code.
            return $this->ownLink($code, $destination, $channelId);
        }
        $this->ok($response, 'create the link '.$code);

        return [
            'id' => (string) $response->json('id'),
            'short_url' => (string) $response->json('short_url'),
            'long_url' => (string) $response->json('long_url'),
            'code' => (string) $response->json('code'),
        ];
    }

    /**
     * The campaign's link with this code, when it goes to the same place on the same channel.
     *
     * @return array{id: string, short_url: string, long_url: string, code: string}|null
     */
    private function ownLink(string $code, string $destination, string $channelId): ?array
    {
        $response = $this->http()->get('/api/trk/links', ['campaign_id' => $this->campaignId(), 'limit' => 200]);
        if (! $response->successful()) {
            return null;
        }
        foreach ((array) ($response->json('links') ?? []) as $link) {
            if (($link['code'] ?? null) === $code && ($link['destination'] ?? null) === $destination && ($link['channel_id'] ?? null) === $channelId) {
                return ['id' => (string) $link['id'], 'short_url' => (string) ($link['short_url'] ?? ''), 'long_url' => (string) ($link['long_url'] ?? ''), 'code' => $code];
            }
        }

        return null;
    }

    /**
     * Click counts for every link in the campaign, keyed by link id.
     *
     * @return array<string, array{code: string, raw: int, human: int}>
     */
    public function linkClicks(): array
    {
        $response = $this->http()->get('/api/trk/links', ['campaign_id' => $this->campaignId(), 'limit' => 200]);
        $this->ok($response, 'list the links');
        $out = [];
        foreach ((array) ($response->json('links') ?? $response->json('items') ?? []) as $link) {
            $out[(string) $link['id']] = ['code' => (string) ($link['code'] ?? ''), 'raw' => (int) data_get($link, 'clicks.raw', 0), 'human' => (int) data_get($link, 'clicks.human', 0)];
        }

        return $out;
    }

    /**
     * POST /api/trk/reports/query (spec 5.6).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function query(array $body): array
    {
        $response = $this->http()->timeout(25)->post('/api/trk/reports/query', $body);

        return (array) $this->ok($response, 'run the report')->json();
    }

    private function addVocab(string $kind, string $value): void
    {
        $this->http()->post('/api/trk/vocab', ['kind' => $kind, 'value' => $value, 'label' => ucfirst($value), 'aliases' => []]);
    }

    private function ok(Response $response, string $what): Response
    {
        if (! $response->successful()) {
            $code = (string) ($response->json('code') ?? '');
            throw new RuntimeException("The CRM could not {$what} (HTTP {$response->status()}".($code !== '' ? ", {$code}" : '').').');
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        if (! self::configured()) {
            throw new RuntimeException('TANTRA_CRM_API_KEY is not set on the server.');
        }

        return Http::baseUrl(rtrim((string) config('tantra.crm.url'), '/'))
            ->withToken((string) config('tantra.crm.api_key'))
            ->acceptJson()
            ->timeout(15);
    }
}
