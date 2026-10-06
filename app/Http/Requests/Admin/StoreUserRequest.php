<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim($this->string('email')->toString()))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', Rule::unique('users')], 'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_EMPLOYEE])], 'is_active' => ['required', 'boolean']];
    }

    /** @return array{name: string, email: string, role: string, is_active: bool} */
    public function userAttributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'role' => $this->string('role')->toString(),
            'is_active' => $this->boolean('is_active'),
        ];
    }
}
