<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\PassportServiceProvider;
use App\Tantra\TantraServiceProvider; // tantra: fork entry point

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    PassportServiceProvider::class,
    TantraServiceProvider::class, // tantra: fork entry point
];
