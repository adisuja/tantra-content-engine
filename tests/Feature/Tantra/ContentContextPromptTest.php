<?php

declare(strict_types=1);

use App\Ai\Agents\PostWritingAssistant;
use App\Enums\Ai\PostAssistantMode;
use App\Enums\User\Locale;

it('puts the Tantra standing context in the writing prompt with Tantra branding on, and keeps upstream\'s prompt with it off', function () {
    $prompt = fn (): string => (new PostWritingAssistant(PostAssistantMode::cases()[0], 'Existing caption', Locale::English, null, 'Previous'))->instructions();

    config(['tantra.branding.enabled' => true]);
    expect($prompt())->toContain('Standing context for Tantra content');

    config(['tantra.branding.enabled' => false]);
    expect($prompt())->not->toContain('Standing context for Tantra content');
});
