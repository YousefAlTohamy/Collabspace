<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['progress', 'next_steps', 'blockers'])]
class DailyStatus extends Model
{
    protected $table = 'daily_statuses';

    protected function casts(): array
    {
        return ['status_date' => 'immutable_date'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
