<?php

declare(strict_types=1);

namespace App\Tantra\Engagement;

use RuntimeException;

/** tantra: the CRM answered with a 4xx; its own words and code are passed on to the person. */
final class CrmRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly ?string $crmCode = null)
    {
        parent::__construct($message);
    }
}
