<?php

declare(strict_types=1);

namespace App\Tantra\Analytics\Collectors;

use App\Dto\Analytics\AccountDailyObservation;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Followers\AbstractFollowerCollector;
use Carbon\CarbonImmutable;

/** tantra: the Tantra page's followers, from Zernio's account list (followersCount). */
class ZernioPageFollowerCollector extends AbstractFollowerCollector
{
    public function __construct(private readonly ZernioPageReader $reader) {}

    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        return $this->observation($date, $this->reader->followers($account, $this->reader->accountFor($account)));
    }
}
