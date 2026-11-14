<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Analytics\BuildInsightsExport;
use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Actions\Analytics\ListAvailableChannelMetrics;
use App\Actions\Analytics\ReadPublicationAnalytics;
use App\Actions\Analytics\ResolveAnalyticsChannelFilter;
use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Enums\Analytics\ExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyticsReportRequest;
use App\Http\Requests\App\Insights\DownloadInsightsRequest;
use App\Http\Resources\App\PublicationDetailResource;
use App\Support\Analytics\InsightsExportWriter;
use App\Support\Analytics\SyncCadence;
use App\Tantra\Analytics\AccountInsights;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InsightsController extends Controller
{
    public function index(
        AnalyticsReportRequest $request,
        BuildWorkspaceAnalyticsReport $analytics,
        ResolveAnalyticsRangePreset $presets,
        ResolveAnalyticsChannelFilter $channelFilter,
        ListAvailableChannelMetrics $availableMetrics,
    ): Response {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);
        $tantra = app(AccountInsights::class)->begin($request); // tantra: every workspace the viewer can see, with the people and network filters (PRD 26)

        ['range' => $range, 'selection' => $selection, 'clamped' => $clamped] = $presets->selection($request->validated(), $request->user()->timezone);
        ['channels' => $channels, 'selected' => $selectedChannels, 'keys' => $channelKeys] = $channelFilter->execute($workspace, $tantra->requested($request->collect('channels'))); // tantra
        $channelKeys = $tantra->keys($channelKeys); // tantra
        $report = $analytics->forSelection($workspace, $selection, $channelKeys, $clamped, weekStart: $request->user()->week_starts_on);
        $single = $selectedChannels->count() === 1 ? $selectedChannels->first() : null;

        return Inertia::render('insights/Index', [
            'channelOptions' => $channels,
            'availableMetrics' => $single === null ? null : $availableMetrics->handle($single, data_get($channelKeys, $single->id)),
            'sync' => SyncCadence::toArray(),
            'report' => [
                ...$report,
                'filters' => [
                    'range' => $range,
                    'start' => data_get($report, 'range.start'),
                    'end' => data_get($report, 'range.end'),
                    'channels' => $tantra->pickedChannels($selectedChannels->pluck('id')->all(), $channels), // tantra: the picked channels, not those the people and network filters leave
                ],
            ],
            'tantraInsights' => fn (): array => $tantra->props($workspace, $request->user(), $report, $channelKeys, $request), // tantra: skipped by the coverage poll's report-only reloads
        ]);
    }

    public function download(
        DownloadInsightsRequest $request,
        string $format,
        BuildWorkspaceAnalyticsReport $analytics,
        BuildInsightsExport $export,
        ResolveAnalyticsRangePreset $presets,
        ResolveAnalyticsChannelFilter $channelFilter,
    ): StreamedResponse {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        $format = ExportFormat::from($format);
        $timezone = $request->user()->timezone;
        $tantra = app(AccountInsights::class)->begin($request); // tantra: the export covers what the tab shows (PRD 26)
        ['selection' => $selection, 'clamped' => $clamped] = $presets->selection($request->validated(), $timezone);
        ['keys' => $channelKeys] = $channelFilter->execute($workspace, $tantra->requested($request->collect('channels'))); // tantra
        $channelKeys = $tantra->keys($channelKeys); // tantra
        $accountKeys = $analytics->keys($channelKeys);
        ['bounds' => $bounds, 'range' => $current] = $analytics->resolveRange($workspace, $selection, $accountKeys, $clamped);
        $report = $analytics->forRange($workspace, $current, $bounds, $channelKeys, $request->user()->week_starts_on);
        $sections = $export->execute($workspace, $report, $current, $accountKeys, $timezone);
        $filename = 'trypost-insights-'.now($timezone)->toDateString().".{$format->value}";

        return response()->streamDownload(function () use ($format, $sections): void {
            $stream = fopen('php://output', 'wb');
            InsightsExportWriter::write($format, $sections, $stream);
            fclose($stream);
        }, $filename, ['Content-Type' => $format->contentType()]);
    }

    public function publication(Request $request, string $publication, ReadPublicationAnalytics $analytics): PublicationDetailResource
    {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);
        app(AccountInsights::class)->begin($request); // tantra: a Top 5 post of any visible workspace opens (PRD 26)

        return PublicationDetailResource::make($analytics->latestForWorkspacePublication($workspace, $publication));
    }
}
