<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Tantra\Analytics\Insights;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** tantra: /tantra/insights, the team dashboard (PRD 10.6). Adi sees everyone; a teammate sees their workspaces. */
class InsightsController extends Controller
{
    public function index(Request $request): Response
    {
        $days = (int) $request->query('days', '30');
        $days = in_array($days, Insights::RANGES, true) ? $days : 30;

        return Inertia::render('tantra/insights/Index', [
            'insights' => Insights::for(ReviewPageController::visibleWorkspaceIds($request->user())->all(), $days),
            'ranges' => Insights::RANGES,
        ]);
    }
}
