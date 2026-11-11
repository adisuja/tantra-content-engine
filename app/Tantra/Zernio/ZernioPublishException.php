<?php

declare(strict_types=1);

namespace App\Tantra\Zernio;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\SocialPublishException;
use Illuminate\Http\Client\Response;

/** tantra: a Zernio refusal, mapped to TryPost's error categories (PRD 10.4 step 5). */
class ZernioPublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $status = $response->status();
        $message = (string) ($response->json('error') ?? $response->json('message') ?? 'Zernio refused the post.');

        $category = match (true) {
            $status === 429 => ErrorCategory::RateLimit,
            $status === 401, $status === 403 => ErrorCategory::Permission,
            $status === 400, $status === 422 => self::fromText($message),
            default => ErrorCategory::Unknown,
        };

        return (new static("Zernio: {$message}", $category, (string) $status, $response->body()))->withNetworkReset($response);
    }

    /** Category for a platform-side failure reported inside a 2xx answer. */
    public static function fromText(string $message, ?string $zernioCategory = null): ErrorCategory
    {
        return match ($zernioCategory) {
            'auth_expired', 'account_issue' => ErrorCategory::Permission,
            'platform_rate_limit', 'quota_exhausted' => ErrorCategory::RateLimit,
            'user_content', 'user_abuse', 'platform_rejected' => preg_match('/media|image|video|document|pdf|file|format|size/i', $message)
                ? ErrorCategory::MediaFormat
                : ErrorCategory::ContentPolicy,
            default => preg_match('/media|image|video|document|pdf|file|format|size/i', $message) ? ErrorCategory::MediaFormat : ErrorCategory::ContentPolicy,
        };
    }

    public function platform(): string
    {
        return Platform::ZernioLinkedInPage->value;
    }
}
