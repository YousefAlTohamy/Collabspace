<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ComputeReportStats;
use App\Http\Requests\PaginationRequest;
use App\Http\Requests\ReportOverviewRequest;
use App\Http\Requests\StoreReportRequest;
use App\Http\Resources\ReportResource;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function overview(ReportOverviewRequest $request, ComputeReportStats $stats): JsonResponse
    {
        $project = $this->authorizedProject($request->user(), $request->validated('project_id'));
        [$from, $to] = $request->window();

        return response()->json(['data' => $stats->execute($request->user(), $project, $from, $to, calendarDay: $request->isToday())]);
    }

    public function store(StoreReportRequest $request, ComputeReportStats $stats): JsonResponse
    {
        $project = $this->authorizedProject($request->user(), $request->validated('project_id'));
        [$from, $to] = $request->window();
        $report = new Report($request->safe()->except('project_id'));
        $report->user_id = $request->user()->id;
        $report->project_id = $project?->id;
        $report->snapshot = $stats->execute($request->user(), $project, $from, $to);
        $report->save();

        return (new ReportResource($report->load('project')))->response()->setStatusCode(201);
    }

    public function index(PaginationRequest $request): AnonymousResourceCollection
    {
        $reports = Report::query()->where('user_id', $request->user()->id)
            ->with('project')->latest()->orderByDesc('id')
            ->paginate((int) ($request->validated('per_page') ?? 15));

        return ReportResource::collection($reports)->withQuery($request->validated());
    }

    public function show(Report $report): ReportResource
    {
        Gate::authorize('view', $report);

        return new ReportResource($report->load('project'));
    }

    private function authorizedProject(User $user, int|string|null $id): ?Project
    {
        return $id === null ? null : Project::findVisibleOrFail($user, $id);
    }
}
