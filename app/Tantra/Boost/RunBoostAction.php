<?php

declare(strict_types=1);

namespace App\Tantra\Boost;

use App\Tantra\Unipile\UnipileClient;
use App\Tantra\Unipile\UnipileException;
use App\Tantra\Zernio\ZernioClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * tantra: posts one reaction, comment or reply from the person's own Unipile
 * account (PRD 10.5), or, on a Tantra page post, the page's own reply through Zernio. Each action posts at most once; a failure is retried once
 * after 15 seconds, then recorded. A restriction pauses that account for 72 hours.
 */
class RunBoostAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 12;

    public function __construct(public string $actionId)
    {
        $this->onQueue(Boost::QUEUE);
    }

    public function handle(UnipileClient $unipile, ?ZernioClient $zernio = null): void
    {
        $action = BoostAction::query()->with('run.post')->find($this->actionId);
        if ($action === null || $action->status !== 'queued') {
            return;
        }
        if (! Boost::enabled()) {
            $this->settle($action, 'skipped', 'boosting is switched off');

            return;
        }
        if ($action->via === 'zernio') {
            $this->asPage($action, $zernio ?? app(ZernioClient::class));

            return;
        }

        $profile = $action->user_id ? MemberProfile::query()->find($action->user_id) : null;
        if ($profile === null || ($blocker = $profile->blocker()) !== null) {
            $this->settle($action, 'skipped', $blocker ?? 'no member profile');

            return;
        }

        $postedToday = BoostAction::query()->where('unipile_account_id', $action->unipile_account_id)
            ->where('status', 'posted')->where('kind', '!=', 'reaction')->where('posted_at', '>=', now()->startOfDay())->count();
        if ($action->kind !== 'reaction' && $postedToday >= $profile->daily_boost_cap) {
            $this->settle($action, 'skipped', 'daily cap reached');

            return;
        }

        $run = $action->run;
        $postRef = $run->unipile_post_ref ?: $this->lookup($unipile, $action);
        if ($postRef === null) {
            // LinkedIn can take a minute to make a new post readable: try again for up to two minutes.
            if ($this->attempts() < 12) {
                $this->release(10);
            } else {
                $this->settle($action, 'failed', 'the post could not be found on LinkedIn');
            }

            return;
        }

        $parentRef = null;
        if ($action->kind === 'reply') {
            $parent = $action->parent;
            if ($parent === null || in_array($parent->status, ['skipped', 'failed'], true)) {
                $this->settle($action, 'skipped', 'its comment was not posted');

                return;
            }
            if ($parent->status !== 'posted') {
                $this->release(30);

                return;
            }
            $parentRef = $parent->unipile_ref;
        }

        try {
            $ref = match ($action->kind) {
                'reaction' => (string) ($unipile->react($action->unipile_account_id, $postRef, $action->reaction_type ?: 'linkedin_like')->json('id') ?? 'reaction'),
                'comment' => $unipile->comment($action->unipile_account_id, $postRef, (string) $action->text),
                'reply' => $unipile->reply($action->unipile_account_id, $postRef, (string) $parentRef, (string) $action->text),
            };
            $action->forceFill(['status' => 'posted', 'unipile_ref' => $ref, 'posted_at' => now(), 'error' => null])->save();
        } catch (UnipileException $e) {
            if ($e->isRestriction()) {
                $profile->forceFill(['boost_paused_until' => now()->addHours(72)])->save();
                $this->settle($action, 'failed', 'LinkedIn restricted the account; boosts from it are paused for 72 hours');

                return;
            }
            $this->retryOnce($action, $e);
        } catch (Throwable $e) {
            $this->retryOnce($action, $e);
        }

        $this->finishRun($run);
    }

    /** The Tantra page answers on its own post through Zernio, in the comment's thread. */
    private function asPage(BoostAction $action, ZernioClient $zernio): void
    {
        $run = $action->run;
        $post = $run->post;
        $urn = $post ? Boost::nativeRef($post) : null;
        $accountId = (string) $post?->socialAccount?->platform_user_id;
        if ($urn === null || $accountId === '') {
            $this->settle($action, 'failed', 'the page post has no LinkedIn id yet');

            return;
        }

        $commentUrn = null;
        if ($action->kind === 'reply') {
            $parent = $action->parent;
            if ($parent === null || in_array($parent->status, ['skipped', 'failed'], true)) {
                $this->settle($action, 'skipped', 'its comment was not posted');

                return;
            }
            if ($parent->status !== 'posted') {
                $this->release(30);

                return;
            }
            $commentUrn = ZernioClient::commentUrn($urn, (string) $parent->unipile_ref);
        }

        $response = $zernio->commentAsPage($accountId, $urn, $commentUrn, (string) $action->text, 'tantra-boost-'.$action->id);
        if ($response->successful() && $response->json('success') !== false) {
            $action->forceFill(['status' => 'posted', 'unipile_ref' => (string) $response->json('data.commentId'), 'posted_at' => now(), 'error' => null])->save();
            $this->finishRun($run);

            return;
        }

        $this->retryOnce($action, new RuntimeException('Zernio answered '.$response->status().': '.mb_substr((string) $response->body(), 0, 200)));
        $this->finishRun($run);
    }

    private function lookup(UnipileClient $unipile, BoostAction $action): ?string
    {
        $native = Boost::nativeRef($action->run->post);
        $ref = $native ? $unipile->postId($action->unipile_account_id, $native) : null;
        if ($ref !== null) {
            $action->run->forceFill(['unipile_post_ref' => $ref])->save();
        }

        return $ref;
    }

    private function retryOnce(BoostAction $action, Throwable $e): void
    {
        if (! $action->getAttribute('error') || ! str_starts_with((string) $action->error, 'retry:')) {
            $action->forceFill(['error' => 'retry: '.mb_substr($e->getMessage(), 0, 200)])->save();
            $this->release(15);

            return;
        }

        $this->settle($action, 'failed', mb_substr($e->getMessage(), 0, 300));
    }

    private function settle(BoostAction $action, string $status, string $reason): void
    {
        $action->forceFill(['status' => $status, 'error' => $reason])->save();
        $this->finishRun($action->run);
    }

    private function finishRun(BoostRun $run): void
    {
        $open = $run->actions()->where('status', 'queued')->exists();
        if ($open || $run->status !== 'running') {
            return;
        }

        $failed = $run->actions()->whereIn('status', ['failed'])->exists();
        $run->forceFill(['status' => $failed ? 'partial' : 'done'])->save();
    }
}
