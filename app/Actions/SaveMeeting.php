<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class SaveMeeting
{
    /** @param list<int|string>|null $participantIds */
    public function execute(
        User $actor,
        Meeting $meeting,
        array $attributes,
        CarbonImmutable $startsAt,
        ?array $participantIds,
    ): Meeting {
        return DB::transaction(function () use ($actor, $meeting, $attributes, $startsAt, $participantIds): Meeting {
            $creating = ! $meeting->exists;
            if ($creating) {
                $meeting->created_by = $actor->id;
            } else {
                $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            }

            $meeting->fill($attributes);
            $meeting->starts_at = $startsAt;
            $meeting->save();

            if ($creating || $participantIds !== null) {
                $changes = $meeting->participants()->sync([$actor->id, ...($participantIds ?? [])]);
                $invited = User::query()->whereKey($changes['attached'])->whereKeyNot($actor->id)->get();
                Notification::send($invited, new MeetingInvitation($meeting->id, $meeting->project_id, $actor));
            }

            return $meeting;
        });
    }
}
