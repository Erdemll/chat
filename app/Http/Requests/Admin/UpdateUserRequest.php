<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class UpdateUserRequest extends StoreUserRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))]];
    }
}
