<?php

namespace App\Http\Requests;

use App\Models\Folder;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFolderRequest extends FormRequest
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
            'parent_id' => ['sometimes', 'nullable', 'string', 'uuid', 'exists:folders,uuid'],
        ];
    }

    /**
     * At least one of name or parent_id must be present.
     * Reject rename if duplicate name exists at the same parent level.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->has('name') && ! $this->has('parent_id')) {
                $validator->errors()->add('name', 'At least one of name or parent_id must be provided.');
            }

            // Duplicate-name check on rename
            if ($this->has('name') && $validator->errors()->isEmpty()) {
                /** @var Folder $folder */
                $folder = $this->route('folder');

                $exists = Folder::query()
                    ->where('owner_id', $folder->owner_id)
                    ->where('parent_id', $folder->parent_id)
                    ->where('name', $this->validated('name'))
                    ->where('id', '!=', $folder->id)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'A folder with this name already exists in this location.');
                }
            }
        });
    }
}
