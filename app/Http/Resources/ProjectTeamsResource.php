<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the pre-computed teams overview from ProjectTeamsOverview. The
 * underlying data is an array of team groups, not an Eloquent model.
 */
class ProjectTeamsResource extends JsonResource
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
