<?php

declare(strict_types=1);

namespace App\Tantra\Templates;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * tantra: a content template (PRD 25.9). Seeded ones are the samples from the
 * LinkedIn Creator Taxonomy and the animated set; intake ones are built by an
 * agent job from a post someone pasted.
 *
 * @property string $id
 * @property string|null $account_id
 * @property string $key
 * @property string $title
 * @property string $platform
 * @property string $status
 * @property string $origin
 * @property list<string>|null $palette
 */
final class ContentTemplate extends Model
{
    use HasUuids;

    public const PLATFORMS = ['linkedin', 'facebook', 'instagram', 'youtube', 'x', 'tiktok', 'threads', 'bluesky', 'pinterest', 'other'];

    protected $table = 'tantra_content_templates';

    protected $fillable = [
        'account_id', 'key', 'title', 'platform', 'format', 'family', 'archetype', 'creator', 'source_url', 'description',
        'spec', 'copy_template', 'palette', 'preview_path', 'sample_md', 'status', 'origin', 'note', 'created_by', 'agent_job_id',
    ];

    protected function casts(): array
    {
        return ['palette' => 'array'];
    }

    /** Seeded samples for everyone, plus the account's own. */
    public static function visibleTo(?string $accountId): Builder
    {
        return self::query()->where(fn ($q) => $q->whereNull('account_id')->when($accountId, fn ($w) => $w->orWhere('account_id', $accountId)));
    }

    public function previewUrl(): ?string
    {
        return $this->preview_path ? Storage::url($this->preview_path) : null;
    }

    /** @return array<string, mixed> */
    public function toCard(): array
    {
        return [
            'id' => $this->id, 'key' => $this->key, 'title' => $this->title, 'platform' => $this->platform,
            'format' => $this->format, 'family' => $this->family, 'creator' => $this->creator, 'status' => $this->status,
            'origin' => $this->origin, 'preview' => $this->previewUrl(), 'source_url' => $this->source_url,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function toDetail(): array
    {
        return [
            ...$this->toCard(), 'archetype' => $this->archetype, 'description' => $this->description, 'spec' => $this->spec,
            'copy_template' => $this->copy_template, 'palette' => $this->palette ?? [], 'note' => $this->note,
        ];
    }

    public static function platformFor(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www\.|m\.|mobile\.)/', '', $host) ?? $host;

        return match (true) {
            str_ends_with($host, 'linkedin.com') || $host === 'lnkd.in' => 'linkedin',
            str_ends_with($host, 'facebook.com') || $host === 'fb.watch' || $host === 'fb.com' => 'facebook',
            str_ends_with($host, 'instagram.com') => 'instagram',
            str_ends_with($host, 'youtube.com') || $host === 'youtu.be' => 'youtube',
            $host === 'x.com' || $host === 'twitter.com' => 'x',
            str_ends_with($host, 'tiktok.com') => 'tiktok',
            str_ends_with($host, 'threads.net') || str_ends_with($host, 'threads.com') => 'threads',
            $host === 'bsky.app' => 'bluesky',
            str_ends_with($host, 'pinterest.com') || $host === 'pin.it' => 'pinterest',
            default => 'other',
        };
    }
}
