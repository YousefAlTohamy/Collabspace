<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string|Closure>>
     */
    public function rules(): array
    {
        return [
            'current_password' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! Hash::check((string) $value, (string) $this->user()->password)) {
                        $fail(__('auth.password'))->translate();
                    }
                },
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
