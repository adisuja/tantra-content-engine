<?php

declare(strict_types=1);

namespace App\Tantra\Agent;

use RuntimeException;

/** tantra: a job result the Engine will not apply, with the reason the agent gets back. */
final class InvalidResult extends RuntimeException {}
