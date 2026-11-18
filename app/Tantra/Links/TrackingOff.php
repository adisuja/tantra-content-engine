<?php

declare(strict_types=1);

namespace App\Tantra\Links;

use RuntimeException;

/** tantra: the team has tracking off in Tantra, so its post links are left as they are. */
final class TrackingOff extends RuntimeException {}
