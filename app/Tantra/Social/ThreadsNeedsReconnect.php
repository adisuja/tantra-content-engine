<?php

declare(strict_types=1);

namespace App\Tantra\Social;

use RuntimeException;

/** tantra: Threads refused the channel's token, or the token lacks the reply permissions. */
final class ThreadsNeedsReconnect extends RuntimeException {}
