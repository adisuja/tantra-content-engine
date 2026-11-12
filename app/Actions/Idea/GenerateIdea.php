<?php

declare(strict_types=1);

namespace App\Actions\Idea;

use App\Ai\Agents\IdeaGenerator;
use App\Models\User;
use App\Support\AiPromptRules;
use App\Tantra\Ai\TantraAi;
use Illuminate\Support\Str;

class GenerateIdea
{
    /**
     * @return array{title: string, body: string}|null
     */
    public static function execute(User $user, string $business, string $audience, ?string $notes): ?array
    {
        // tantra: runs on the Account's AI connector (Connectors tab).
        $response = TantraAi::prompt(new IdeaGenerator($user->locale, $business, $audience, $notes), 'Suggest one content idea.', $user->account_id);

        $title = Str::substr(trim((string) data_get($response, 'title')), 0, 255);

        if ($title === '') {
            return null;
        }

        return [
            'title' => $title,
            'body' => Str::substr(trim((string) data_get($response, 'body')), 0, AiPromptRules::PROMPT_MAX_LENGTH),
        ];
    }
}
