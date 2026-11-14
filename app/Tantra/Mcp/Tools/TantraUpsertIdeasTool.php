<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Models\Idea;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Ideas\Networks;
use App\Tantra\Ideas\TantraIdeas;
use App\Tantra\Ideas\UpsertIdeas;
use App\Tantra\Mcp\TantraMcp;
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
#[Description('Create or update Tantra ideas by external_key (the same key never makes a second idea). Each idea names its workspace by profile or workspace_id (the current workspace when neither is given; only the Account owner can name another one), its platform and a preview (text, first_comment, thread, media {type, files, video, poster}) shown as a small phone on the Ideas board. A new idea goes to the workspace\'s first stage, or to the stage named in stage ("Batch 5", "Repurposed"), made when missing. An idea already in review or dropped is left alone and returned as skipped.')]
class TantraUpsertIdeasTool extends Tool
{
    use TantraMcp;

    public function handle(Request $request): Response|ResponseFactory
    {
        $rules = TantraIdeas::rules('ideas.*.');
        $rules['ideas.*.external_key'][] = 'distinct';
        $validated = $request->validate([
            'ideas' => ['required', 'array', 'min:1', 'max:'.TantraIdeas::MAX_BATCH],
            'ideas.*' => ['array'],
            ...$rules,
        ]);

        $workspace = $this->workspaceOf($request);
        if ($workspace === null || ! $request->user()->can('create', Idea::class)) {
            return Response::error('You cannot add ideas in this workspace.');
        }

        $results = [];
        if ($error = $this->attempt(function () use ($request, $validated, $workspace, &$results): void {
            $results = UpsertIdeas::execute($request->user(), $validated['ideas'], $workspace);
        })) {
            return $error;
        }

        return Response::structured(['data' => $results, 'counts' => collect($results)->countBy('outcome')->all()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ideas' => $schema->array()
                ->items($schema->object(fn ($idea) => [
                    'external_key' => $idea->string()->required()->description('Your stable key for this idea, unique in the Account (letters, digits, . _ : -).'),
                    'profile' => $idea->string()->description('Engine profile key of the workspace, for example "ben".'),
                    'workspace_id' => $idea->string()->description('Workspace UUID, instead of profile.'),
                    'stage' => $idea->string()->description('Stage name, for example "Batch 5" or "Repurposed". Omit for the first stage.'),
                    'title' => $idea->string()->description('Card title. Defaults to the hook.'),
                    'body' => $idea->string()->description('Card text. Defaults to the preview text.'),
                    'platform' => $idea->string()->enum(Networks::keys())->required()->description('The network the post is for.'),
                    'channel_id' => $idea->string()->description('The channel to post from, when the workspace has several for that network.'),
                    'format' => $idea->string()->description('Format, for example "carousel" or "short".'),
                    'template_key' => $idea->string()->description('The sample or template it follows.'),
                    'hook' => $idea->string()->description('The opening line.'),
                    'preview' => $idea->object()->description('{text, title, first_comment, thread: [string], media: {type: text|image|images|carousel|document|video|poll|pending, title, files: [https link or tantra/previews/ path], video, poster, tall}}.'),
                    'upload_tokens' => $idea->array()->items($idea->string())->description('Full-quality files from POST /api/tantra/uploads in this workspace, used for the draft on Send to review.'),
                    'kind' => $idea->string()->enum(IdeaMeta::KINDS)->description('new (default), repurpose or repost.'),
                    'source_review_item_id' => $idea->string()->description('The review item it was repurposed from.'),
                    'source_post_id' => $idea->string()->description('The post it was repurposed from.'),
                    'cadence_week' => $idea->integer()->description('Repurposing cadence week.'),
                    'target_date' => $idea->string()->description('Planned day, Y-m-d.'),
                    'agent_job_id' => $idea->string()->description('The agent job that wrote it.'),
                    'state' => $idea->string()->enum([IdeaMeta::STATE_IDEA, IdeaMeta::STATE_DROPPED])->description('idea, or dropped to set it aside.'),
                ]))
                ->required()
                ->description('Up to 200 ideas.'),
        ];
    }
}
