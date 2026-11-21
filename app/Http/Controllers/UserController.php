<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchUsersRequest;
use App\Http\Resources\UserSummaryResource;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __invoke(SearchUsersRequest $request): AnonymousResourceCollection
    {
        $search = mb_strtolower(trim((string) $request->validated('search')));

        // Escape LIKE wildcards so % and _ are matched literally.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);

        $users = User::query()
            ->whereKeyNot($request->user()->id)
            ->where(function (Builder $query) use ($escaped, $search): void {
                $query
                    ->where('name', 'like', '%'.$escaped.'%')
                    ->orWhere('email', $search);
            })
            ->orderBy('name')
            ->paginate($request->validated('per_page', 15));

        return UserSummaryResource::collection($users);
    }
}
