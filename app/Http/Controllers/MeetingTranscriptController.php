<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListMeetingTranscriptsRequest;
use App\Http\Requests\StoreMeetingTranscriptRequest;
use App\Http\Resources\MeetingTranscriptResource;
use App\Models\Meeting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class MeetingTranscriptController extends Controller
{
    public function index(ListMeetingTranscriptsRequest $request, Meeting $meeting): AnonymousResourceCollection
    {
        $query = $meeting->transcript()->with('speaker')->orderBy('offset_seconds')->orderBy('id');
        if (($search = $request->validated('q')) !== null) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where('text', 'like', '%'.$escaped.'%');
        }

        return MeetingTranscriptResource::collection($query->paginate((int) ($request->validated('per_page') ?? 15)))
            ->preserveQuery();
    }

    public function store(StoreMeetingTranscriptRequest $request, Meeting $meeting): JsonResponse
    {
        $entries = DB::transaction(fn () => $meeting->transcript()->createMany($request->validated('entries')));

        return MeetingTranscriptResource::collection($entries->load('speaker'))->response()->setStatusCode(201);
    }
}
