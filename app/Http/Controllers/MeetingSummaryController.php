<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\GenerateMeetingSummary;
use App\Http\Resources\MeetingResource;
use App\Models\Meeting;
use Illuminate\Support\Facades\Gate;

class MeetingSummaryController extends Controller
{
    public function __invoke(Meeting $meeting, GenerateMeetingSummary $action): MeetingResource
    {
        Gate::authorize('update', $meeting);
        $action->execute($meeting);

        return new MeetingResource($meeting->load(['organizer', 'participants', 'project']));
    }
}
