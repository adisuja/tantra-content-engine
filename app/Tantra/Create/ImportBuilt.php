<?php

declare(strict_types=1);

namespace App\Tantra\Create;

use App\Actions\Media\DeleteOwnedMedia;
use App\Dto\ImportedFile;
use App\Dto\RemoteFile;
use App\Enums\Media\Type as MediaType;
use App\Models\Media;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\RemoteMediaImporter;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Review\ReviewGate;
use App\Tantra\Templates\ContentTemplate;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * tantra: Create as a chat (CR-06). The Tantra CRM builds the finished post itself (copy, rendered pages, PDF, MP4 or GIF
 * on https addresses) and sends it in `built` on POST /api/gateway/tantra/create, or on PUT /api/gateway/tantra/review/{item}
 * for a change to a post that waits for review. The piece goes into Review as it is: no AI writing step. Its media is
 * downloaded through the one remote import path (RemoteMediaImporter: SSRF guard, byte cap, MIME sniffed from the bytes)
 * from the allowed hosts only (config tantra.built.media_hosts), then attached the way a batch import attaches upload
 * tokens (ImportBatch).
 *
 * `built` (as tantra-crm src/content/create/buildJob.ts sends it):
 *   {title?, text, first_comment?, account?, media?: {type: carousel|image|video|gif|text, pages?: [https], pdf?, mp4?, gif?, image?}}
 */
final class ImportBuilt
{
    public const TYPES = ['carousel', 'image', 'video', 'gif', 'text'];

    public const TITLE_MAX = 120;

    public const COMMENT_MAX = 3000;

    public const URL_MAX = 1024;

    private const TIMEOUT_SECONDS = 30;

