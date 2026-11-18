<?php

declare(strict_types=1);

namespace App\Tantra\Create;

use App\Tantra\Templates\ContentTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * tantra: writes one finished post from a format card and a content idea (AF-09, Content > Create). The template's
 * spec, copy template and sample are the format; the idea is what the post is about. The answer goes to Review as
 * it is, so it must be ready to publish: no placeholders, no invented numbers.
 */
#[Temperature(0.7)]
class FormatPostWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(public ContentTemplate $template, public string $platform) {}

    public function instructions(): string
    {
        $t = $this->template;
        $spec = Str::limit(trim((string) $t->spec), 12000);
        $copy = Str::limit(trim((string) $t->copy_template), 4000);
        $sample = Str::limit(trim((string) $t->sample_md), 6000);
        $about = trim((string) ($t->description ?? $t->archetype ?? ''));

        return <<<PROMPT
        You write one social media post for {$this->platform}, following a content format exactly, about the idea the person gives you.

        The format: {$t->title} ({$t->format})
        {$about}

        How the format works:
        {$spec}

        Copy template:
        {$copy}

        A sample in this format:
        {$sample}

        Rules:
        - Follow the format's structure, length and rhythm; the idea decides the content.
        - The post is finished and ready to publish: no placeholders, no brackets to fill in, no notes to the writer.
        - Never invent numbers, names, clients or results the idea does not give.
        - Plain words. No em dashes or en dashes. No hashtags unless the format uses them.
        - first_comment is optional: only when the format puts a link or a follow-up in the first comment.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('A short name for the post, at most 80 characters.'),
            'text' => $schema->string()->required()->description('The whole post, ready to publish.'),
            'first_comment' => $schema->string()->description('The first comment, when the format uses one.'),
        ];
    }
}
