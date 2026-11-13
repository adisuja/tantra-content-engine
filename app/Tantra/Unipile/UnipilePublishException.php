<?php

declare(strict_types=1);

namespace App\Tantra\Unipile;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\SocialPublishException;
use Illuminate\Http\Client\Response;

/** tantra: a Unipile refusal, mapped to TryPost's error categories. */
class UnipilePublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $status = $response->status();
        $message = (string) ($response->json('detail') ?? $response->json('title') ?? $response->json('message') ?? 'LinkedIn refused the post.');

        $category = match (true) {
            $status === 429 => ErrorCategory::RateLimit,
            $status === 401, $status === 403 => ErrorCategory::Permission,
            $status === 400, $status === 422 => preg_match('/media|image|video|document|pdf|file|format|size/i', $message) ? ErrorCategory::MediaFormat : ErrorCategory::ContentPolicy,
            default => ErrorCategory::Unknown,
        };

        return new static($message, $category, (string) $status, $response->body());
    }

    public function platform(): string
    {
        return Platform::UnipileLinkedIn->value;
    }
}
