<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectPriority;
use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'type', 'priority', 'status', 'start_date', 'end_date'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => ProjectPriority::class,
            'status' => ProjectStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('members', fn (Builder $members) => $members->where('user_id', $user->id));
    }

    public static function findVisibleOrFail(User $user, int|string $id): self
    {
        return self::query()->visibleTo($user)->find($id) ?? throw new AuthorizationException;
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * R2: membership is the permission boundary.
     */
    public function isMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    /**
     * R1: leadership is what grants management rights.
     */
    public function isLeader(User $user): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->where('role', ProjectRole::Leader->value)
            ->exists();
    }

    /**
     * R1: used to guard demoting/removing the last leader.
     */
    public function leaderCount(): int
    {
        return $this->members()
            ->where('role', ProjectRole::Leader->value)
            ->count();
    }
}
