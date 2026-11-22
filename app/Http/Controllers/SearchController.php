<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Http\Resources\SearchResource;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\Task;
use App\Support\Like;

class SearchController extends Controller
{
    public function __invoke(SearchRequest $request): SearchResource
    {
        $pattern = Like::contains($request->validated('q'));
        $projects = Project::query()->visibleTo($request->user());
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
