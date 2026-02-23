<?php

namespace App\Services;

use App\Models\SystemConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

class SystemConfigService
{
    /**
     * Keys that contain sensitive data and must be encrypted at rest.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'r2_access_key',
        'r2_secret_key',
        'smtp_password',
    ];

    /**
     * Placeholder value used to mask secrets in API responses.
     */
    private const MASK_PLACEHOLDER = '••••••••';

    /**
     * All allowed config keys.
     *
     * @var list<string>
     */
    public const ALLOWED_KEYS = [
        'r2_endpoint',
        'r2_access_key',
        'r2_secret_key',
        'r2_bucket',
        'r2_region',
        'upload_chunk_size',
        'max_items_per_folder',
        'max_storage_bytes',
        'trash_retention_days',
        'activity_log_retention_days',
        'email_log_retention_days',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'smtp_from_address',
        'smtp_from_name',
        'branding.app_name',
        'branding.copyright_text',
        'branding.logo_path',
        'branding.favicon_path',
        'branding.tag_line',
        'backup_enabled',
        'backup_schedule_type',
        'backup_time',
        'backup_day_of_month',
        'backup_retention_days',
        'backup_keep_forever',
        'backup_notification_email',
    ];

    /**
     * Mapping from system config keys to .env variable names (fallback).
     *
     * @var array<string, string>
     */
    private const ENV_FALLBACK = [
        'r2_endpoint' => 'AWS_ENDPOINT',
        'r2_access_key' => 'AWS_ACCESS_KEY_ID',
        'r2_secret_key' => 'AWS_SECRET_ACCESS_KEY',
        'r2_bucket' => 'AWS_BUCKET',
        'r2_region' => 'AWS_DEFAULT_REGION',
        'trash_retention_days' => 'TRASH_RETENTION_DAYS',
        'activity_log_retention_days' => 'ACTIVITY_LOG_RETENTION_DAYS',
        'email_log_retention_days' => 'EMAIL_LOG_RETENTION_DAYS',
        'smtp_host' => 'MAIL_HOST',
        'smtp_port' => 'MAIL_PORT',
        'smtp_username' => 'MAIL_USERNAME',
        'smtp_password' => 'MAIL_PASSWORD',
        'smtp_encryption' => 'MAIL_SCHEME',
        'smtp_from_address' => 'MAIL_FROM_ADDRESS',
        'smtp_from_name' => 'MAIL_FROM_NAME',
    ];

    /**
     * Get all configs with secrets masked.
     * Shows DB value if set, otherwise shows .env fallback.
     *
     * @return Collection<int, array{key: string, value: ?string, is_secret: bool, source: string, updated_at: ?string}>
     */
    public function getAll(): Collection
    {
        $dbConfigs = SystemConfig::all()->keyBy('key');

        return collect(self::ALLOWED_KEYS)->map(function (string $key) use ($dbConfigs) {
            $dbConfig = $dbConfigs->get($key);

            if ($dbConfig && $dbConfig->value !== null) {
                return [
                    'key' => $key,
                    'value' => $dbConfig->is_secret ? '••••••••' : $dbConfig->value,
                    'is_secret' => $dbConfig->is_secret,
                    'source' => 'database',
                    'updated_at' => $dbConfig->updated_at?->toIso8601String(),
                ];
            }

            $envValue = $this->getEnvFallback($key);
            $isSecret = in_array($key, self::SENSITIVE_KEYS, true);

            return [
                'key' => $key,
                'value' => $isSecret && $envValue !== null ? '••••••••' : $envValue,
                'is_secret' => $isSecret,
                'source' => $envValue !== null ? 'env' : 'unset',
                'updated_at' => null,
            ];
        })->values();
    }

    /**
     * Get a single config value (decrypted if secret).
     * Falls back to .env if not set in DB.
     */
    public function get(string $key): ?string
    {
        $config = SystemConfig::query()->where('key', $key)->first();

        if ($config && $config->value !== null) {
            if ($config->is_secret) {
                return Crypt::decryptString($config->value);
            }

            return $config->value;
        }

        return $this->getEnvFallback($key);
    }

    /**
     * Resolve the effective value for a config key (DB → .env).
     * Alias for get() — explicit name for clarity.
     */
    public function resolve(string $key): ?string
    {
        return $this->get($key);
    }

    /**
     * Set a single config value (encrypted if sensitive key).
     */
    public function set(string $key, ?string $value): SystemConfig
    {
        $isSecret = in_array($key, self::SENSITIVE_KEYS, true);

        // Skip if frontend sent back the masked placeholder — don't overwrite real credential
        if ($isSecret && $value === self::MASK_PLACEHOLDER) {
            return SystemConfig::query()->where('key', $key)->firstOrNew(['key' => $key]);
        }

        $storedValue = $value;
        if ($isSecret && $value !== null) {
            $storedValue = Crypt::encryptString($value);
        }

        return SystemConfig::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $storedValue,
                'is_secret' => $isSecret,
            ],
        );
    }

    /**
     * Bulk-set multiple config values.
     *
     * @param  array<int, array{key: string, value: ?string}>  $configs
     */
    public function bulkSet(array $configs): void
    {
        foreach ($configs as $config) {
            $this->set($config['key'], $config['value']);
        }
    }

    /**
     * Get the .env fallback value for a config key.
     */
    private function getEnvFallback(string $key): ?string
    {
        $envKey = self::ENV_FALLBACK[$key] ?? null;

        if ($envKey === null) {
            return null;
        }

        $value = env($envKey);

        return $value !== null ? (string) $value : null;
    }
}
