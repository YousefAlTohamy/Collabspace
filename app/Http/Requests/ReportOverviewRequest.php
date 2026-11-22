<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportOverviewRequest extends FormRequest
{
    private const DAYS = ['week' => 7, 'month' => 30, 'three_months' => 90, 'year' => 365];

    public function rules(): array
    {
        return [
            'period' => ['nullable', 'string', Rule::in(array_keys(self::DAYS))],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function window(): array
    {
        $to = CarbonImmutable::now('UTC');

        return [$to->subDays(self::DAYS[$this->validated('period') ?? 'year']), $to];
    }
}
