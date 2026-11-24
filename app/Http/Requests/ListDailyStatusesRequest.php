<?php

declare(strict_types=1);

namespace App\Http\Requests;

class ListDailyStatusesRequest extends PaginationRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('project'));
    }

    public function rules(): array
    {
        return parent::rules() + ['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']];
    }
}
