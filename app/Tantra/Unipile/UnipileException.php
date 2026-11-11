<?php

declare(strict_types=1);

namespace App\Tantra\Unipile;

use Illuminate\Http\Client\Response;
use RuntimeException;

/** tantra: a refused Unipile call. A restriction or checkpoint pauses the account (PRD 10.5 safety rails). */
class UnipileException extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Unipile answered '.$response->status().': '.mb_substr((string) ($response->json('detail') ?? $response->json('message') ?? $response->body()), 0, 300));
    }

    public function isRestriction(): bool
    {
        $text = strtolower($this->response->body());

        return in_array($this->response->status(), [401, 403], true)
            || str_contains($text, 'restrict') || str_contains($text, 'checkpoint') || str_contains($text, 'disconnected');
    }

    public function isRateLimit(): bool
    {
        return $this->response->status() === 429;
    }
}
