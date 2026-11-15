<?php

declare(strict_types=1);

namespace App\Tantra\Auth;

use RuntimeException;

/**
 * tantra: Clerk could not be asked (its signing keys or Backend API timed
 * out, failed, or CLERK_SECRET_KEY is not set), so who the person is stays
 * unknown. Never a reason to say "no access": the caller answers "try again".
 */
class ClerkUnavailable extends RuntimeException {}
