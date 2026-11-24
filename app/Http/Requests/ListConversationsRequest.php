<?php

declare(strict_types=1);

namespace App\Http\Requests;

class ListConversationsRequest extends PaginationRequest
{
    public function rules(): array
    {
        return parent::rules() + ['q' => ['nullable', 'string', 'min:2', 'max:200']];
    }
}
