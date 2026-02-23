<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShareRequest extends FormRequest
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
            'file_id' => ['sometimes', 'nullable', 'uuid', 'exists:files,id'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'uuid', 'exists:folders,uuid'],
            'shared_with' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'permission' => ['required', 'string', 'in:view,edit'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'send_notification' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file_id.required_without' => 'Either file_id or folder_id is required.',
            'folder_id.required_without' => 'Either file_id or folder_id is required.',
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $hasFile = $this->filled('file_id');
            $hasFolder = $this->filled('folder_id');

            if (! $hasFile && ! $hasFolder) {
                $validator->errors()->add('file_id', 'Either file_id or folder_id is required.');
            }

            if ($hasFile && $hasFolder) {
                $validator->errors()->add('file_id', 'Cannot share both a file and a folder at the same time.');
            }

            // 'edit' permission is only for system users, not guest links
            if ($this->input('permission') === 'edit' && ! $this->filled('shared_with')) {
                $validator->errors()->add('permission', 'Edit permission requires a system user (shared_with is required).');
            }
        });
    }
}
