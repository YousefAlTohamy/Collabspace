<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'note', 'start_date', 'end_date'])]
class Report extends Model
{
    protected function casts(): array
    {
        return [
            'type' => ReportType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'snapshot' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
