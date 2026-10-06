<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_active;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $this->input('body'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:4000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['body.required' => 'Mesaj boş olamaz.', 'body.string' => 'Mesaj metin olmalıdır.', 'body.max' => 'Mesaj en fazla 4000 karakter olabilir.'];
    }
}
