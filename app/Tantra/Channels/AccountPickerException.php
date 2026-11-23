<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use RuntimeException;

/** tantra: the CRM's account picker could not connect what was picked (expired, or an account it may not pick). */
final class AccountPickerException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
