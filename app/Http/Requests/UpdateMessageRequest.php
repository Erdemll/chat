<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Gate;

class UpdateMessageRequest extends StoreMessageRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->route('message'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'expected_body' => ['sometimes', 'string', 'max:4000']];
    }
}
