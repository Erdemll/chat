<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
        return [
            'body' => ['required', 'string', 'max:4000'],
            'mentions' => ['nullable', 'array', 'list', 'max:20'],
            'mentions.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /** @return array<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            /** @var list<int> $userIds */
            $userIds = $this->input('mentions') ?? [];
            if ($userIds === []) {
                return;
            }
            if (User::query()->whereIn('id', $userIds)->where('is_active', true)
                ->where('id', '!=', $this->user()->id)->count() !== count($userIds)) {
                $validator->errors()->add('mentions', 'Yalnızca kendiniz dışındaki aktif çalışanları mention edebilirsiniz.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['body.required' => 'Mesaj boş olamaz.', 'body.string' => 'Mesaj metin olmalıdır.', 'body.max' => 'Mesaj en fazla 4000 karakter olabilir.'];
    }
}
