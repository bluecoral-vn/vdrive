# vDrive — File Storage Backend

Modern cloud storage API built with Laravel 12, PHP 8.4, SQLite, and Cloudflare R2.

> **Storage:** vDrive supports **S3-compatible storage only** (e.g. Cloudflare R2, AWS S3, MinIO). Local filesystem storage is not supported.

## Quick Start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate
php artisan db:seed --class=RolePermissionSeeder
php artisan serve
```

## Default Accounts

After installation (via `setup.php` or `db:seed`), two default accounts are created:

| Role  | Name                    | Email              | Password | Quota     |
|-------|-------------------------|--------------------|----------|-----------|
| Admin | Blue Coral              | admin@bluecoral.vn | admin    | Unlimited |
| User  | A member of Blue Coral  | user@bluecoral.vn  | user     | 10 MB     |

> **⚠️ Security notice:** After installation, you should disable these default accounts if they are not needed.
> The `setup.php` page will prompt you to disable them after completing setup.

## Important Notes

- **Delete `public/setup.php`** immediately after installation on production.
- **Purge R2 Storage:** The `setup.php` page has a "Purge R2 Storage" button to delete all files uploaded to R2/S3. This action **cannot be undone**.
- **Reset Database:** The "Reset Database" button on `setup.php` will wipe all SQLite data.
- Ensure `APP_DEBUG=false` and `HASH_DRIVER=argon2id` in `.env` on production.

## Deploy

> **Prerequisite**: `brew install hudochenkov/sshpass/sshpass`

### Push to Staging (full reset — DB wiped, fresh install)

```bash
bash scripts/deploy-staging.sh
```

### Push to Production (code only — DB preserved)

```bash
bash scripts/deploy-prod.sh
```

### Push to both (staging → production)

```bash
bash scripts/deploy-all.sh
```

### Preview without deploying

```bash
bash scripts/deploy-staging.sh --dry-run
bash scripts/deploy-prod.sh --dry-run
```

### Environments

| Environment | URL                                  | Strategy                      |
|-------------|--------------------------------------|-------------------------------|
| Staging     | https://vdrive-staging.bluecoral.vn  | Full reset on every deploy    |
| Production  | https://demo-vdrive.bluecoral.vn     | Code only, DB preserved       |

Server credentials are stored in `.deploy.env` (gitignored).

## Tests

```bash
php artisan test                        # Feature tests
bash scripts/local_check.sh             # Full quality gate
bash scripts/local_check.sh --skip-s3   # Skip real S3 tests
```

## License

[GPL-3.0](https://www.gnu.org/licenses/gpl-3.0.html) — Powered by [Blue Coral](https://bluecoral.vn)
