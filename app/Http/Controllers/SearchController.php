<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Http\Resources\SearchResource;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;

class SearchController extends Controller
{
    public function __invoke(SearchRequest $request): SearchResource
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->validated('q'));
        $pattern = '%'.$escaped.'%';
        $projects = Project::query()->whereHas('members', fn (Builder $members) => $members->where('user_id', $request->user()->id));
        $projectIds = (clone $projects)->select('projects.id');

        return new SearchResource([
            'projects' => $projects->where('name', 'like', $pattern)->latest()->orderByDesc('id')->limit(5)
                ->get(['id', 'name', 'status']),
            'tasks' => Task::query()->join('projects', 'projects.id', '=', 'tasks.project_id')
                ->whereIn('tasks.project_id', $projectIds)->where('tasks.title', 'like', $pattern)
                ->latest('tasks.created_at')->orderByDesc('tasks.id')->limit(5)
                ->get(['tasks.id', 'tasks.title', 'tasks.status', 'tasks.project_id', 'projects.name as project_name']),
            'files' => ProjectFile::query()->join('projects', 'projects.id', '=', 'files.project_id')
                ->whereIn('files.project_id', $projectIds)->where('files.original_name', 'like', $pattern)
                ->latest('files.created_at')->orderByDesc('files.id')->limit(5)
                ->get(['files.id', 'files.original_name', 'files.project_id', 'projects.name as project_name']),
        ]);
    }
}
