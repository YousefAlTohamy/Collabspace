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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function overview(ReportOverviewRequest $request, ComputeReportStats $stats): JsonResponse
    {
        $project = $this->authorizedProject($request->validated('project_id'));
        [$from, $to] = $request->window();

        return response()->json(['data' => $stats->execute($request->user(), $project, $from, $to)]);
    }

    public function store(StoreReportRequest $request, ComputeReportStats $stats): JsonResponse
    {
        $project = $this->authorizedProject($request->validated('project_id'));
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

        return ReportResource::collection($reports);
    }

    public function show(Report $report): ReportResource
    {
        Gate::authorize('view', $report);

        return new ReportResource($report->load('project'));
    }

    private function authorizedProject(int|string|null $id): ?Project
    {
        if ($id === null) {
            return null;
        }

        $project = Project::query()->find($id);
        throw_if($project === null, AuthorizationException::class);
        Gate::authorize('view', $project);

        return $project;
    }
}
