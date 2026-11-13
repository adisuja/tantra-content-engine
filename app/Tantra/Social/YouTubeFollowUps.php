<?php

declare(strict_types=1);

namespace App\Tantra\Social;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Http\SafeHttpFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * tantra: what YouTube takes after a video is uploaded: adding it to a
 * playlist (playlistItems.insert), the custom thumbnail (thumbnails.set) and
 * the first comment (commentThreads.insert, scope youtube.force-ssl).
 *
 * The video is live by then, so none of these ever fails the post: a failure
 * is reported, logged and flagged on the post (`youtube_followup_failed`,
 * `first_comment_failed`).
 */
final class YouTubeFollowUps
{
    use PublishesFirstComments;

    public const string API = 'https://www.googleapis.com/youtube/v3';

    public const string UPLOAD_API = 'https://www.googleapis.com/upload/youtube/v3';

    /** YouTube refuses a thumbnail over 2 MB. */
    private const int THUMBNAIL_MAX_BYTES = 2 * 1024 * 1024;

    /** What thumbnails.set accepts. */
    private const array THUMBNAIL_TYPES = ['image/jpeg', 'image/png'];

    /**
     * @param  array<string, mixed>  $result  the uploaded video: {id, url}
     * @return array<string, mixed>
     */
    public function run(Post $post, array $result): array
    {
        $videoId = (string) ($result['id'] ?? '');
        $account = $post->socialAccount;

        if ($videoId === '' || $account === null) {
            return $result;
        }

        if (filled($playlist = data_get($post->meta, 'playlist_id'))) {
            $this->attempt($post, 'playlist', fn () => $this->addToPlaylist($account, $videoId, (string) $playlist));
        }

        if (filled($thumbnail = data_get($post->meta, 'thumbnail_url'))) {
            $this->attempt($post, 'thumbnail', fn () => $this->setThumbnail($account, $videoId, (string) $thumbnail));
        }

        return $this->withFirstComments($post, $result, fn (string $text): array => [
            'id' => $this->comment($account, $videoId, $text),
        ]);
    }

    /**
     * The channel's playlists for the composer's picker, newest first.
     *
     * @return list<array{id: string, title: string}>
     */
    public static function playlists(SocialAccount $account): array
    {
        $response = Http::withToken((string) $account->access_token)->get(self::API.'/playlists', [
            'part' => 'snippet',
            'mine' => 'true',
            'maxResults' => 50,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('YouTube playlists could not be read: '.$response->status());
        }

        return collect((array) $response->json('items', []))
            ->map(fn (mixed $item): array => [
                'id' => (string) data_get($item, 'id'),
                'title' => (string) data_get($item, 'snippet.title', ''),
            ])
            ->filter(fn (array $playlist): bool => $playlist['id'] !== '')
            ->values()
            ->all();
    }

    private function addToPlaylist(SocialAccount $account, string $videoId, string $playlistId): void
    {
        $response = Http::withToken((string) $account->access_token)->post(self::API.'/playlistItems?part=snippet', [
            'snippet' => [
                'playlistId' => $playlistId,
                'resourceId' => ['kind' => 'youtube#video', 'videoId' => $videoId],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('YouTube refused the playlist: '.$response->status().' '.$response->body());
        }
    }

    private function setThumbnail(SocialAccount $account, string $videoId, string $url): void
    {
        // The link is typed by a person: fetched through the SSRF guard (no
        // internal addresses), capped at 2 MB, and only a JPEG or PNG is sent.
        $bytes = app(SafeHttpFetcher::class)->tryGetBody($url, self::THUMBNAIL_MAX_BYTES);

        if ($bytes === null || $bytes === '') {
            throw new RuntimeException('The thumbnail image could not be read, is not public, or is over 2 MB.');
        }

        $mime = (string) finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $bytes);

        if (! in_array($mime, self::THUMBNAIL_TYPES, true)) {
            throw new RuntimeException("The thumbnail must be a JPEG or PNG image, not {$mime}.");
        }

        $response = Http::withToken((string) $account->access_token)
            ->withBody($bytes, $mime)
            ->post(self::UPLOAD_API.'/thumbnails/set?videoId='.rawurlencode($videoId));

        if ($response->failed()) {
            throw new RuntimeException('YouTube refused the thumbnail: '.$response->status().' '.$response->body());
        }
    }

    private function comment(SocialAccount $account, string $videoId, string $text): string
    {
        $response = Http::withToken((string) $account->access_token)->post(self::API.'/commentThreads?part=snippet', [
            'snippet' => [
                'videoId' => $videoId,
                'topLevelComment' => ['snippet' => ['textOriginal' => $text]],
            ],
        ]);

        $id = $response->json('id');

        if ($response->failed() || ! is_string($id) || $id === '') {
            throw new RuntimeException('YouTube refused the first comment: '.$response->status().' '.$response->body());
        }

        return $id;
    }

    private function attempt(Post $post, string $step, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
            Log::warning('tantra: YouTube follow-up failed', ['post_id' => $post->id, 'step' => $step, 'error' => $e->getMessage()]);
            $post->forceFill(['meta' => [...((array) $post->meta), 'youtube_followup_failed' => $step]])->saveQuietly();
        }
    }
}
