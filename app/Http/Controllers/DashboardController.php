<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildDashboard;
use App\Http\Resources\DashboardResource;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, BuildDashboard $dashboard): DashboardResource
    {
        return new DashboardResource($dashboard->execute($request->user()));
    }
}
