<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $meeting = $this->route('meeting');

        return $meeting === null || $this->user()->can('update', $meeting);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'starts_at' => ['required', 'date', Rule::when($this->route('meeting') === null, 'after:now')],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'meeting_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'note' => ['nullable', 'string', 'max:2000'],
            'project_id' => ['nullable', 'integer'],
            'participant_ids' => ['sometimes', 'array', 'list'],
            'participant_ids.*' => ['bail', 'required', 'integer', 'distinct', Rule::notIn([$this->user()->id]), 'exists:users,id'],
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->input('starts_at'))->utc();
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $meeting = $this->route('meeting');
            $start = $this->startsAt();
            if ($meeting !== null && ! $start->isFuture() && $start->format('Y-m-d H:i:s') !== $meeting->starts_at->format('Y-m-d H:i:s')) {
                $validator->errors()->add('starts_at', 'The starts at field must be a date after now.');
            }

            $actor = $this->user();
            $projectId = $this->input('project_id', $meeting?->project_id);
            if ($projectId !== null) {
                $project = Project::query()->find($projectId);
                throw_if($project === null, AuthorizationException::class);
                Gate::authorize('view', $project);
            }

            $participants = $this->input('participant_ids')
                ?? $meeting?->participants()->whereKeyNot($actor->id)->pluck('users.id')->all()
                ?? [];
            if ($participants === []) {
                return;
            }

            $eligible = User::query()->whereKey($participants)
                ->whereHas('projects', function (Builder $projects) use ($projectId, $actor): void {
                    if ($projectId !== null) {
                        $projects->whereKey($projectId);

                        return;
                    }

                    $projects->whereIn('projects.id', $actor->projects()->getQuery()->select('projects.id'));
                })->pluck('id')->all();

            foreach (array_diff($participants, $eligible) as $index => $id) {
                $field = $this->has('participant_ids') ? "participant_ids.{$index}" : 'participant_ids';
                $validator->errors()->add($field, 'The participant must belong to the project or share a project with the organizer.');
            }
        }];
    }
}