    /**
     * Validation rules for `built`, keyed for the request body.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $url = ['nullable', 'string', 'max:'.self::URL_MAX, self::urlRule()];

        return [
            'built' => ['sometimes', 'array'],
            'built.title' => ['nullable', 'string', 'max:'.self::TITLE_MAX],
            'built.text' => ['required_with:built', 'string', 'max:'.ReviewFeed::TEXT_MAX],
            'built.first_comment' => ['nullable', 'string', 'max:'.self::COMMENT_MAX],
            'built.account' => ['nullable', 'string', 'max:200'],
            'built.media' => ['nullable', 'array'],
            'built.media.type' => ['nullable', 'string', Rule::in(self::TYPES)],
            'built.media.pages' => ['nullable', 'array', 'max:'.max(1, (int) config('tantra.built.max_pages', 20))],
            'built.media.pages.*' => ['required', 'string', 'max:'.self::URL_MAX, self::urlRule()],
            'built.media.pdf' => $url,
            'built.media.mp4' => $url,
            'built.media.gif' => $url,
            'built.media.image' => $url,
        ];
    }

    /** An https address on an allowed host, with no user name or password in it. */
    public static function urlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }
            if (! is_string($value) || ! self::urlAllowed($value)) {
                $fail('Media must be an https address on an allowed host.');
            }
        };
    }

    public static function urlAllowed(string $url): bool
    {
        if (preg_match('/^https:\/\/[^\s"\'<>]+$/', $url) !== 1) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || blank($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }
        $hosts = self::hosts();

        return $hosts !== [] && app(RemoteMediaImporter::class)->hostAllowed($url, $hosts);
    }

    /** @return list<string> */
    public static function hosts(): array
    {
        return array_values(array_filter(array_map(fn ($h): string => Str::lower(trim((string) $h)), (array) config('tantra.built.media_hosts', [])), fn (string $h): bool => $h !== ''));
    }

    /**
     * The files to attach, in page order: [url, kind] with kind image, pdf, video or gif. A LinkedIn carousel goes as its
     * PDF (the swipeable document) when there is one; elsewhere a carousel goes as its pages.
     *
     * @param  array<string, mixed>  $media
     * @return list<array{url: string, kind: string}>
     */
    public static function files(array $media, string $platform): array
    {
        $type = in_array($media['type'] ?? null, self::TYPES, true) ? $media['type'] : 'text';
        $pages = array_values(array_filter((array) ($media['pages'] ?? []), fn ($u): bool => is_string($u) && $u !== ''));
        $one = fn (string $key): ?string => is_string($media[$key] ?? null) && $media[$key] !== '' ? $media[$key] : null;
        $asImages = fn (array $urls): array => array_map(fn (string $u): array => ['url' => $u, 'kind' => 'image'], $urls);

        return match ($type) {
            'carousel' => ($platform === 'linkedin' && $one('pdf')) || ($pages === [] && $one('pdf'))
                ? [['url' => (string) $one('pdf'), 'kind' => 'pdf']]
                : $asImages($pages),
            'image' => ($u = $one('image') ?? ($pages[0] ?? null)) !== null ? [['url' => $u, 'kind' => 'image']] : [],
            'video' => ($u = $one('mp4')) !== null ? [['url' => $u, 'kind' => 'video']] : [],
            'gif' => ($u = $one('gif')) !== null ? [['url' => $u, 'kind' => 'gif']] : (($u = $one('mp4')) !== null ? [['url' => $u, 'kind' => 'video']] : []),
            default => [],
        };
    }

    /**
     * Import a new built piece into Review for the workspace. Returns the piece's state (status "ready").
     *
     * @param  array<string, mixed>  $built  validated
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function create(User $user, Workspace $workspace, string $formatKey, ?string $idea, ?string $platform, array $built): array
    {
        $template = preg_match(FormatCards::KEY, $formatKey) === 1
            ? ContentTemplate::visibleTo($user->account_id)->where('key', $formatKey)->first()
            : null;
        if ($template === null) {
            throw ValidationException::withMessages(['format' => 'That format is not available. Pick one of the cards.']);
        }
        if (($reason = CreateFromFormat::blocker($workspace)) !== null) {
            throw ValidationException::withMessages(['workspace' => $reason]);
        }
        $settings = WorkspaceSettings::query()->findOrFail($workspace->id);
        $platform = CreateFromFormat::platform($platform, $template);
        $piece = self::piece($built);
        $idea = trim(str_replace("\r\n", "\n", (string) $idea));

        // The same piece sent again (a retry) lands on the same Review item, not a second one.
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'tantra:built:'.implode('|', [$workspace->id, $template->key, $platform, $idea, $piece['text']]))->toString();
        $engineBatchId = 'create-'.now()->format('Ymd');
        $engineId = 'TB'.substr(str_replace('-', '', $id), 0, 16);

        $post = array_filter([
            'engine_post_id' => $engineId,
            'profile' => (string) $settings->brand_profile_key,
            'platform' => $platform,
            'text' => $piece['text'],
            'first_comment' => $piece['first_comment'],
            'format' => $template->format,
            'short' => $piece['title'] ?? Str::limit($idea !== '' ? $idea : $piece['text'], 80, ''),
            'idea' => $idea !== '' ? Str::limit($idea, 500, '') : null,
            'sample' => ['id' => $template->key],
            'social_account_id' => self::account($workspace, $platform, $built['account'] ?? null),
        ], fn ($v): bool => $v !== null && $v !== '');

        $item = self::import($user, $workspace, $engineBatchId, $post, $piece['media'], $platform);

        $state = [
            'id' => $id,
            'status' => 'ready',
            'format' => $template->key,
            'format_title' => Str::limit((string) $template->title, 120, ''),
            'platform' => $platform,
            'idea' => Str::limit($idea, 280),
            'idea_full' => $idea,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'review_item_id' => $item['review_item_id'] ?? null,
            'title' => $post['short'] ?? null,
            'problem' => null,
            'built' => true,
            'created_at' => now()->toIso8601String(),
        ];
        CreateFromFormat::store($state);

        return $state;
    }

    /**
     * Replace the copy and the media of a post made in Create while it waits for review (a content_edit job). The same
     * Review item is updated: its revision goes up and its channel drafts are made again.
     *
     * @param  array<string, mixed>  $built  validated
     *
     * @throws ValidationException
     */
    public static function edit(User $user, ReviewItem $item, array $built): void
    {
        ReviewGate::assertReviewOwner($user, $item);
        if (! in_array($item->review_status, ReviewFeed::STATES['waiting'], true)) {
            throw ValidationException::withMessages(['review' => 'Only a post waiting for review can be edited.']);
        }
        if (! str_starts_with((string) $item->engine_batch_id, 'create-') || blank($item->engine_post_id)) {
            throw ValidationException::withMessages(['built' => 'This post was not made in Create, so only its text can be changed.']);
        }
        $workspace = $item->workspace;
        $profile = $workspace ? WorkspaceSettings::query()->find($workspace->id)?->brand_profile_key : null;
        if ($workspace === null || blank($profile)) {
            throw ValidationException::withMessages(['workspace' => 'This workspace is not ready for Create yet. Ask the Tantra team to finish setting it up.']);
        }
        $piece = self::piece($built);
        $platform = (string) $item->platform;
        $previous = (array) ($item->payload ?? []);

        $post = [
            ...array_diff_key($previous, array_flip(['first_comment', 'upload_tokens', 'media', 'built'])),
            'engine_post_id' => (string) $item->engine_post_id,
            'profile' => (string) $profile,
            'platform' => $platform,
            'text' => $piece['text'],
            'short' => $piece['title'] ?? $item->short ?? Str::limit($piece['text'], 80, ''),
        ];
        if ($piece['first_comment'] !== null) {
            $post['first_comment'] = $piece['first_comment'];
        }
        if (array_key_exists('account', $built)) {
            $account = self::account($workspace, $platform, $built['account']);
            unset($post['social_account_id']);
            if ($account !== null) {
                $post['social_account_id'] = $account;
            }
        }

        $result = self::import($user, $workspace, (string) $item->engine_batch_id, $post, $piece['media'], $platform);
        if (($result['outcome'] ?? null) === 'unchanged') {
            throw ValidationException::withMessages(['review' => 'This post cannot be changed any more: '.($result['note'] ?? 'it is locked').'.']);
        }
    }

    /**
     * The checked piece: copy cleaned the way Create cleans it (no en or em dashes), media as sent.
     *
     * @param  array<string, mixed>  $built
     * @return array{title: ?string, text: string, first_comment: ?string, media: array<string, mixed>}
     */
    private static function piece(array $built): array
    {
        $text = CreateFromFormat::clean((string) ($built['text'] ?? ''));
        if ($text === '') {
            throw ValidationException::withMessages(['built.text' => 'The post needs some text.']);
        }
        $title = Str::limit(CreateFromFormat::clean((string) ($built['title'] ?? '')), 80, '');
        $comment = CreateFromFormat::clean((string) ($built['first_comment'] ?? ''));

        return [
            'title' => $title !== '' ? $title : null,
            'text' => $text,
            'first_comment' => $comment !== '' ? $comment : null,
            'media' => (array) ($built['media'] ?? []),
        ];
    }

    /**
     * The channel the CRM named (a channel id, or its name or user name) in this workspace on this network, else null for
     * the network's first channel.
     */
    private static function account(Workspace $workspace, string $platform, mixed $asked): ?string
    {
        $asked = trim((string) $asked);
        if ($asked === '') {
            return null;
        }
        $channels = SocialAccount::query()->where('workspace_id', $workspace->id)->get();
        if (Str::isUuid($asked) && ($hit = $channels->firstWhere('id', $asked)) !== null) {
            return (string) $hit->id;
        }
        $onNetwork = $channels->filter(fn (SocialAccount $c): bool => $platform === 'x' ? $c->platform->value === 'x' : str_contains($c->platform->value, $platform));
        $needle = Str::lower(ltrim($asked, '@'));
        $hit = $onNetwork->first(fn (SocialAccount $c): bool => in_array($needle, [Str::lower((string) $c->display_name), Str::lower(ltrim((string) $c->username, '@'))], true));

        return $hit?->id !== null ? (string) $hit->id : null;
    }

    /**
     * Download the media and import the post into Review. Downloads are removed again when the import fails or leaves
     * the item unchanged.
     *
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $media
     * @return array<string, mixed> the ImportBatch result of the item
     */
    private static function import(User $user, Workspace $workspace, string $engineBatchId, array $post, array $media, string $platform): array
    {
        $files = self::files($media, $platform);
        $rows = self::download($workspace, $files);
        $type = in_array($media['type'] ?? null, self::TYPES, true) ? $media['type'] : 'text';

        $post = [
            ...$post,
            'media' => array_filter(['type' => $type, 'title' => $post['short'] ?? null]),
            'upload_tokens' => array_map(fn (Media $m): string => (string) $m->upload_token, $rows),
            // The record of what the CRM built, so Review can show the pages of a PDF carousel.
            'built' => array_filter([
                'type' => $type,
                'pages' => array_values(array_filter((array) ($media['pages'] ?? []), 'is_string')),
                'pdf' => $media['pdf'] ?? null,
                'mp4' => $media['mp4'] ?? null,
                'gif' => $media['gif'] ?? null,
                'image' => $media['image'] ?? null,
            ], fn ($v): bool => $v !== null && $v !== [] && $v !== ''),
        ];

        try {
            $result = ImportBatch::execute($user, ['engine_batch_id' => $engineBatchId, 'title' => 'Created in Tantra '.now()->format('j M'), 'posts' => [$post]]);
        } catch (Throwable $e) {
            self::forget($rows);

            throw $e;
        }
        $item = $result['items'][0] ?? [];
        if (($item['outcome'] ?? null) === 'unchanged') {
            self::forget($rows);
        }

        return $item;
    }

    /**
     * @param  list<array{url: string, kind: string}>  $files
     * @return list<Media>
     *
     * @throws ValidationException
     */
    private static function download(Workspace $workspace, array $files): array
    {
        if ($files === []) {
            return [];
        }
        $hosts = self::hosts();
        $fileCap = max(1, (int) config('tantra.built.max_file_mb', 100)) * 1024 * 1024;
        $totalCap = max(1, (int) config('tantra.built.max_total_mb', 300)) * 1024 * 1024;
        $importer = app(RemoteMediaImporter::class);
        $rows = [];
        $total = 0;

        foreach ($files as $index => $file) {
            $types = match ($file['kind']) {
                'pdf' => [MediaType::Document],
                'video' => [MediaType::Video],
                default => [MediaType::Image],
            };
            $problem = null;
            if (! self::urlAllowed($file['url'])) {
                $problem = 'Media must be an https address on an allowed host.';
            } else {
                $imported = $importer->import($workspace, new RemoteFile($file['url'], basename((string) parse_url($file['url'], PHP_URL_PATH)), allowedHosts: $hosts), $types, self::TIMEOUT_SECONDS);
                if ($imported->media === null) {
                    $problem = match ($imported->failure) {
                        ImportedFile::TYPE_NOT_ALLOWED => "File {$index} is not the kind of file the post needs ({$file['kind']}).",
                        ImportedFile::TOO_LARGE => "File {$index} is too large.",
                        ImportedFile::HOST_NOT_ALLOWED => 'Media must be an https address on an allowed host.',
                        default => "File {$index} could not be downloaded.",
                    };
                } else {
                    $rows[] = $imported->media;
                    $size = (int) $imported->media->size;
                    $total += $size;
                    if ($size > $fileCap) {
                        $problem = "File {$index} is too large.";
                    } elseif ($total > $totalCap) {
                        $problem = 'The media of this post is too large all together.';
                    } elseif ($file['kind'] === 'gif' && ! MediaType::isGif($imported->media->mime_type)) {
                        $problem = "File {$index} is not a GIF.";
                    }
                }
            }
            if ($problem !== null) {
                self::forget($rows);

                throw ValidationException::withMessages(['built.media' => $problem]);
            }
        }

        return $rows;
    }

    /** @param list<Media> $rows */
    private static function forget(array $rows): void
    {
        if ($rows !== []) {
            DB::transaction(fn () => DeleteOwnedMedia::forRows(array_map(fn (Media $m): string => (string) $m->id, $rows)));
        }
    }
}
