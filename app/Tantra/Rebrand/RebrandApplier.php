<?php

declare(strict_types=1);

namespace App\Tantra\Rebrand;

use App\Tantra\Agent\AgentJob;
use App\Tantra\Agent\InvalidResult;
use App\Tantra\Agent\JobApplier;
use App\Tantra\Notify\Notify;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/** tantra: stores a rebrand proposal for the person to look at and approve (PRD 25.11). */
final class RebrandApplier implements JobApplier
{
    public function apply(AgentJob $job, array $result): string
    {
        $rebrand = $job->subject;
        if (! $rebrand instanceof Rebrand) {
            throw new InvalidResult('This job has no rebrand to fill.');
        }
        $v = Validator::make($result, [
            'headline_options' => ['required', 'array', 'min:1', 'max:5'],
            'headline_options.*' => ['string', 'max:220'],
            'about' => ['required', 'string', 'max:2600'],
            'banner' => ['nullable', 'array'],
            'banner.brief' => ['nullable', 'string', 'max:1500'],
            'banner.text' => ['nullable', 'string', 'max:120'],
            'banner.image_base64' => ['nullable', 'string', 'max:8000000'],
            'featured' => ['nullable', 'array', 'max:6'],
            'featured.*.title' => ['required', 'string', 'max:200'],
            'featured.*.why' => ['nullable', 'string', 'max:300'],
            'experience' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($v->fails()) {
            throw new InvalidResult($v->errors()->first());
        }
        foreach ([...$result['headline_options'], $result['about']] as $text) {
            if (preg_match('/[\x{2013}\x{2014}]/u', (string) $text)) {
                throw new InvalidResult('No em or en dashes, please (house rule).');
            }
        }

        $banner = null;
        $image = data_get($result, 'banner.image_base64');
        if (is_string($image) && $image !== '') {
            $bytes = base64_decode((string) preg_replace('/^data:image\/\w+;base64,/', '', $image), true);
            if ($bytes !== false && in_array((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes), ['image/png', 'image/jpeg'], true)) {
                $banner = "tantra/rebrands/{$rebrand->id}/banner.png";
                Storage::put($banner, $bytes, 'public');
            }
        }
        $proposal = $result;
        unset($proposal['banner']['image_base64']);
        $rebrand->forceFill(['proposal' => $proposal, 'status' => 'proposed', 'banner_path' => $banner ?? $rebrand->banner_path])->save();

        if ($rebrand->user !== null) {
            Notify::send($rebrand->user, 'rebrand_ready', ['subject' => 'Your LinkedIn rebrand is ready to look at', 'text' => 'A new headline, About section and banner are waiting for you on the Team page. Nothing changes on LinkedIn until you apply it.'], null, null, url('/tantra/team'), 'Open the Team page');
        }

        return 'Rebrand ready for '.($rebrand->user?->name ?? 'them').' to look at.';
    }
}
