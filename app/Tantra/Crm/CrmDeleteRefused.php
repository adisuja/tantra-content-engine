<?php

declare(strict_types=1);

namespace App\Tantra\Crm;

use RuntimeException;

/** tantra: a network delete that did not happen, with the answer the Tantra app is given (a sentence a customer can read). */
final class CrmDeleteRefused extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
