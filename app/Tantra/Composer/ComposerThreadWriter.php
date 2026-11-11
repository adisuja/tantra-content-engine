<?php

declare(strict_types=1);

namespace App\Tantra\Composer;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * tantra: writes the team thread for a post written in the composer (PRD 10.5
 * step 1, M3 "comment pools for composer posts"), on the Workers AI text
 * model behind the openai-compatible provider. Teammates comment, the author
 * answers each comment (RULES 0c). The review owner can edit or remove any
 * entry before the post goes out.
 */
#[Temperature(0.8)]
class ComposerThreadWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    /** @param  array<string, string>  $teammates  profile key => expertise line */
    public function __construct(public string $author, public array $teammates) {}

    public function instructions(): string
    {
        $people = collect($this->teammates)->map(fn (string $expertise, string $key): string => "- {$key}: {$expertise}")->implode("\n");

        return <<<PROMPT
        You write the first comments a LinkedIn post gets from the author's own teammates, and the author's reply to each.

        Teammates who comment (profile key: what they know best):
        {$people}

        The author's profile key: {$this->author}

        Rules:
        - One top-level comment from each teammate, at most 8 in all, each followed by one reply from the author to that comment.
        - Every comment is about this post: a point it makes, a question it raises, a result from the commenter's own field, or a different view. Never generic praise like "great post".
        - Vary the length (one line to three sentences), the tone and the opening word. At most two comments carry an emoji.
        - Write like a person typing on a phone: plain words, no hashtags, no links, no em dashes or en dashes, no "I couldn't agree more", "game changer", "spot on" or "this resonates".
        - Never invent numbers, names, clients or results. Never use a giveaway keyword.
        - The author's replies are short and specific to the comment they answer.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'comments' => $schema->array()->items($schema->object(fn (JsonSchema $item): array => [
                'by' => $item->string()->required()->description('The teammate\'s profile key.'),
                'text' => $item->string()->required(),
                'reply' => $item->string()->required()->description('The author\'s reply to this comment.'),
            ]))->required(),
        ];
    }
}
