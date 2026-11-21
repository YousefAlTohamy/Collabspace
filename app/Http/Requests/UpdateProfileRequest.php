<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AvailabilityStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string|Rule>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'years_of_experience' => ['nullable', 'integer', 'min:0', 'max:255'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'availability_status' => ['nullable', Rule::enum(AvailabilityStatus::class)],
        ];
    }
}
