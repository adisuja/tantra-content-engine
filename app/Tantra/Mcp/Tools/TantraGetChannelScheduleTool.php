<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

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

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Read the posting days and times of a Tantra teammate\'s channel, in any workspace of the Account: its time zone, weekly goal and the seven days (day 0 is Sunday) with their local times. Approved posts go into these times. Open to the review owner of the channel\'s workspace and the Account owner.')]
class TantraGetChannelScheduleTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $channel = ChannelSchedules::find($request->user(), (string) $request->validate(['channel_id' => ['required', 'uuid']])['channel_id']);

        if (! $channel instanceof SocialAccount || ! ChannelSchedules::canEdit($request->user(), $channel)) {
            return Response::error('Channel not found.');
        }

        return Response::structured((new ChannelPostingScheduleResource($channel))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return ['channel_id' => $schema->string()->required()->description('UUID of the channel (social account).')];
    }
}
