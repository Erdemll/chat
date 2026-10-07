<?php

namespace App\Http\Requests;

use App\Models\Message;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMessageReadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->is_active === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message_ids' => ['required', 'array', 'list', 'max:100'],
            'message_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /** @return array<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var list<int> $messageIds */
            $messageIds = $this->input('message_ids');

            /** Check all IDs in one query after their shapes have been validated. */
            if (Message::query()->whereIn('id', $messageIds)->count() !== count($messageIds)) {
                $validator->errors()->add('message_ids', 'Seçilen mesajlardan biri geçersiz.');
            }
        }];
    }
}
