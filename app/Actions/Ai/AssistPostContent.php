<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use App\Ai\Agents\PostWritingAssistant;
use App\Enums\Ai\PostAssistantMode;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Repurpose\CaptionAdapter;
use App\Tantra\Ai\TantraAi;
use Illuminate\Validation\ValidationException;

final class AssistPostContent
{
    public static function execute(
        Workspace $workspace,
        User $user,
        PostAssistantMode $mode,
        string $currentContent,
        ?string $prompt,
        ?string $previousContent,
        ?Platform $platform,
        ?SocialAccount $account = null,
        ?ContentType $contentType = null,
    ): string {
        $platform = $account->platform ?? $platform;
        $limit = transform($platform, fn (Platform $platform): int => ContentType::maxContentLengthFor($account, $platform, $contentType));

        // tantra: runs on the Account's AI connector (Connectors tab).
        $response = TantraAi::prompt(
            new PostWritingAssistant($mode, $currentContent, $user->locale, $platform, $previousContent, $limit),
            $mode->requiresPrompt() ? trim((string) $prompt) : 'Revise the existing caption as instructed.',
            $workspace->account_id,
        );

        $text = trim((string) $response);

        if ($text === '') {
            throw ValidationException::withMessages([
                $mode->requiresPrompt() ? 'prompt' : 'current_content' => __('posts.composer.assistant_error'),
            ]);
        }

        return $platform instanceof Platform
            ? app(CaptionAdapter::class)->adapt($workspace, $user, $text, $platform, $limit)
            : $text;
    }
}
