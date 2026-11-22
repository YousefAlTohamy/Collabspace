<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConversationType;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

#[Fillable(['type', 'project_id', 'team_id', 'user_one_id', 'user_two_id'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['type' => ConversationType::class];
    }

    public static function createProjectChannel(Project $project): void
    {
        self::query()->create(['project_id' => $project->id, 'type' => ConversationType::Project]);
    }

    public static function ensureTeamChannel(int $projectId, int $teamId): void
    {
        $attributes = ['project_id' => $projectId, 'team_id' => $teamId, 'type' => ConversationType::Team->value];

        try {
            self::query()->firstOrCreate($attributes);
        } catch (UniqueConstraintViolationException $exception) {
            // A repeatable-read snapshot can miss a channel committed by another transaction.
            if (self::query()->where($attributes)->sharedLock()->first(['id']) === null) {
                throw $exception;
            }
        }
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }
}
