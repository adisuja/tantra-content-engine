<?php

declare(strict_types=1);

namespace App\Tantra\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * tantra: the sections of a Pinterest board, for the composer's section picker.
 */
final class PinterestSections
{
    /**
     * @return list<array{id: string, name: string}>
     */
    public static function list(SocialAccount $account, string $boardId): array
    {
        $response = Http::withToken((string) $account->access_token)
            ->get(config('trypost.platforms.pinterest.api').'/boards/'.rawurlencode($boardId).'/sections', ['page_size' => 100]);

        if ($response->failed()) {
            throw new RuntimeException('Pinterest board sections could not be read: '.$response->status());
        }

        return collect((array) $response->json('items', []))
            ->map(fn (mixed $section): array => ['id' => (string) data_get($section, 'id'), 'name' => (string) data_get($section, 'name', '')])
            ->filter(fn (array $section): bool => $section['id'] !== '')
            ->values()
            ->all();
    }
}
