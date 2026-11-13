<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ChannelPostingScheduleResource;
use App\Tantra\Channels\ChannelSchedules;
use App\Tantra\Http\Requests\ChannelScheduleRequest;
use Illuminate\Http\Request;

/**
 * tantra: any channel's posting days, times and timezone in the Account, with
 * the Account owner's API key (PRD 25.6). Upstream's endpoints are bound to one
 * workspace; these reach every teammate's channels.
 */
class ChannelScheduleController extends Controller
{
    public function show(Request $request, string $channel): ChannelPostingScheduleResource
    {
        $account = ChannelSchedules::find($request->user(), $channel);

        abort_if($account === null, 404);
        abort_unless(ChannelSchedules::canEdit($request->user(), $account), 403);

        return ChannelPostingScheduleResource::make($account);
    }

    public function update(ChannelScheduleRequest $request): ChannelPostingScheduleResource
    {
        return ChannelPostingScheduleResource::make(ChannelSchedules::update($request->targetChannel(), $request->validated()));
    }
}
