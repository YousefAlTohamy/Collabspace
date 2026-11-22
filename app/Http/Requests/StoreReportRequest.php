<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ReportType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ReportType::class)],
            'note' => ['nullable', 'string', 'max:2000'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'project_id' => ['nullable', 'integer'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }

            $start = CarbonImmutable::parse($this->input('start_date'), 'UTC');
            $end = CarbonImmutable::parse($this->input('end_date'), 'UTC');
            if ($end > $start->addDays(366)) {
                $validator->errors()->add('end_date', __('The end date must be within 366 days of the start date.'));
            }
        }];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function window(): array
    {
        return [
            CarbonImmutable::parse($this->validated('start_date'), 'UTC')->startOfDay(),
            CarbonImmutable::parse($this->validated('end_date'), 'UTC')->endOfDay(),
        ];
    }
}
