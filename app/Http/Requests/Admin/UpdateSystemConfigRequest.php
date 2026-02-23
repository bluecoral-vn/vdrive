<?php

namespace App\Http\Requests\Admin;

use App\Services\SystemConfigService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSystemConfigRequest extends FormRequest
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
            'configs' => ['required', 'array', 'min:1'],
            'configs.*.key' => ['required', 'string', Rule::in(SystemConfigService::ALLOWED_KEYS)],
            'configs.*.value' => ['present', 'nullable', 'string'],
        ];
    }
}
