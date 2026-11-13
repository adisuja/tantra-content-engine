<?php

declare(strict_types=1);

namespace App\Tantra\Http\Requests;

use App\Http\Requests\App\Channel\UpdatePostingScheduleRequest;
use App\Models\SocialAccount;
use App\Tantra\Channels\ChannelSchedules;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: upstream's posting schedule rules, for a channel in the Account that
 * the user reviews (or any channel, for the Account owner). PRD 25.6.
 */
class ChannelScheduleRequest extends UpdatePostingScheduleRequest
{
    private ?SocialAccount $channel = null;

    public function authorize(): bool
    {
        $this->channel = ChannelSchedules::find($this->user(), (string) $this->route('channel'));

        abort_if($this->channel === null, Response::HTTP_NOT_FOUND);

        return ChannelSchedules::canEdit($this->user(), $this->channel);
    }

    public function targetChannel(): SocialAccount
    {
        abort_if($this->channel === null, Response::HTTP_NOT_FOUND);

        return $this->channel;
    }
}
