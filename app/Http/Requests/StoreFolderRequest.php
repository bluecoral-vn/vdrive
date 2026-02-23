<?php

namespace App\Http\Requests;

use App\Models\Folder;
use Illuminate\Foundation\Http\FormRequest;

class StoreFolderRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'string', 'uuid', 'exists:folders,uuid'],
        ];
    }

    /**
     * Additional validation: no duplicate folder names at the same level.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Resolve UUID to numeric ID for the duplicate-name check
            $parentUuid = $this->validated('parent_id');
            $parentId = $parentUuid
                ? Folder::query()->where('uuid', $parentUuid)->value('id')
                : null;

            $exists = Folder::query()
                ->where('owner_id', $this->user()->id)
                ->where('parent_id', $parentId)
                ->where('name', $this->validated('name'))
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                $validator->errors()->add('name', 'A folder with this name already exists in this location.');
            }
        });
    }
}
