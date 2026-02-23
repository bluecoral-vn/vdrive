<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitUploadRequest extends FormRequest
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
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'uuid', 'exists:folders,uuid'],
        ];
    }
}
