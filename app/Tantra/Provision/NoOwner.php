<?php

declare(strict_types=1);

namespace App\Tantra\Provision;

use RuntimeException;

/** A new Tantra team arrived with nobody to own its Engine workspace. */
final class NoOwner extends RuntimeException {}
