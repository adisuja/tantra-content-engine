<?php

declare(strict_types=1);

namespace App\Tantra\Auth;

use RuntimeException;

/**
 * tantra: a Clerk session token that must not sign anyone in.
 */
class InvalidClerkToken extends RuntimeException {}
