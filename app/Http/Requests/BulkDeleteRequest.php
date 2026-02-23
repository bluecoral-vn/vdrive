<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteRequest extends FormRequest
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
            'files' => ['sometimes', 'array'],
            'files.*' => ['string', 'exists:files,id'],
            'folders' => ['sometimes', 'array'],
            'folders.*' => ['string', 'uuid', 'exists:folders,uuid'],
        ];
    }

    /**
     * At least one of files or folders must be non-empty.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $files = $this->input('files', []);
            $folders = $this->input('folders', []);

            if (empty($files) && empty($folders)) {
                $validator->errors()->add('files', 'At least one file or folder ID is required.');
            }
        });
    }
}
