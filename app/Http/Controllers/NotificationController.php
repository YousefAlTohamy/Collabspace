<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PaginationRequest;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function index(PaginationRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $perPage = (int) ($request->validated('per_page') ?? 15);

        $notifications = $user->notifications()
            ->reorder('created_at', 'desc')
            ->orderByDesc('id')
            ->paginate($perPage);

        return NotificationResource::collection($notifications)
            ->additional(['unread_count' => $user->notifications()->whereNull('read_at')->count()]);
    }

    public function read(Request $request, string $id): NotificationResource
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return new NotificationResource($notification);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => __('Notifications marked as read.')]);
    }
}
