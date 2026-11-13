<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\ChannelPostingScheduleResource;
use App\Models\SocialAccount;
use App\Tantra\Channels\ChannelSchedules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
#[Description('Set the posting days and times of a Tantra teammate\'s channel, as on the Team page: its time zone and the seven days (day 0 is Sunday), each switched on or off with at most 4 unique HH:mm times in the channel time zone. A goal or schedule left out stays as it is; a null posting_schedule clears it. Queued posts move to the new times. Open to the review owner of the channel\'s workspace and the Account owner.')]
class TantraUpdateChannelScheduleTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $channel = ChannelSchedules::find($request->user(), (string) $request->validate(['channel_id' => ['required', 'uuid']])['channel_id']);

        if (! $channel instanceof SocialAccount || ! ChannelSchedules::canEdit($request->user(), $channel)) {
            return Response::error('Channel not found.');
        }

        $validated = $request->validate(ChannelSchedules::rules($request->all()));

        try {
            $channel = ChannelSchedules::update($channel, $validated);
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

        return Response::structured((new ChannelPostingScheduleResource($channel))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'channel_id' => $schema->string()->required()->description('UUID of the channel (social account).'),
            'timezone' => $schema->string()->required()->description('IANA time zone of the channel, e.g. America/New_York.'),
            'posting_goal' => $schema->integer()->nullable()->description('Weekly posting goal, 1 to 28 posts, or null. Left out, it stays as it is.'),
            'posting_schedule' => $schema->array()->nullable()->items($schema->object([
                'day' => $schema->integer()->required()->description('0 (Sunday) to 6 (Saturday).'),
                'enabled' => $schema->boolean()->required(),
                'times' => $schema->array()->items($schema->string())->required()->description('At most 4 unique HH:mm times of that day, in the channel time zone.'),
            ]))->description('Exactly seven days, or null to clear the schedule. Left out, it stays as it is.'),
        ];
    }
}
