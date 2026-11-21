<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\TeamResource;
use App\Models\Team;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeamController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        // Small fixed list (4 rows), pagination would be noise.
        return TeamResource::collection(Team::query()->orderBy('name')->get());
    }
}
