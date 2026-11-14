<?php

declare(strict_types=1);

namespace App\Tantra\Analytics\Collectors;

use App\Dto\Analytics\AccountDailyObservation;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Followers\AbstractFollowerCollector;
use Carbon\CarbonImmutable;

/**
 * tantra: followers of a LinkedIn profile (OAuth or Unipile channel), read
 * through Unipile's users/me. Never the OAuth token path.
 */
class UnipileLinkedInFollowerCollector extends AbstractFollowerCollector
{
    public function __construct(private readonly UnipileLinkedInReader $reader) {}

    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        return $this->observation($date, $this->reader->followers($this->reader->accountFor($account)));
    }
}
