<?php

declare(strict_types=1);

// tantra: settings for everything the Tantra fork adds. Read with config('tantra.*').

return [

    // In production, fold upstream's Horizon supervisors into one pool of a
    // few workers (App\Tantra\Ops\LeanHorizon), sized for a small team on a
    // 2 GB server. Set TANTRA_LEAN_WORKERS=false to use upstream's sizing.
    'lean_workers' => [
        'enabled' => (bool) env('TANTRA_LEAN_WORKERS', true),
        'processes' => (int) env('TANTRA_LEAN_WORKER_PROCESSES', 3),
    ],

];
