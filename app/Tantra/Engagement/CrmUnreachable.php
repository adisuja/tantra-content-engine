<?php

declare(strict_types=1);

namespace App\Tantra\Engagement;

use RuntimeException;

/** tantra: the CRM did not answer, or failed on its side (5xx). The Engagement tab shows its "CRM unreachable" state. */
final class CrmUnreachable extends RuntimeException {}
