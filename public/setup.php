<?php
/*
|--------------------------------------------------------------------------
| vDrive – Secure Web Installer
|--------------------------------------------------------------------------
| One-time-use installer. Blocked after APP_INSTALLED=true or APP_KEY set.
| Delete this file immediately after installation completes.
*/

// ── Security Gate ────────────────────────────────────────
$basePath = dirname(__DIR__);
$envPath  = $basePath . '/.env';
$blocked  = false;

if (file_exists($envPath)) {
    $raw = file_get_contents($envPath);
    $installed   = (bool) preg_match('/^APP_INSTALLED\s*=\s*true$/mi', $raw);
    $hasKey      = (bool) preg_match('/^APP_KEY\s*=\s*.+$/m', $raw);
    $explicitOff = (bool) preg_match('/^APP_INSTALLED\s*=\s*false$/mi', $raw);
    if ($installed || ($hasKey && !$explicitOff)) {
        $blocked = true;
    }
}

if ($blocked) {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Locked</title>'
      . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
      . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">'
      . '</head>'
      . '<body style="font-family:Inter,system-ui,sans-serif;text-align:center;padding:100px 20px;background:#f1f5f9;color:#020817">'
      . '<div style="max-width:440px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:48px 32px">'
      . '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 16px"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>'
      . '<h1 style="font-size:20px;font-weight:600;margin-bottom:8px">Installation Locked</h1>'
      . '<p style="color:#64748b;font-size:14px;line-height:1.6">Blue Coral has already been installed.</p>'
      . '<p style="color:#94a3b8;font-size:13px;margin-top:16px">To re-install, set <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px">APP_INSTALLED=false</code> in .env and clear APP_KEY.</p>'
      . '<p style="color:#94a3b8;font-size:11px;margin-top:24px">&copy; 2017&ndash;2026 Blue Coral. All rights reserved.</p>'
      . '</div></body></html>');
}

// ── Session & CSRF ───────────────────────────────────────
session_start();
if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['_csrf'];

