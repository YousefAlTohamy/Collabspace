<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchUsersRequest;
use App\Http\Resources\UserSummaryResource;
use App\Models\User;
use App\Support\Like;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __invoke(SearchUsersRequest $request): AnonymousResourceCollection
    {
        $search = mb_strtolower(trim((string) $request->validated('search')));

        $users = User::query()
            ->whereKeyNot($request->user()->id)
            ->where(function (Builder $query) use ($search): void {
                $query
                    ->where('name', 'like', Like::contains($search))
                    ->orWhere('email', $search);
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->validated('per_page', 15));

        return UserSummaryResource::collection($users)->withQuery($request->validated());
    }
}
