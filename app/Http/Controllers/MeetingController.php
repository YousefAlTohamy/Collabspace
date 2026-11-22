<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListMeetingsRequest;
use App\Http\Requests\StoreMeetingRequest;
use App\Http\Resources\MeetingResource;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

class MeetingController extends Controller
{
    private const RELATIONS = ['organizer', 'participants', 'project'];

    public function index(ListMeetingsRequest $request): AnonymousResourceCollection
    {
        $actor = $request->user();
        $today = CarbonImmutable::today('UTC');
        $from = CarbonImmutable::parse($request->validated('from') ?? $today, 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($request->validated('to') ?? $today->addDays(30), 'UTC')->addDay()->startOfDay();

        $meetings = Meeting::query()
            ->where(fn (Builder $mine) => $mine->where('created_by', $actor->id)
                ->orWhereHas('participants', fn (Builder $participants) => $participants->whereKey($actor->id)))
            ->where(fn (Builder $visible) => $visible->whereNull('project_id')
                ->orWhereHas('project', fn (Builder $project) => $project->visibleTo($actor)))
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to)
            ->with(self::RELATIONS)
            ->orderBy('starts_at')->orderBy('id')
            ->paginate((int) ($request->validated('per_page') ?? 15));

        return MeetingResource::collection($meetings)->preserveQuery();
    }

    public function store(StoreMeetingRequest $request): JsonResponse
    {
        $meeting = DB::transaction(function () use ($request): Meeting {
            $meeting = new Meeting;
            $meeting->created_by = $request->user()->id;
            $this->saveMeeting($meeting, $request);

            return $meeting;
        });

        return (new MeetingResource($meeting->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(Meeting $meeting): MeetingResource
    {
        Gate::authorize('view', $meeting);

        return new MeetingResource($meeting->load(self::RELATIONS));
    }

    public function update(StoreMeetingRequest $request, Meeting $meeting): MeetingResource
    {
        $meeting = DB::transaction(function () use ($request, $meeting): Meeting {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            $this->saveMeeting($locked, $request);

            return $locked;
        });

        return new MeetingResource($meeting->load(self::RELATIONS));
    }

    public function destroy(Meeting $meeting): JsonResponse
    {
        Gate::authorize('delete', $meeting);
        $meeting->delete();

        return response()->json(['message' => __('Meeting deleted.')]);
    }

    private function saveMeeting(Meeting $meeting, StoreMeetingRequest $request): void
    {
        $creating = ! $meeting->exists;
        $meeting->fill($request->safe()->except(['participant_ids', 'starts_at']));
        $meeting->starts_at = $request->startsAt();
        $meeting->save();

        if ($creating || $request->safe()->has('participant_ids')) {
            $actor = $request->user();
            $changes = $meeting->participants()->sync([$actor->id, ...$request->validated('participant_ids', [])]);
            $invited = User::query()->whereKey($changes['attached'])->whereKeyNot($actor->id)->get();
            Notification::send($invited, new MeetingInvitation($meeting->id, $meeting->project_id, $actor));
        }
    }
}
