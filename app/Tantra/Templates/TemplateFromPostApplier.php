<?php

declare(strict_types=1);

namespace App\Tantra\Templates;

use App\Tantra\Agent\AgentJob;
use App\Tantra\Agent\InvalidResult;
use App\Tantra\Agent\JobApplier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * tantra: turns a template_from_post result into a live template (PRD 25.9).
 * Files go under tantra/templates/<key>/, which the job purge never touches
 * (review M8). Applied twice, it writes the same template once.
 */
final class TemplateFromPostApplier implements JobApplier
{
    public function apply(AgentJob $job, array $result): string
    {
        $template = $job->subject;
        if (! $template instanceof ContentTemplate) {
            throw new InvalidResult('This job has no template to fill.');
        }

        $v = Validator::make($result, [
            'title' => ['required', 'string', 'max:200'],
            'format' => ['required', 'string', 'max:80'],
            'platform' => ['nullable', Rule::in(ContentTemplate::PLATFORMS)],
            'family' => ['nullable', 'string', 'max:40'],
            'archetype' => ['nullable', 'string', 'max:300'],
            'creator' => ['nullable', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:3000'],
            'spec' => ['required', 'string', 'max:60000'],
            'copy_template' => ['nullable', 'string', 'max:8000'],
            'palette' => ['nullable', 'array', 'max:12'],
            'palette.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sample_md' => ['required', 'string', 'max:120000'],
            'preview_base64' => ['nullable', 'string', 'max:7000000'],
            'preview_url' => ['nullable', 'url:https', 'max:1000'],
        ]);
        if ($v->fails()) {
            throw new InvalidResult($v->errors()->first());
        }

        $template->forceFill([
            'title' => $result['title'], 'format' => $result['format'],
            'platform' => $result['platform'] ?? $template->platform, 'family' => $result['family'] ?? null,
            'archetype' => $result['archetype'] ?? null, 'creator' => $result['creator'] ?? null,
            'description' => $result['description'], 'spec' => $result['spec'], 'copy_template' => $result['copy_template'] ?? null,
            'palette' => $result['palette'] ?? null, 'sample_md' => $result['sample_md'], 'status' => 'live',
        ]);
        $preview = $this->preview($result);
        if ($preview !== null) {
            [$bytes, $ext] = $preview;
            $path = "tantra/templates/{$template->key}/preview.{$ext}";
            Storage::put($path, $bytes, 'public');
            $template->preview_path = $path;
        }
        $template->save();

        return "Template {$template->key} is live: {$template->title}.";
    }

    /** @return array{0: string, 1: string}|null */
    private function preview(array $result): ?array
    {
        $bytes = null;
        if (filled($result['preview_base64'] ?? null)) {
            $bytes = base64_decode((string) preg_replace('/^data:image\/\w+;base64,/', '', (string) $result['preview_base64']), true) ?: null;
        } elseif (filled($result['preview_url'] ?? null)) {
            try {
                $bytes = Http::timeout(30)->get((string) $result['preview_url'])->throw()->body();
            } catch (Throwable) {
                $bytes = null;
            }
        }
        if ($bytes === null || strlen($bytes) > 5_000_000) {
            return null;
        }
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return match ($type) {
            'image/png' => [$bytes, 'png'], 'image/jpeg' => [$bytes, 'jpg'], 'image/webp' => [$bytes, 'webp'],
            default => throw new InvalidResult('The preview must be a PNG, JPG or WebP image.'),
        };
    }
}
