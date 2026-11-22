<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'starts_at', 'duration_minutes', 'meeting_url', 'note', 'project_id'])]
class Meeting extends Model
{
    /** @use HasFactory<MeetingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
            'project_id' => 'integer',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_participants')->withTimestamps();
    }

    public function transcript(): HasMany
    {
        return $this->hasMany(MeetingTranscript::class);
    }
}
