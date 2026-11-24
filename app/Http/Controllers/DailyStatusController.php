<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListDailyStatusesRequest;
use App\Http\Requests\StoreDailyStatusRequest;
use App\Http\Resources\DailyStatusResource;
use App\Models\DailyStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DailyStatusController extends Controller
{
    public function index(ListDailyStatusesRequest $request, Project $project): AnonymousResourceCollection
    {
        $actor = $request->user();
        $statuses = DailyStatus::query()->where('project_id', $project->id)
            ->where('status_date', $request->validated('date') ?? today('UTC')->toDateString())
            ->when($actor->cannot('viewAllDailyStatuses', $project), fn (Builder $query) => $query->where('user_id', $actor->id))
            ->with('author')->latest('updated_at')->orderByDesc('id')
            ->paginate((int) ($request->validated('per_page') ?? 15));

        return DailyStatusResource::collection($statuses)->withQuery($request->validated());
    }

    public function update(StoreDailyStatusRequest $request, Project $project): DailyStatusResource
    {
        $key = [
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'status_date' => today('UTC')->toDateString(),
        ];
        $attributes = $request->validated() + ['blockers' => null];
        DailyStatus::query()->upsert([$key + $attributes], array_keys($key), array_keys($attributes));

        return new DailyStatusResource(DailyStatus::query()->where($key)->with('author')->firstOrFail());
    }
}
