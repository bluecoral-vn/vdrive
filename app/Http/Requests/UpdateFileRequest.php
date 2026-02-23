<?php

namespace App\Http\Requests;

use App\Models\File;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFileRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'uuid', 'exists:folders,uuid'],
        ];
    }

    /**
     * At least one of name or folder_id must be present.
     * Reject rename if duplicate name exists in the same folder.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->has('name') && ! $this->has('folder_id')) {
                $validator->errors()->add('name', 'At least one of name or folder_id must be provided.');
            }

            // Duplicate-name check on rename
            if ($this->has('name') && $validator->errors()->isEmpty()) {
                /** @var File $file */
                $file = $this->route('file');

                $query = File::query()
                    ->where('owner_id', $file->owner_id)
                    ->where('name', $this->validated('name'))
                    ->where('id', '!=', $file->id);

                if ($file->folder_id === null) {
                    $query->whereNull('folder_id');
                } else {
                    $query->where('folder_id', $file->folder_id);
                }

                if ($query->exists()) {
                    $validator->errors()->add('name', 'A file with this name already exists in this location.');
                }
            }
        });
    }
}
