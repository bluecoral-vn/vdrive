<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PresignPartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'uuid', 'exists:upload_sessions,id'],
            'part_number' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
