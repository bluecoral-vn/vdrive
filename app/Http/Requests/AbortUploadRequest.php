<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AbortUploadRequest extends FormRequest
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
        ];
    }
}
