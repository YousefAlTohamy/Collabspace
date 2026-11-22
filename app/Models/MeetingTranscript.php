<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MeetingTranscriptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['speaker_id', 'offset_seconds', 'text'])]
class MeetingTranscript extends Model
{
    /** @use HasFactory<MeetingTranscriptFactory> */
    use HasFactory;

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'speaker_id');
    }
}
