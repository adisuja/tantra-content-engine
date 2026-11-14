<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Tantra\Digest\DailyDigest;
use App\Tantra\Digest\DigestReport;
use App\Tantra\Notify\Notify;
use App\Tantra\Review\ReviewGate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * tantra: once a day, at the digest hour in each person's own timezone, email
 * every workspace member who has something to review or something to report.
 * Runs hourly; the sent-on date keeps it to one a day.
 */
class DailyDigestCommand extends Command
{
    protected $signature = 'tantra:daily-digest {--user= : Only this user id or email} {--force : Ignore the hour and today\'s send} {--dry : Show what would be sent}';

    protected $description = 'Send the daily digest to members whose local digest hour it is';

    public function handle(): int
    {
        $hour = (int) config('tantra.digest.hour', 8);
        $ids = DB::table('user_workspace')->distinct()->pluck('user_id');
        $users = User::query()->whereIn('id', $ids)
            ->when($this->option('user'), fn ($q, $who) => $q->where(fn ($w) => $w->where('id', $who)->orWhere('email', $who)))
            ->get();
        $sent = 0;

        foreach ($users as $user) {
            $local = CarbonImmutable::now($user->timezone ?: 'UTC');
            $preference = NotificationPreference::query()->firstOrCreate(['user_id' => $user->id]);
            if (! $this->option('force') && ($local->hour !== $hour || $preference->daily_digest_sent_on?->toDateString() === $local->toDateString())) {
                continue;
            }
            if (! ($preference->daily_digest ?? true) || ! (ReviewGate::hasJoined($user) || filled($user->getAttributes()['clerk_user_id'] ?? null))) {
                continue; // not joined yet: their invite email does the talking
            }

            $stats = self::stats(DigestReport::for($user), $local);
            if ($stats['waiting'] === 0 && $stats['published_24h'] === 0 && $stats['scheduled_7d'] === 0 && $stats['views_delta'] <= 0) {
                continue; // nothing to review and nothing to report
            }

            $mail = new DailyDigest(
                (string) (strtok((string) $user->name, ' ') ?: $user->name),
                $stats,
                URL::signedRoute('tantra.digest.unsubscribe', ['user' => $user->id]),
                $user->timezone,
            );
            $this->line(($this->option('dry') ? 'would send' : 'send')." {$user->email}: ".$mail->envelope()->subject);
            if (! $this->option('dry')) {
                Notify::send($user, 'daily_digest', array_diff_key($stats, ['item_ids' => true]), $mail, null, url('/tantra/review'), 'Open Tantra');
                $preference->forceFill(['daily_digest_sent_on' => $local->toDateString()])->save();
            }
            $sent++;
        }

        $this->info("{$sent} digest(s)".($this->option('dry') ? ' to send' : ' sent'));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    public static function stats(DigestReport $report, CarbonImmutable $local): array
    {
        $waiting = $report->waitingForReview();
        $today = $report->viewsByNetwork();
        $yesterday = $report->viewsByNetwork($report->now->subDay());
        $publishedAll = $report->publishedSince(null);

        return [
            'waiting' => $waiting->count(),
            'item_ids' => $waiting->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            'published_24h' => $report->publishedSince($report->now->subDay()),
            'published_all' => $publishedAll,
            'scheduled_7d' => $report->scheduledNextDays(7),
            'views' => array_sum($today),
            'views_delta' => array_sum($today) - array_sum($yesterday),
            'networks' => $today,
            'standout' => $report->standout($publishedAll),
            'date' => $local->format('l j F'),
        ];
    }
}
