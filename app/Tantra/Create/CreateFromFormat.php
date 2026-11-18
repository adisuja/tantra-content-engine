<?php

declare(strict_types=1);

namespace App\Tantra\Create;

use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Ai\AiUnavailableException;
use App\Tantra\Ai\TantraAi;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Templates\ContentTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * tantra: Content > Create in Tantra (AF-09, PRD 9.22). The person picks a format card and gives a content idea; the
 * engine writes the post end to end (FormatPostWriter on the Account's AI, TantraAi) and pushes the finished piece to
 * Review through the batch import (engine batch "create-<date>"), where it waits for approval like any other post.
 * Nothing is published here.
 *
 * A piece is tracked in the cache for a day: creating, then ready (with its review item) or failed (with the reason in
 * words). The writing runs on the queue (CreatePiece), so the request that starts it answers at once.
 */
final class CreateFromFormat
{
    public const TTL_SECONDS = 86400;

    public const RECENT = 20;

    public const PLATFORMS = ['linkedin', 'x', 'facebook', 'instagram', 'threads', 'bluesky', 'tiktok', 'youtube', 'pinterest'];

    private const LABELS = ['linkedin' => 'LinkedIn', 'x' => 'X', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'threads' => 'Threads', 'bluesky' => 'Bluesky', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'pinterest' => 'Pinterest'];

    /**
     * Why Create cannot run for this workspace yet, in words, or null when it can.
     */
    public static function blocker(Workspace $workspace): ?string
    {
        if (blank(WorkspaceSettings::query()->find($workspace->id)?->brand_profile_key)) {
            return 'This workspace is not ready for Create yet. Ask the Tantra team to finish setting it up.';
        }

        return null;
    }

    /**
     * Start one piece. Returns its state (status "creating").
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function start(User $user, Workspace $workspace, string $formatKey, string $idea, ?string $platform = null): array
    {
        $idea = trim(str_replace("\r\n", "\n", $idea));
        if (mb_strlen($idea) < 3) {
            throw ValidationException::withMessages(['idea' => 'Say in a few words what the post is about.']);
        }
        if (mb_strlen($idea) > 2000) {
            throw ValidationException::withMessages(['idea' => 'Keep the idea under 2,000 characters.']);
        }
        $template = preg_match(FormatCards::KEY, $formatKey) === 1
            ? ContentTemplate::visibleTo($user->account_id)->where('status', 'live')->where('key', $formatKey)->first()
            : null;
        if ($template === null) {
            throw ValidationException::withMessages(['format' => 'That format is not available. Pick one of the cards.']);
        }
        if (($reason = self::blocker($workspace)) !== null) {
            throw ValidationException::withMessages(['workspace' => $reason]);
        }
        $platform = self::platform($platform, $template);

        $id = (string) Str::uuid();
        $state = [
            'id' => $id,
            'status' => 'creating',
            'format' => $template->key,
            'format_title' => Str::limit((string) $template->title, 120, ''),
            'platform' => $platform,
            'idea' => Str::limit($idea, 280),
            'idea_full' => $idea,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'review_item_id' => null,
            'title' => null,
            'problem' => null,
            'created_at' => now()->toIso8601String(),
        ];
        self::put($state);
        self::remember($workspace->id, $id);
        CreatePiece::dispatch($id);

        return $state;
    }

    /** The piece's network: the one asked for, else the format's own, else LinkedIn. */
    public static function platform(?string $asked, ContentTemplate $template): string
    {
        $asked = strtolower(trim((string) $asked));
        if (in_array($asked, self::PLATFORMS, true)) {
            return $asked;
        }

        return in_array($template->platform, self::PLATFORMS, true) ? $template->platform : 'linkedin';
    }

    /** Write the piece and push it to Review. Runs once per piece; a piece that is not creating any more is left alone. */
    public static function run(string $id): void
    {
        $state = self::state($id);
        if ($state === null || $state['status'] !== 'creating') {
            return;
        }
        $user = User::query()->find($state['user_id']);
        $workspace = Workspace::query()->find($state['workspace_id']);
        $template = $user === null ? null : ContentTemplate::visibleTo($user->account_id)->where('key', $state['format'])->first();
        if ($user === null || $workspace === null || $template === null) {
            self::put([...$state, 'status' => 'failed', 'problem' => 'The format or the workspace is not there any more.']);

            return;
        }

        try {
            $platform = (string) $state['platform'];
            $response = TantraAi::prompt(new FormatPostWriter($template, self::LABELS[$platform] ?? 'LinkedIn'), "The idea:\n\n".$state['idea_full'], $user->account_id, task: 'content.assist');
            $text = self::clean((string) ($response['text'] ?? ''));
            if ($text === '') {
                throw new RuntimeException('The AI gave no text.');
            }
            $title = Str::limit(self::clean((string) ($response['title'] ?? '')) ?: Str::limit($state['idea_full'], 80, ''), 80, '');
            $comment = self::clean((string) ($response['first_comment'] ?? ''));
            $settings = WorkspaceSettings::query()->findOrFail($workspace->id);

            $result = ImportBatch::execute($user, [
                'engine_batch_id' => 'create-'.now()->format('Ymd'),
                'title' => 'Created in Tantra '.now()->format('j M'),
                'posts' => [array_filter([
                    'engine_post_id' => 'TC'.substr(str_replace('-', '', $id), 0, 16),
                    'profile' => (string) $settings->brand_profile_key,
                    'platform' => $platform,
                    'text' => $text,
                    'first_comment' => $comment !== '' ? $comment : null,
                    'format' => $template->format,
                    'short' => $title,
                    'idea' => Str::limit($state['idea_full'], 500, ''),
                    'sample' => ['id' => $template->key],
                ], fn ($v): bool => $v !== null && $v !== '')],
            ]);

            self::put([...$state, 'status' => 'ready', 'title' => $title, 'review_item_id' => $result['items'][0]['review_item_id'] ?? null]);
        } catch (AiUnavailableException) {
            self::put([...$state, 'status' => 'failed', 'problem' => 'No AI is connected for Content yet, so the piece could not be written. Ask the Tantra team to connect one.']);
        } catch (ValidationException $e) {
            self::put([...$state, 'status' => 'failed', 'problem' => (string) collect($e->errors())->flatten()->first()]);
        } catch (Throwable $e) {
            report($e);
            self::put([...$state, 'status' => 'failed', 'problem' => 'The piece could not be written. Try again in a minute.']);
        }
    }

    /** @return array<string, mixed>|null */
    public static function state(string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $state = Cache::get("tantra:create:{$id}");

        return is_array($state) ? $state : null;
    }

    /**
     * The workspace's recent pieces, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(string $workspaceId): array
    {
        $ids = (array) Cache::get("tantra:create:recent:{$workspaceId}", []);

        return array_values(array_filter(array_map(fn ($id): ?array => is_string($id) ? self::state($id) : null, $ids)));
    }

    /**
     * A piece as the Tantra app sees it: no user, workspace or engine ids.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function present(array $state): array
    {
        return [
            'ref' => $state['id'],
            'status' => $state['status'],
            'format' => $state['format'],
            'format_title' => $state['format_title'],
            'platform' => $state['platform'],
            'idea' => $state['idea'],
            'title' => $state['title'],
            'problem' => $state['problem'],
            'review_ref' => $state['review_item_id'],
            'created_at' => $state['created_at'],
        ];
    }

    /** The post's text with Windows line ends, control characters and en or em dashes taken out. */
    public static function clean(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace('/(\d)\s*\x{2013}\s*(\d)/u', '$1 to $2', $text) ?? $text;
        $text = preg_replace('/\s*[\x{2013}\x{2014}]\s*/u', ', ', $text) ?? $text;
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

        return trim($text);
    }

    /** @param array<string, mixed> $state */
    private static function put(array $state): void
    {
        Cache::put("tantra:create:{$state['id']}", $state, self::TTL_SECONDS);
    }

    private static function remember(string $workspaceId, string $id): void
    {
        $key = "tantra:create:recent:{$workspaceId}";
        $ids = array_values(array_filter((array) Cache::get($key, []), 'is_string'));
        array_unshift($ids, $id);
        Cache::put($key, array_slice(array_values(array_unique($ids)), 0, self::RECENT), self::TTL_SECONDS);
    }
}
