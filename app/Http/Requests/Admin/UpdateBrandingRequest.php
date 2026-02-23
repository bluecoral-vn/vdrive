<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandingRequest extends FormRequest
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
            'app_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'copyright_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tag_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'logo' => ['sometimes', 'nullable', 'file', 'mimes:png,svg,webp', 'max:1024'],
            'favicon' => ['sometimes', 'nullable', 'file', 'mimes:ico,png', 'max:1024'],
            'delete_logo' => ['sometimes', 'boolean'],
            'delete_favicon' => ['sometimes', 'boolean'],
        ];
    }
}
