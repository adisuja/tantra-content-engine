<?php

declare(strict_types=1);

namespace App\Tantra\Templates;

use App\Models\User;
use App\Tantra\Agent\AgentJob;
use App\Tantra\Agent\AgentJobs;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Unipile\UnipileClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * tantra: "Add from a post" (PRD 25.9). Makes a template in state building and
 * queues a template_from_post job. The Engine reads a LinkedIn post itself
 * through Unipile and hands the agent the post as data; the agent never holds
 * our Unipile key (review M7). Other networks go by their public address.
 */
final class TemplateIntake
{
    public static function start(User $by, string $url, ?string $note = null): ContentTemplate
    {
        $platform = ContentTemplate::platformFor($url);
        if ($platform === 'other') {
            throw ValidationException::withMessages(['url' => 'Paste the address of a post on LinkedIn, Facebook, Instagram, YouTube, X, TikTok, Threads, Bluesky or Pinterest.']);
        }
        $open = ContentTemplate::query()->where('account_id', $by->account_id)->where('source_url', $url)->whereIn('status', ['building', 'live'])->first();
        if ($open !== null) {
            return $open;
        }

        return DB::transaction(function () use ($by, $url, $note, $platform): ContentTemplate {
            $template = ContentTemplate::query()->create([
                'account_id' => $by->account_id, 'key' => self::nextKey(), 'title' => 'New template from '.ucfirst($platform),
                'platform' => $platform, 'source_url' => $url, 'note' => $note, 'status' => 'building', 'origin' => 'intake',
                'created_by' => $by->id,
            ]);
            $job = AgentJobs::enqueue('template_from_post', [
                'template_key' => $template->key, 'url' => $url, 'platform' => $platform, 'note' => $note,
                'post' => $platform === 'linkedin' ? self::linkedInPost($by, $url) : null,
            ], $by, null, $template);
            $template->forceFill(['agent_job_id' => $job->id])->save();

            return $template;
        });
    }

    /** What the person who pasted it sees: building, waiting for the team, live or failed. */
    public static function state(ContentTemplate $template): string
    {
        if ($template->status !== 'building') {
            return $template->status;
        }
        $job = $template->agent_job_id ? AgentJob::query()->find($template->agent_job_id) : null;

        return match ($job?->status) {
            AgentJob::AWAITING_ADMIN => 'waiting',
            AgentJob::FAILED, AgentJob::CANCELLED => 'failed',
            default => 'building',
        };
    }

    private static function nextKey(): string
    {
        $n = (int) ContentTemplate::query()->where('key', 'like', 'T-%')->count() + 1;
        while (ContentTemplate::query()->where('key', "T-{$n}")->exists()) {
            $n++;
        }

        return "T-{$n}";
    }

    /** @return array<string, mixed>|null the post's text, author and media, read through a teammate's Unipile account */
    private static function linkedInPost(User $by, string $url): ?array
    {
        if (! UnipileClient::configured() || ! preg_match('/(activity|ugcPost|share)[:-](\d{15,25})/', $url, $m)) {
            return null;
        }
        $account = MemberProfile::query()->whereKey($by->id)->value('unipile_account_id')
            ?? MemberProfile::query()->whereNotNull('unipile_account_id')->where('unipile_status', 'connected')->value('unipile_account_id');
        if ($account === null) {
            return null;
        }
        try {
            $post = app(UnipileClient::class)->postDetails((string) $account, "urn:li:{$m[1]}:{$m[2]}");
        } catch (Throwable) {
            return null;
        }
        if ($post === null) {
            return null;
        }

        return [
            'text' => $post['text'] ?? null,
            'author' => data_get($post, 'author.name') ?? data_get($post, 'author.display_name'),
            'author_headline' => data_get($post, 'author.headline') ?? data_get($post, 'author.description'),
            'created_at' => $post['created_at'] ?? $post['parsed_datetime'] ?? null,
            'reactions' => collect((array) ($post['reactions_counter'] ?? []))->sum('count') ?: ($post['reaction_counter'] ?? null),
            'comments' => $post['comments_counter'] ?? $post['comment_counter'] ?? null,
            'reposts' => $post['reposts_counter'] ?? $post['repost_counter'] ?? null,
            'attachments' => collect((array) ($post['attachments'] ?? []))->map(fn ($a) => [
                'type' => data_get($a, 'type'), 'mimetype' => data_get($a, 'mimetype'), 'url' => data_get($a, 'url'),
            ])->values()->all(),
        ];
    }
}
