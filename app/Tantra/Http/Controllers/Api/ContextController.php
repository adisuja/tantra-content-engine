<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tantra\Links\LinkRules;
use Illuminate\Http\JsonResponse;

/** tantra: the standing content context the Engine's own generator reads, for the content kit and agents (card 29). */
class ContextController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'context' => trim(view('prompts.tantra.content_context')->render()),
            'links' => [
                'campaign_id' => config('tantra.crm.links.campaign_id'),
                'organic' => config('tantra.crm.links.organic'),
                'utm_campaign' => config('tantra.crm.links.utm_campaign'),
                'mediums' => LinkRules::MEDIUMS,
            ],
        ]]);
    }
}
