#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────
# vDrive — Deploy to STAGING (Full Reset)
# ──────────────────────────────────────────────────────────
# Syncs code, resets database, runs fresh install.
# S3 credentials use PRODUCTION values.
#
# Usage:
#   bash scripts/deploy-staging.sh            # Full deploy
#   bash scripts/deploy-staging.sh --dry-run  # Preview only
# ──────────────────────────────────────────────────────────

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# ── Colors ────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

DRY_RUN=false
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=true

step() { echo -e "\n${CYAN}━━━ $1 ━━━${NC}\n"; }
pass() { echo -e "${GREEN}✓ $1${NC}"; }
fail() { echo -e "${RED}✗ $1${NC}"; exit 1; }
info() { echo -e "${YELLOW}→ $1${NC}"; }

echo -e "${BOLD}${CYAN}"
echo "╔══════════════════════════════════════════════╗"
echo "║   vDrive — Deploy to STAGING (Full Reset)   ║"
echo "╚══════════════════════════════════════════════╝"
echo -e "${NC}"

# ── Load config ──────────────────────────────────────
DEPLOY_ENV="$ROOT/.deploy.env"
if [[ ! -f "$DEPLOY_ENV" ]]; then
    fail "Missing .deploy.env — copy from plan and fill in credentials"
fi
source "$DEPLOY_ENV"

# Validate required vars
for var in STAGING_HOST STAGING_PORT STAGING_USER STAGING_PASS STAGING_PATH \
           S3_ACCESS_KEY S3_SECRET_KEY S3_REGION S3_BUCKET S3_ENDPOINT \
           STAGING_ADMIN_EMAIL STAGING_ADMIN_PASS; do
    [[ -z "${!var:-}" ]] && fail "Missing $var in .deploy.env"
done

# ── Check sshpass ────────────────────────────────────
if ! command -v sshpass &>/dev/null; then
    fail "sshpass not installed. Run: brew install hudochenkov/sshpass/sshpass"
fi

# ── SSH options with timeouts ────────────────────────
SSH_OPTS="-o StrictHostKeyChecking=no -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=3 -p ${STAGING_PORT}"
SSH_CMD="sshpass -p ${STAGING_PASS} ssh ${SSH_OPTS} ${STAGING_USER}@${STAGING_HOST}"
RSYNC_SSH="sshpass -p ${STAGING_PASS} ssh ${SSH_OPTS}"

info "Target: ${STAGING_USER}@${STAGING_HOST}:${STAGING_PATH}"

# ── Verify SSH connection ────────────────────────────
info "Testing SSH connection..."
if ! $SSH_CMD "echo 'SSH OK'" 2>/dev/null; then
    fail "Cannot connect to staging server via SSH"
fi
pass "SSH connection verified"
echo ""

# ── Step 1: Sync files ───────────────────────────────
step "1/4 — Syncing files to staging"

RSYNC_OPTS=(
    -avz --delete
    --exclude-from="$ROOT/scripts/rsync-excludes.txt"
)

if $DRY_RUN; then
    RSYNC_OPTS+=(--dry-run)
    info "DRY RUN — no files will be transferred"
fi

rsync "${RSYNC_OPTS[@]}" \
    -e "$RSYNC_SSH" \
    "$ROOT/" "${STAGING_USER}@${STAGING_HOST}:${STAGING_PATH}/"

if $DRY_RUN; then
    echo ""
    pass "Dry run complete — review the file list above"
    exit 0
fi

pass "Files synced"

# ── Step 2: Fix permissions + Reset DB ───────────────
step "2/4 — Permissions & database reset"

$SSH_CMD bash <<EOF
set -e

# ── Fix permissions ──
chmod 750 "${STAGING_PATH}"
find "${STAGING_PATH}" -type d -exec chmod 755 {} + 2>/dev/null || true
find "${STAGING_PATH}" -type f -exec chmod 644 {} + 2>/dev/null || true
chmod 664 "${STAGING_PATH}/database/database.sqlite" 2>/dev/null || true
chmod -R 775 "${STAGING_PATH}/storage" "${STAGING_PATH}/bootstrap/cache" 2>/dev/null || true
echo "✓ Permissions fixed"

# ── Reset database ──
DB_PATH="${STAGING_PATH}/database/database.sqlite"
mkdir -p "\$(dirname "\$DB_PATH")"
rm -f "\$DB_PATH" "\${DB_PATH}-wal" "\${DB_PATH}-shm"
touch "\$DB_PATH"
chmod 664 "\$DB_PATH"
echo "✓ Database reset"
EOF

pass "Permissions fixed & database reset"

# ── Step 3: Write .env (setup.php handles install) ───
step "3/4 — Writing environment config"

APP_NAME="${STAGING_APP_NAME:-vDrive - Blue Coral}"
APP_URL="${STAGING_APP_URL:-https://vdrive-staging.bluecoral.vn}"

$SSH_CMD bash <<EOF
cd "${STAGING_PATH}"

# Write fresh .env — APP_INSTALLED=false forces /setup.php
cat > .env <<ENVBLOCK
APP_NAME="${APP_NAME}"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=${APP_URL}
APP_INSTALLED=false

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DEV_CREDENTIALS=show

DB_CONNECTION=sqlite
DB_DATABASE=${STAGING_PATH}/database/database.sqlite

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database
HASH_DRIVER=argon2id

JWT_SECRET=
JWT_ALGO=HS256

AWS_ACCESS_KEY_ID=${S3_ACCESS_KEY}
AWS_SECRET_ACCESS_KEY=${S3_SECRET_KEY}
AWS_DEFAULT_REGION=${S3_REGION}
AWS_BUCKET=${S3_BUCKET}
AWS_ENDPOINT=${S3_ENDPOINT}
AWS_USE_PATH_STYLE_ENDPOINT=true

TRASH_RETENTION_DAYS=7
ACTIVITY_LOG_RETENTION_DAYS=7
EMAIL_LOG_RETENTION_DAYS=7

MAIL_MAILER=smtp
MAIL_HOST=localhost
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_SCHEME=null
MAIL_FROM_ADDRESS=noreply@vdrive.local
MAIL_FROM_NAME="\\\${APP_NAME}"
ENVBLOCK

echo "✓ .env written (APP_INSTALLED=false, DEV_CREDENTIALS=show)"

# Ensure storage directories exist
mkdir -p storage/logs
mkdir -p storage/framework/{cache/data,sessions,views}
mkdir -p bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "✓ Storage directories ready"
echo "→ Visit ${APP_URL}/setup.php to complete installation"
EOF

pass "Environment configured — setup.php will handle installation"

# ── Step 4: Verify ───────────────────────────────────
step "4/4 — Verifying deployment"

$SSH_CMD bash <<EOF
cd "${STAGING_PATH}"
echo "PHP version: \$(php -v | head -1)"
echo "APP_INSTALLED: \$(grep '^APP_INSTALLED=' .env | cut -d= -f2)"
echo "DEV_CREDENTIALS: \$(grep '^DEV_CREDENTIALS=' .env | cut -d= -f2)"
echo "DB file size: \$(wc -c < database/database.sqlite) bytes"
EOF

pass "Staging deployment complete"

echo -e "\n${BOLD}${GREEN}"
echo "╔══════════════════════════════════════════════╗"
echo "║        Staging deployed successfully!         ║"
echo "╠══════════════════════════════════════════════╣"
echo "║  APP_INSTALLED=false → setup.php required     ║"
echo "║  URL: ${APP_URL}/setup.php"
echo "╚══════════════════════════════════════════════╝"
echo -e "${NC}"