function verifyCsrf(string $token): void {
    if ($token !== ($_SESSION['_csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// ── Environment Check ────────────────────────────────────
function checkEnvironment(string $basePath): array {
    $checks = [];

    // PHP version
    $checks['PHP >= 8.2'] = version_compare(PHP_VERSION, '8.2.0', '>=');

    // Extensions
    foreach (['pdo_sqlite','openssl','fileinfo','curl','mbstring','zip'] as $ext) {
        $checks["ext-{$ext}"] = extension_loaded($ext);
    }

    // Writable directories
    foreach (['storage', 'bootstrap/cache'] as $dir) {
        $full = $basePath . '/' . $dir;
        $checks["{$dir}/ writable"] = is_dir($full) && is_writable($full);
    }

    return $checks;
}

$envChecks = checkEnvironment($basePath);
$envAllPass = !in_array(false, $envChecks, true);

// ── AJAX Test Handlers ───────────────────────────────────
if (isset($_GET['action']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    verifyCsrf($input['_token'] ?? '');

    try {
        switch ($_GET['action']) {
            case 'test_db':
                $dbPath = trim($input['db_path'] ?? '');
                if (!$dbPath) $dbPath = $basePath . '/database/database.sqlite';
                $dir = dirname($dbPath);
                if (!is_dir($dir)) throw new RuntimeException("Directory does not exist: {$dir}");
                if (!is_writable($dir)) throw new RuntimeException("Directory not writable: {$dir}");
                if (!file_exists($dbPath)) touch($dbPath);
                $pdo = new PDO("sqlite:{$dbPath}");
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec('CREATE TABLE IF NOT EXISTS _setup_test (id INTEGER)');
                $pdo->exec('DROP TABLE IF EXISTS _setup_test');
                echo json_encode(['ok' => true, 'message' => 'SQLite connection successful']);
                break;

            case 'reset_db':
                $dbPath = trim($input['db_path'] ?? '');
                if (!$dbPath) $dbPath = $basePath . '/database/database.sqlite';
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    if (!mkdir($dir, 0755, true)) throw new RuntimeException("Cannot create directory: {$dir}");
                }
                if (!is_writable($dir)) throw new RuntimeException("Directory not writable: {$dir}");

                if (file_exists($dbPath) && filesize($dbPath) > 0) {
                    unlink($dbPath);
                    touch($dbPath);
                    chmod($dbPath, 0664);
                    echo json_encode(['ok' => true, 'message' => 'Database cleared and recreated']);
                } elseif (file_exists($dbPath)) {
                    echo json_encode(['ok' => true, 'message' => 'Database is already empty']);
                } else {
                    touch($dbPath);
                    chmod($dbPath, 0664);
                    echo json_encode(['ok' => true, 'message' => 'New database file created']);
                }
                break;

            case 'test_r2':
                require_once $basePath . '/vendor/autoload.php';
                $client = new Aws\S3\S3Client([
                    'region'                  => $input['r2_region'] ?? 'auto',
                    'version'                 => 'latest',
                    'endpoint'                => $input['r2_endpoint'] ?? '',
                    'use_path_style_endpoint' => true,
                    'credentials' => [
                        'key'    => $input['r2_key'] ?? '',
                        'secret' => $input['r2_secret'] ?? '',
                    ],
                ]);
                $bucket  = $input['r2_bucket'] ?? '';
                $testKey = '_setup_test_' . bin2hex(random_bytes(8));
                $client->putObject(['Bucket' => $bucket, 'Key' => $testKey, 'Body' => 'test']);
                $client->deleteObject(['Bucket' => $bucket, 'Key' => $testKey]);
                echo json_encode(['ok' => true, 'message' => 'R2 connection successful – put & delete OK']);
                break;

            case 'purge_r2':
                require_once $basePath . '/vendor/autoload.php';
                $client = new Aws\S3\S3Client([
                    'region'                  => $input['r2_region'] ?? 'auto',
                    'version'                 => 'latest',
                    'endpoint'                => $input['r2_endpoint'] ?? '',
                    'use_path_style_endpoint' => true,
                    'credentials' => [
                        'key'    => $input['r2_key'] ?? '',
                        'secret' => $input['r2_secret'] ?? '',
                    ],
                ]);
                $bucket = $input['r2_bucket'] ?? '';
                $totalDeleted = 0;
                $continuationToken = null;
                do {
                    $params = ['Bucket' => $bucket, 'MaxKeys' => 1000];
                    if ($continuationToken) $params['ContinuationToken'] = $continuationToken;
                    $result = $client->listObjectsV2($params);
                    $objects = $result['Contents'] ?? [];
                    if (!empty($objects)) {
                        $deleteKeys = array_map(fn($o) => ['Key' => $o['Key']], $objects);
                        $client->deleteObjects([
                            'Bucket' => $bucket,
                            'Delete' => ['Objects' => $deleteKeys, 'Quiet' => true],
                        ]);
                        $totalDeleted += count($objects);
                    }
                    $continuationToken = $result['NextContinuationToken'] ?? null;
                } while ($result['IsTruncated'] ?? false);
                echo json_encode(['ok' => true, 'message' => "Deleted {$totalDeleted} objects from R2 bucket"]);
                break;

            case 'disable_defaults':
                require_once $basePath . '/vendor/autoload.php';
                $app = require $basePath . '/bootstrap/app.php';
                $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
                $kernel->bootstrap();
                $defaultEmails = ['admin@bluecoral.vn', 'user@bluecoral.vn'];
                $updated = App\Models\User::whereIn('email', $defaultEmails)
                    ->update(['status' => 'disabled', 'disabled_at' => now()]);
                echo json_encode(['ok' => true, 'message' => "Disabled {$updated} default account(s)"]);
                break;

            case 'test_smtp':
                $email = trim($input['smtp_from'] ?? '');
                if (!$email) $email = 'admin@bluecoral.vn';
                $host = $input['smtp_host'] ?? '';
                $port = (int) ($input['smtp_port'] ?? 587);
                $enc  = $input['smtp_encryption'] ?? '';

                // Quick socket test first
                $fp = @fsockopen(($enc === 'ssl' ? 'ssl://' : '') . $host, $port, $errno, $errstr, 10);
                if (!$fp) throw new RuntimeException("Cannot connect to {$host}:{$port} – {$errstr}");
                $banner = fgets($fp, 512);
                fclose($fp);

                // Try sending via Symfony Mailer
                require_once $basePath . '/vendor/autoload.php';
                $scheme = match($enc) { 'ssl','tls' => 'smtps', default => 'smtp' };
                $user = urlencode($input['smtp_username'] ?? '');
                $pass = urlencode($input['smtp_password'] ?? '');
                $dsn  = "{$scheme}://{$user}:{$pass}@{$host}:{$port}";
                $transport = Symfony\Component\Mailer\Transport::fromDsn($dsn);
                $mailer = new Symfony\Component\Mailer\Mailer($transport);
                $msg = (new Symfony\Component\Mime\Email())
                    ->from($input['smtp_from'] ?? "noreply@{$host}")
                    ->to($email)
                    ->subject('vDrive – SMTP Test')
                    ->text('This is a test email from vDrive installer. If you received this, SMTP is working correctly.');
                $mailer->send($msg);
                echo json_encode(['ok' => true, 'message' => "Test email sent to {$email}"]);
                break;

            default:
                echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Install Handler ──────────────────────────────────────
$installResult = null;
$installErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_install'])) {
    if (($_POST['_token'] ?? '') !== $csrf) die('CSRF mismatch');

    try {
        $p = $_POST;

        // 1. Build .env content
        $appName = addcslashes(trim($p['app_name'] ?? 'VDrive'), '"');
        $envContent = implode("\n", [
            "APP_NAME=\"{$appName}\"",
            "APP_ENV=production",
            "APP_KEY=",
            "APP_DEBUG=false",
            "APP_URL=" . trim($p['app_url'] ?? 'https://localhost'),
            "APP_INSTALLED=false",
            "",
            "LOG_CHANNEL=stack",
            "LOG_STACK=single",
            "LOG_DEPRECATIONS_CHANNEL=null",
            "LOG_LEVEL=warning",
            "",
            "DB_CONNECTION=sqlite",
            "DB_DATABASE=" . trim($p['db_path'] ?: $basePath . '/database/database.sqlite'),
            "",
            "BROADCAST_CONNECTION=log",
            "FILESYSTEM_DISK=local",
            "QUEUE_CONNECTION=database",
            "CACHE_STORE=database",
            "HASH_DRIVER=argon2id",
            "",
            "JWT_SECRET=",
            "JWT_ALGO=HS256",
            "",
            "AWS_ACCESS_KEY_ID=" . trim($p['r2_key'] ?? ''),
            "AWS_SECRET_ACCESS_KEY=" . trim($p['r2_secret'] ?? ''),
            "AWS_DEFAULT_REGION=" . trim($p['r2_region'] ?? 'apac'),
            "AWS_BUCKET=" . trim($p['r2_bucket'] ?? ''),
            "AWS_ENDPOINT=" . trim($p['r2_endpoint'] ?? ''),
            "AWS_USE_PATH_STYLE_ENDPOINT=true",
            "",
            "TRASH_RETENTION_DAYS=7",
            "ACTIVITY_LOG_RETENTION_DAYS=7",
            "EMAIL_LOG_RETENTION_DAYS=7",
            "",
            "MAIL_MAILER=smtp",
            "MAIL_HOST=" . trim($p['smtp_host'] ?? ''),
            "MAIL_PORT=" . trim($p['smtp_port'] ?? '587'),
            "MAIL_USERNAME=" . trim($p['smtp_username'] ?? 'null'),
            "MAIL_PASSWORD=" . trim($p['smtp_password'] ?? 'null'),
            "MAIL_SCHEME=" . (trim($p['smtp_encryption'] ?? '') ?: 'null'),
            "MAIL_FROM_ADDRESS=" . trim($p['smtp_from'] ?? "noreply@" . parse_url(trim($p['app_url'] ?? ''), PHP_URL_HOST)),
            "MAIL_FROM_NAME=\"\${APP_NAME}\"",
            "",
        ]);

        // 2. Write .env
        $dbPath = trim($p['db_path'] ?: $basePath . '/database/database.sqlite');
        if (!file_exists($dbPath)) {
            $dir = dirname($dbPath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            touch($dbPath);
        }
        file_put_contents($envPath, $envContent);

        // 3. Bootstrap Laravel
        require_once $basePath . '/vendor/autoload.php';
        $app = require $basePath . '/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        $steps = [];

        // 4. Generate APP_KEY
        $currentKey = config('app.key');
        if (empty($currentKey) || $currentKey === '') {
            Illuminate\Support\Facades\Artisan::call('key:generate', ['--force' => true]);
            $steps[] = '✓ APP_KEY generated';
        } else {
            $steps[] = '→ APP_KEY already exists';
        }

        // 5. Generate JWT_SECRET
        $currentJwt = config('jwt.secret');
        if (empty($currentJwt) || $currentJwt === '') {
            Illuminate\Support\Facades\Artisan::call('jwt:secret', ['--force' => true]);
            $steps[] = '✓ JWT_SECRET generated';
        } else {
            $steps[] = '→ JWT_SECRET already exists';
        }

        // 6. Run Artisan commands
        Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        $steps[] = '✓ Migrations completed';

        Illuminate\Support\Facades\Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\RolePermissionSeeder',
            '--force' => true,
        ]);
        $steps[] = '✓ Roles & permissions seeded';

        // 7. Create default users
        $defaultUsers = [
            [
                'name'     => 'Blue Coral',
                'email'    => 'admin@bluecoral.vn',
                'password' => 'admin',
                'quota_limit_bytes' => null, // unlimited
                'status'   => 'active',
                'role'     => 'admin',
            ],
            [
                'name'     => 'A member of Blue Coral',
                'email'    => 'user@bluecoral.vn',
                'password' => 'user',
                'quota_limit_bytes' => 10485760, // 10 MB
                'status'   => 'active',
                'role'     => 'user',
            ],
        ];

        foreach ($defaultUsers as $userData) {
            $roleName = $userData['role'];
            unset($userData['role']);
            $u = App\Models\User::where('email', $userData['email'])->first();
            if ($u) {
                $steps[] = "→ {$userData['email']} already exists";
            } else {
                $u = App\Models\User::create($userData);
                $steps[] = "✓ {$userData['email']} created";
            }
            $role = App\Models\Role::where('slug', $roleName)->first();
            if ($role) {
                $u->roles()->syncWithoutDetaching([$role->id]);
                $steps[] = "✓ {$roleName} role assigned to {$userData['email']}";
            }
        }

        // 7b. Seed system_configs table (ensure DB ↔ .env consistency)
        $configService = new App\Services\SystemConfigService();
        $configMap = [
            'r2_endpoint'      => trim($p['r2_endpoint'] ?? ''),
            'r2_access_key'    => trim($p['r2_key'] ?? ''),
            'r2_secret_key'    => trim($p['r2_secret'] ?? ''),
            'r2_bucket'        => trim($p['r2_bucket'] ?? ''),
            'r2_region'        => trim($p['r2_region'] ?? 'apac'),
            'smtp_host'        => trim($p['smtp_host'] ?? ''),
            'smtp_port'        => trim($p['smtp_port'] ?? '587'),
            'smtp_username'    => trim($p['smtp_username'] ?? ''),
            'smtp_password'    => trim($p['smtp_password'] ?? ''),
            'smtp_encryption'  => trim($p['smtp_encryption'] ?? ''),
            'smtp_from_address' => trim($p['smtp_from'] ?? ''),
        ];
        $seededCount = 0;
        foreach ($configMap as $key => $value) {
            if ($value !== '' && $value !== 'null') {
                $configService->set($key, $value);
                $seededCount++;
            }
        }
        $steps[] = "✓ {$seededCount} system config(s) synced to database";

        // 8. Post-install commands
        try { Illuminate\Support\Facades\Artisan::call('storage:link', ['--force' => true]); } catch (Throwable $e) { /* may already exist */ }
        $steps[] = '✓ Storage linked';

        Illuminate\Support\Facades\Artisan::call('config:cache');
        $steps[] = '✓ Config cached';
        Illuminate\Support\Facades\Artisan::call('route:cache');
        $steps[] = '✓ Routes cached';
        Illuminate\Support\Facades\Artisan::call('view:cache');
        $steps[] = '✓ Views cached';

        // 9. Set APP_INSTALLED=true
        $finalEnv = file_get_contents($envPath);
        $finalEnv = preg_replace('/^APP_INSTALLED\s*=\s*.*$/m', 'APP_INSTALLED=true', $finalEnv);
        file_put_contents($envPath, $finalEnv);
        $steps[] = '✓ APP_INSTALLED set to true';

        // Re-cache config with the updated flag
        Illuminate\Support\Facades\Artisan::call('config:cache');

        $_SESSION['_installed'] = true;
        $installResult = [
            'success' => true,
            'steps'   => $steps,
            'app_url' => trim($p['app_url'] ?? ''),
        ];

    } catch (Throwable $e) {
        $installErrors[] = $e->getMessage();
    }
}

// ── Render HTML ──────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>vDrive – Installer</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#f1f5f9;--surface:#ffffff;--border:#e2e8f0;--border-focus:#020817;
  --text:#020817;--text-secondary:#64748b;--text-muted:#94a3b8;
  --primary:#020817;--primary-hover:#0f172a;--primary-light:#f1f5f9;
  --success:#059669;--success-bg:#ecfdf5;--success-border:#a7f3d0;
  --danger:#dc2626;--danger-bg:#fef2f2;--danger-border:#fecaca;
  --warn:#d97706;--warn-bg:#fffbeb;--warn-border:#fde68a;
  --radius:8px;--radius-lg:12px;
  --font:'Inter',system-ui,-apple-system,sans-serif;
  --shadow:0 1px 3px rgba(0,0,0,.04),0 1px 2px rgba(0,0,0,.03);
  --shadow-lg:0 4px 12px rgba(0,0,0,.08);
}
body{background:var(--bg);color:var(--text);font-family:var(--font);line-height:1.6;min-height:100vh;padding:40px 20px;font-size:14px;-webkit-font-smoothing:antialiased}
.container{max-width:640px;margin:0 auto}

/* Card */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px 28px;margin-bottom:16px;box-shadow:var(--shadow)}
.card-header{display:flex;align-items:center;gap:10px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border)}
.card-header h2{font-size:15px;font-weight:600;color:var(--text)}
.step-num{background:var(--primary);color:#fff;width:26px;height:26px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;flex-shrink:0}
.step-num.done{background:var(--success)}

/* Check list */
.check-list{list-style:none}
.check-list li{padding:7px 0;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary)}
.check-list li.pass{color:var(--text)}.check-list li.fail{color:var(--danger)}
.check-icon{width:18px;height:18px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:10px;flex-shrink:0}
.check-icon.pass{background:var(--border);color:var(--text)}
.check-icon.fail{background:var(--danger-bg);color:var(--danger)}

/* Forms */
.section-label{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;margin:24px 0 12px}
.section-label:first-of-type{margin-top:0}
label{display:block;font-size:13px;color:var(--text-secondary);margin-bottom:4px;margin-top:12px;font-weight:500}
label:first-child{margin-top:0}
input[type=text],input[type=email],input[type=password],input[type=number],select{
  width:100%;padding:8px 12px;background:var(--surface);border:1px solid var(--border);
  border-radius:var(--radius);color:var(--text);font-size:13px;font-family:var(--font);
  outline:none;transition:border-color .15s,box-shadow .15s
}
input:focus,select:focus{border-color:var(--border-focus);box-shadow:0 0 0 3px rgba(2,8,23,.08)}
input::placeholder{color:var(--text-muted)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}

/* Buttons */
.btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 16px;border:none;border-radius:var(--radius);font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;font-family:var(--font);gap:6px;line-height:1.4}
.btn-primary{background:var(--primary);color:#fff}.btn-primary:hover{background:var(--primary-hover)}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--text-secondary)}.btn-ghost:hover{border-color:var(--text-muted);color:var(--text)}
.btn-danger{background:var(--danger);color:#fff}.btn-danger:hover{background:#b91c1c}
.btn:disabled{opacity:.4;cursor:not-allowed;pointer-events:none}
.btn-group{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}
.btn-sm{padding:6px 12px;font-size:12px}

/* Alerts */
.alert{padding:12px 16px;border-radius:var(--radius);margin-bottom:16px;font-size:13px;line-height:1.5}
.alert-danger{background:var(--danger-bg);border:1px solid var(--danger-border);color:var(--danger)}
.alert-warn{background:var(--warn-bg);border:1px solid var(--warn-border);color:var(--warn)}
.alert-success{background:var(--success-bg);border:1px solid var(--success-border);color:var(--success)}

/* Step log */
.step-log{list-style:none;font-size:13px}.step-log li{padding:4px 0;color:var(--text-secondary)}

/* Code blocks */
pre.code-block{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:10px 14px;font-size:12px;overflow-x:auto;color:var(--text);margin:6px 0;cursor:pointer;position:relative;font-family:'SF Mono',Monaco,Consolas,monospace;transition:border-color .15s}
pre.code-block:hover{border-color:#94a3b8}
pre.code-block::after{content:'Copy';position:absolute;top:8px;right:10px;font-size:10px;color:var(--text-muted);font-family:var(--font);font-weight:500;opacity:0;transition:opacity .15s}
pre.code-block:hover::after{opacity:1}

/* Toast notifications */
.toast-container{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast{pointer-events:auto;min-width:320px;max-width:420px;padding:14px 16px;border-radius:var(--radius);font-size:13px;line-height:1.5;display:flex;align-items:flex-start;gap:10px;box-shadow:var(--shadow-lg);animation:toastIn .3s ease;border:1px solid}
.toast.success{background:var(--success-bg);border-color:var(--success-border);color:var(--success)}
.toast.error{background:var(--danger-bg);border-color:var(--danger-border);color:var(--danger)}
.toast-icon{flex-shrink:0;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;margin-top:1px}
.toast.success .toast-icon{background:var(--success);color:#fff}
.toast.error .toast-icon{background:var(--danger);color:#fff}
.toast-body{flex:1}
.toast-title{font-weight:600;margin-bottom:2px}
.toast-msg{opacity:.85;font-size:12px}
.toast-close{flex-shrink:0;cursor:pointer;opacity:.5;font-size:16px;line-height:1;padding:0 2px;background:none;border:none;color:inherit;font-family:var(--font)}
.toast-close:hover{opacity:1}
.toast.removing{animation:toastOut .25s ease forwards}
@keyframes toastIn{from{opacity:0;transform:translateX(40px)}to{opacity:1;transform:translateX(0)}}
@keyframes toastOut{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(40px)}}

.hidden{display:none}
.footer{text-align:center;color:var(--text-secondary);font-size:13px;padding-top:20px}
</style>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>
<div class="container">

<?php if (!empty($installErrors)): ?>
<div class="alert alert-danger">
    <strong>Installation failed:</strong><br>
    <?= htmlspecialchars(implode('<br>', $installErrors)) ?>
</div>
<?php endif; ?>

<?php if ($installResult && $installResult['success']): ?>
<!-- ═══ STEP: Finalization ═══ -->
<div class="card">
    <div class="card-header">
        <span class="step-num done">✓</span>
        <h2>Installation Complete</h2>
    </div>
    <ul class="step-log">
        <?php foreach ($installResult['steps'] as $s): ?>
        <li><?= htmlspecialchars($s) ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="card">
    <div class="card-header"><h2>Your Endpoints</h2></div>
    <p class="section-label">App Base</p>
    <pre class="code-block" onclick="copyText(this)"><?= htmlspecialchars($installResult['app_url']) ?></pre>
    <p class="section-label">API Base URL</p>
    <pre class="code-block" onclick="copyText(this)"><?= htmlspecialchars($installResult['app_url']) ?>/api</pre>
    <p class="section-label">API Documentation</p>
    <pre class="code-block" onclick="copyText(this)"><?= htmlspecialchars($installResult['app_url']) ?>/docs/api</pre>
</div>

<div class="card">
    <div class="card-header"><h2>Cron Jobs</h2></div>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">Add these to your server's crontab:</p>
    <p class="section-label">Scheduler (required)</p>
    <pre class="code-block" onclick="copyText(this)">* * * * * cd <?= htmlspecialchars($basePath) ?> && php artisan schedule:run >> /dev/null 2>&1</pre>
    <p class="section-label">Queue Worker (if using async queue)</p>
    <pre class="code-block" onclick="copyText(this)">* * * * * cd <?= htmlspecialchars($basePath) ?> && php artisan queue:work --sleep=3 --tries=3 --max-time=3600 >> /dev/null 2>&1</pre>
</div>

<div class="card" id="defaultAccountsCard">
    <div class="card-header">
        <h2>Default Accounts</h2>
    </div>
    <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">Two default accounts were created during installation:</p>
    <ul style="list-style:none;font-size:13px;color:var(--text)">
        <li style="padding:6px 0"><strong>Admin:</strong> admin@bluecoral.vn / admin (Unlimited quota)</li>
        <li style="padding:6px 0"><strong>User:</strong> user@bluecoral.vn / user (10 MB quota)</li>
    </ul>
    <div class="alert alert-warn" style="margin-top:16px">
        For security, you should disable these accounts if you don't need them.
    </div>
    <div class="btn-group" style="margin-top:12px">
        <button class="btn btn-danger btn-sm" onclick="disableDefaults()">Yes, disable them</button>
        <button class="btn btn-ghost btn-sm" onclick="dismissDefaultsCard()">No, keep them</button>
    </div>
</div>

<div class="card" style="border-color:var(--danger-border)">
    <div class="card-header" style="border-bottom-color:var(--danger-border)">
        <h2>Security Checklist</h2>
    </div>
    <div class="alert alert-danger" style="margin-bottom:16px">
        <strong>Delete setup.php immediately!</strong>
    </div>
    <ul class="check-list" style="font-size:13px">
        <li class="fail"><span class="check-icon fail">!</span> Delete <code>public/setup.php</code> from your server <strong>now</strong></li>
        <li class="fail"><span class="check-icon fail">!</span> Verify <code>APP_DEBUG=false</code> in .env</li>
        <li><span class="check-icon pass">-</span> Enable HTTPS on your domain</li>
        <li><span class="check-icon pass">-</span> Set correct directory permissions (storage/ 755, .env 600)</li>
        <li><span class="check-icon pass">-</span> Set up the cron jobs shown above</li>
        <li><span class="check-icon pass">-</span> Configure your server firewall</li>
    </ul>
    <div class="btn-group" style="margin-top:20px">
        <button class="btn btn-danger" onclick="confirmRemoval()">
            I have removed setup.php
        </button>
    </div>
    <p id="confirmMsg" class="hidden" style="margin-top:12px;color:var(--success);font-size:13px">
        Redirecting to your application...
    </p>
</div>

<?php else: ?>
<!-- ═══ STEP 1: Environment Check ═══ -->
<div class="card" id="step1">
    <div class="card-header">
        <span class="step-num">1</span>
        <h2>Environment Check</h2>
    </div>
    <ul class="check-list">
        <?php foreach ($envChecks as $name => $pass): ?>
        <li class="<?= $pass ? 'pass' : 'fail' ?>">
            <span class="check-icon <?= $pass ? 'pass' : 'fail' ?>"><?= $pass ? '✓' : '✕' ?></span>
            <?= htmlspecialchars($name) ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($envAllPass): ?>
    <div class="btn-group">
        <button class="btn btn-primary" onclick="showStep(2)">Continue →</button>
    </div>
    <?php else: ?>
    <div class="alert alert-danger" style="margin-top:16px">
        Please fix the failed checks above before continuing.
    </div>
    <?php endif; ?>
</div>

<!-- ═══ STEPS 2+3: Configuration & Tests ═══ -->
<div class="card hidden" id="step2">
    <div class="card-header">
        <span class="step-num">2</span>
        <h2>Configuration</h2>
    </div>
    <form method="POST" id="installForm" onsubmit="return confirmInstall()">
        <input type="hidden" name="_token" value="<?= $csrf ?>">
        <input type="hidden" name="_install" value="1">

        <p class="section-label">Application</p>
        <div class="row">
            <div><label>App Name</label><input type="text" name="app_name" value="Blue Coral" required></div>
            <div><label>App URL</label><input type="text" name="app_url" value="<?= htmlspecialchars(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?>" required></div>
        </div>

        <p class="section-label">Database (SQLite)</p>
        <label>Database Path <span style="color:var(--text-muted);font-weight:400">(leave empty for default)</span></label>
        <input type="text" name="db_path" placeholder="<?= htmlspecialchars($basePath) ?>/database/database.sqlite">
        <div class="btn-group">
            <button type="button" class="btn btn-ghost btn-sm" onclick="testConnection('db')">Test Database</button>
            <button type="button" class="btn btn-ghost btn-sm" style="color:var(--danger)" onclick="resetDatabase()">Reset Database</button>
        </div>

        <p class="section-label">R2 Storage (S3 Compatible)</p>
        <div class="row">
            <div><label>Access Key</label><input type="text" name="r2_key" id="r2_key"></div>
            <div><label>Secret Key</label><input type="password" name="r2_secret" id="r2_secret"></div>
        </div>
        <div class="row">
            <div><label>Endpoint URL</label><input type="text" name="r2_endpoint" id="r2_endpoint" placeholder="https://....r2.cloudflarestorage.com"></div>
            <div><label>Bucket Name</label><input type="text" name="r2_bucket" id="r2_bucket"></div>
        </div>
        <label>Region</label>
        <input type="text" name="r2_region" id="r2_region" value="apac">
        <div class="btn-group">
            <button type="button" class="btn btn-ghost btn-sm" onclick="testConnection('r2')">Test R2 Connection</button>
            <button type="button" class="btn btn-ghost btn-sm" style="color:var(--danger)" onclick="purgeR2()">Purge R2 Storage</button>
        </div>

        <p class="section-label">SMTP (Email)</p>
        <div class="row">
            <div><label>SMTP Host</label><input type="text" name="smtp_host" id="smtp_host"></div>
            <div><label>SMTP Port</label><input type="number" name="smtp_port" id="smtp_port" value="587"></div>
        </div>
        <div class="row">
            <div><label>Username</label><input type="text" name="smtp_username" id="smtp_username"></div>
            <div><label>Password</label><input type="password" name="smtp_password" id="smtp_password"></div>
        </div>
        <div class="row">
            <div>
                <label>Encryption</label>
                <select name="smtp_encryption" id="smtp_encryption">
                    <option value="">None</option>
                    <option value="tls" selected>TLS</option>
                    <option value="ssl">SSL</option>
                </select>
            </div>
            <div><label>From Address</label><input type="email" name="smtp_from" id="smtp_from" placeholder="noreply@your-domain.com"></div>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-ghost btn-sm" onclick="testConnection('smtp')">Test SMTP</button>
        </div>

        <p class="section-label">Default Accounts</p>
        <div class="alert alert-warn" style="margin-bottom:0">
            Two default accounts will be created automatically:<br>
            <strong>Admin:</strong> admin@bluecoral.vn / admin (Unlimited)<br>
            <strong>User:</strong> user@bluecoral.vn / user (10 MB)
        </div>

        <div class="btn-group" style="margin-top:28px;padding-top:20px;border-top:1px solid var(--border)">
            <button type="button" class="btn btn-ghost" onclick="showStep(1)">← Back</button>
            <button type="submit" class="btn btn-primary" id="installBtn">Install</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="footer">&copy; 2026 vDrive - Powered by <a href="https://bluecoral.vn/?utm_source=vdrive&utm_medium=copyright&utm_campaign=opensource" target="_blank" rel="noopener noreferrer" style="color:#0f172a;text-decoration:none;font-weight:500">Blue Coral</a></div>
</div>

<script>
/* ── Toast Notification System ── */
function showToast(type, title, message, duration = 5000) {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.innerHTML = `
        <span class="toast-icon">${type === 'success' ? '✓' : '!'}</span>
        <div class="toast-body">
            <div class="toast-title">${title}</div>
            <div class="toast-msg">${message}</div>
        </div>
        <button class="toast-close" onclick="dismissToast(this.parentElement)">×</button>
    `;
    container.appendChild(toast);
    setTimeout(() => dismissToast(toast), duration);
}

function dismissToast(toast) {
    if (!toast || toast.classList.contains('removing')) return;
    toast.classList.add('removing');
    setTimeout(() => toast.remove(), 250);
}

/* ── Step Navigation ── */
function showStep(n) {
    document.getElementById('step1').classList.toggle('hidden', n !== 1);
    document.getElementById('step2').classList.toggle('hidden', n !== 2);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ── Form Data ── */
function getFormData() {
    const fd = new FormData(document.getElementById('installForm'));
    const data = {};
    fd.forEach((v, k) => data[k] = v);
    return data;
}

/* ── Connection Test ── */
const testLabels = { db: 'Database', r2: 'R2 Connection', smtp: 'SMTP' };

async function testConnection(type) {
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Testing...';

    try {
        const data = getFormData();
        const res = await fetch('setup.php?action=test_' + type, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const json = await res.json();
        if (json.ok) {
            showToast('success', testLabels[type] + ' — Passed', json.message);
        } else {
            showToast('error', testLabels[type] + ' — Failed', json.error, 8000);
        }
    } catch (e) {
        showToast('error', testLabels[type] + ' — Error', 'Request failed: ' + e.message, 8000);
    }

    btn.disabled = false;
    btn.textContent = 'Test ' + testLabels[type];
}

/* ── Reset Database ── */
async function resetDatabase() {
    if (!confirm('This will delete all data in the SQLite database. Continue?')) return;
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Resetting...';

    try {
        const data = getFormData();
        const res = await fetch('setup.php?action=reset_db', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const json = await res.json();
        if (json.ok) {
            showToast('success', 'Database Reset', json.message);
        } else {
            showToast('error', 'Reset Failed', json.error, 8000);
        }
    } catch (e) {
        showToast('error', 'Reset Error', 'Request failed: ' + e.message, 8000);
    }

    btn.disabled = false;
    btn.textContent = 'Reset Database';
}

/* ── Install Confirmation ── */
function confirmInstall() {
    const btn = document.getElementById('installBtn');
    if (!confirm('Start installation? This will write .env, run migrations, and create default accounts.')) return false;
    btn.disabled = true;
    btn.textContent = 'Installing...';
    return true;
}

/* ── Purge R2 Storage ── */
async function purgeR2() {
    if (!confirm('This will permanently DELETE ALL files in the R2 bucket. This cannot be undone. Continue?')) return;
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Purging...';

    try {
        const data = getFormData();
        const res = await fetch('setup.php?action=purge_r2', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const json = await res.json();
        if (json.ok) {
            showToast('success', 'R2 Purged', json.message);
        } else {
            showToast('error', 'Purge Failed', json.error, 8000);
        }
    } catch (e) {
        showToast('error', 'Purge Error', 'Request failed: ' + e.message, 8000);
    }

    btn.disabled = false;
    btn.textContent = 'Purge R2 Storage';
}

/* ── Disable Default Accounts ── */
async function disableDefaults() {
    if (!confirm('Disable both default accounts (admin@bluecoral.vn and user@bluecoral.vn)?')) return;
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Disabling...';

    try {
        const res = await fetch('setup.php?action=disable_defaults', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ _token: '<?= $csrf ?>' })
        });
        const json = await res.json();
        if (json.ok) {
            showToast('success', 'Accounts Disabled', json.message);
            document.getElementById('defaultAccountsCard').style.display = 'none';
        } else {
            showToast('error', 'Disable Failed', json.error, 8000);
        }
    } catch (e) {
        showToast('error', 'Disable Error', 'Request failed: ' + e.message, 8000);
    }

    btn.disabled = false;
    btn.textContent = 'Yes, disable them';
}

function dismissDefaultsCard() {
    document.getElementById('defaultAccountsCard').style.display = 'none';
}

/* ── Copy to Clipboard ── */
function copyText(el) {
    navigator.clipboard.writeText(el.textContent.trim()).then(() => {
        showToast('success', 'Copied', 'Command copied to clipboard', 2500);
    });
}

/* ── Confirm Removal ── */
function confirmRemoval() {
    if (!confirm('Have you really deleted setup.php from your server?')) return;
    document.getElementById('confirmMsg').classList.remove('hidden');
    setTimeout(() => {
        window.location.href = '<?= $installResult ? htmlspecialchars($installResult['app_url']) : '/' ?>';
    }, 2000);
}
</script>
</body>
</html>

