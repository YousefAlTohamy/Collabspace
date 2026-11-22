<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ProjectMember;
use Illuminate\Validation\ValidationException;

class LockTaskMembers
{
    public function execute(int $projectId, array $attributes): void
    {
        $memberFields = [];
        foreach ($attributes['assignee_ids'] ?? [] as $index => $userId) {
            $memberFields["assignee_ids.{$index}"] = (int) $userId;
        }
        if (isset($attributes['reviewer_id'])) {
            $memberFields['reviewer_id'] = (int) $attributes['reviewer_id'];
        }
        if ($memberFields === []) {
            return;
        }

        // R3: serialize assignment writes with removal of those project members.
        $members = ProjectMember::query()
            ->where('project_id', $projectId)
            ->whereIn('user_id', $memberFields)
            ->orderBy('user_id')
            ->lockForUpdate()
            ->pluck('user_id')
            ->all();

        $missingFields = array_keys(array_diff($memberFields, $members));
        if ($missingFields !== []) {
            throw ValidationException::withMessages(array_fill_keys($missingFields, __('The selected user must belong to the project.')));
        }
    }
}
