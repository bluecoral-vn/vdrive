<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBackupConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'backup_enabled' => ['sometimes', 'boolean'],
            'backup_schedule_type' => ['sometimes', 'string', 'in:daily,monthly,manual'],
            'backup_time' => ['sometimes', 'date_format:H:i'],
            'backup_day_of_month' => ['nullable', 'integer', 'in:1,3,7'],
            'backup_retention_days' => ['nullable', 'integer', 'in:3,7,15,30'],
            'backup_keep_forever' => ['sometimes', 'boolean'],
            'backup_notification_email' => ['nullable', 'email'],
        ];
    }
}
