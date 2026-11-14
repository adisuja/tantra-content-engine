<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Tantra\Analytics\Insights;
use App\Tantra\Analytics\TrafficReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** tantra: /tantra/insights, the team dashboard (PRD 10.6) built around traffic and leads. Adi sees everyone; a teammate sees their workspaces. */
class InsightsController extends Controller
{
    public function index(Request $request): Response
    {
        $days = (int) $request->query('days', '30');
        $days = in_array($days, Insights::RANGES, true) ? $days : 30;

        $bucket = (string) $request->query('bucket', 'day');
        $workspaceIds = ReviewPageController::visibleWorkspaceIds($request->user())->all();

        return Inertia::render('tantra/insights/Index', [
            'insights' => Insights::for($workspaceIds, $days),
            // Traffic and leads per person, platform and piece (social from the Engine, funnel from the Tantra CRM).
            'traffic' => TrafficReport::build($workspaceIds, $days, $bucket),
            'member' => $request->query('member'),
            'ranges' => Insights::RANGES,
        ]);
    }
}
