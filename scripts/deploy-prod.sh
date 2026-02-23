#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────
# vDrive — Deploy to PRODUCTION (Code Only)
# ──────────────────────────────────────────────────────────
# Syncs code only. NEVER touches .env or database.
# Runs migrations + seeder + repair + cache after sync.
#
# Usage:
#   bash scripts/deploy-prod.sh            # Full deploy
#   bash scripts/deploy-prod.sh --dry-run  # Preview only
#   bash scripts/deploy-prod.sh --yes      # Skip confirmation
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
AUTO_YES=false
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=true ;;
        --yes|-y)  AUTO_YES=true ;;
    esac
done

step() { echo -e "\n${CYAN}━━━ $1 ━━━${NC}\n"; }
pass() { echo -e "${GREEN}✓ $1${NC}"; }
fail() { echo -e "${RED}✗ $1${NC}"; exit 1; }
info() { echo -e "${YELLOW}→ $1${NC}"; }
warn() { echo -e "${YELLOW}⚠ $1${NC}"; }

echo -e "${BOLD}${CYAN}"
echo "╔══════════════════════════════════════════════╗"
echo "║   vDrive — Deploy to PRODUCTION (Code Only) ║"
echo "╚══════════════════════════════════════════════╝"
echo -e "${NC}"

# ── Load config ──────────────────────────────────────
DEPLOY_ENV="$ROOT/.deploy.env"
if [[ ! -f "$DEPLOY_ENV" ]]; then
    fail "Missing .deploy.env — copy from plan and fill in credentials"
fi
source "$DEPLOY_ENV"

for var in PROD_HOST PROD_PORT PROD_USER PROD_PASS PROD_PATH; do
    [[ -z "${!var:-}" ]] && fail "Missing $var in .deploy.env"
done

# ── Check sshpass ────────────────────────────────────
if ! command -v sshpass &>/dev/null; then
    fail "sshpass not installed. Run: brew install hudochenkov/sshpass/sshpass"
fi

# ── SSH options with timeouts ────────────────────────
SSH_OPTS="-o StrictHostKeyChecking=no -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=3 -p ${PROD_PORT}"
SSH_CMD="sshpass -p ${PROD_PASS} ssh ${SSH_OPTS} ${PROD_USER}@${PROD_HOST}"
RSYNC_SSH="sshpass -p ${PROD_PASS} ssh ${SSH_OPTS}"

info "Target: ${PROD_USER}@${PROD_HOST}:${PROD_PATH}"

# ── Verify SSH connection ────────────────────────────
info "Testing SSH connection..."
if ! $SSH_CMD "echo 'SSH OK'" 2>/dev/null; then
    fail "Cannot connect to production server via SSH"
fi
pass "SSH connection verified"

# ── Confirmation prompt (skip with --yes or -y) ──────
if ! $DRY_RUN && ! $AUTO_YES; then
    echo ""
    echo -e "${YELLOW}⚠  You are deploying to PRODUCTION${NC}"
    read -r -p "Continue? (y/N): " confirm
    [[ "$confirm" != "y" && "$confirm" != "Y" ]] && { echo "Aborted."; exit 0; }
fi

echo ""

# ── Step 1: Sync files ───────────────────────────────
step "1/4 — Syncing files to production"

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
    "$ROOT/" "${PROD_USER}@${PROD_HOST}:${PROD_PATH}/"

if $DRY_RUN; then
    echo ""
    pass "Dry run complete — review the file list above"
    exit 0
fi

pass "Files synced"

# ── Step 2: Permissions + Migrations + Seeders ───────
step "2/4 — Permissions, migrations & seeders"

$SSH_CMD bash <<EOF
set -e

cd "${PROD_PATH}"

# ── Fix permissions ──
chmod 750 "${PROD_PATH}"
find "${PROD_PATH}" -type d -exec chmod 755 {} + 2>/dev/null || true
find "${PROD_PATH}" -type f -exec chmod 644 {} + 2>/dev/null || true
chmod 664 "${PROD_PATH}/database/database.sqlite" 2>/dev/null || true
chmod -R 775 "${PROD_PATH}/storage" "${PROD_PATH}/bootstrap/cache" 2>/dev/null || true
echo "✓ Permissions fixed"

# ── Ensure storage dirs ──
mkdir -p storage/logs
mkdir -p storage/framework/{cache/data,sessions,views}
mkdir -p bootstrap/cache
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# ── Check DB ──
if [[ ! -f database/database.sqlite ]]; then
    echo "⚠  No database found — creating fresh one"
    mkdir -p database
    touch database/database.sqlite
    chmod 664 database/database.sqlite
fi

# ── Migrations ──
php artisan migrate --force --no-interaction
echo "✓ Migrations complete"

# ── Seed roles & permissions (upsert — safe to re-run) ──
php artisan db:seed --class='Database\Seeders\RolePermissionSeeder' --force --no-interaction
echo "✓ Roles & permissions synced"
EOF

pass "Migrations & seeders complete"

# ── Step 3: Post-deploy tasks ────────────────────────
step "3/4 — Post-deploy tasks"

$SSH_CMD bash <<EOF
set -e

cd "${PROD_PATH}"

# ── Repair sensitive configs (encrypt any legacy plain-text values) ──
php artisan config:repair-secrets --no-interaction
echo "✓ Sensitive configs checked"

# ── Storage link ──
php artisan storage:link --force 2>/dev/null || true
echo "✓ Storage linked"

# ── Cache config & routes ──
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
echo "✓ Config & routes cached"

# ── Verify cron schedule ──
echo ""
echo "── Scheduled tasks ──"
php artisan schedule:list 2>/dev/null || echo "(schedule:list not available)"

echo ""
CRON_INSTALLED=\$(crontab -l 2>/dev/null | grep -c "schedule:run" || true)
if [[ "\$CRON_INSTALLED" -gt 0 ]]; then
    echo "✓ Scheduler cron is installed"
else
    echo "⚠  Scheduler cron NOT found in crontab"
    echo "   Add this to crontab (run: crontab -e):"
    echo "   * * * * * cd ${PROD_PATH} && php artisan schedule:run >> /dev/null 2>&1"
fi
EOF

pass "Post-deploy tasks complete"

# ── Step 4: Verify ───────────────────────────────────
step "4/4 — Verifying deployment"

$SSH_CMD bash <<EOF
cd "${PROD_PATH}"
echo "PHP version: \$(php -v | head -1)"
echo "Laravel version: \$(php artisan --version)"
echo "APP_INSTALLED: \$(grep '^APP_INSTALLED=' .env | cut -d= -f2)"
echo "DB file size: \$(wc -c < database/database.sqlite) bytes"

# Check queue worker
QUEUE_RUNNING=\$(ps aux 2>/dev/null | grep -c "[q]ueue:work" || true)
if [[ "\$QUEUE_RUNNING" -gt 0 ]]; then
    echo "Queue worker: running (\$QUEUE_RUNNING process(es))"
else
    echo "Queue worker: not running (optional — needed for async jobs)"
fi
EOF

pass "Production deployment complete"

echo -e "\n${BOLD}${GREEN}"
echo "╔══════════════════════════════════════════════╗"
echo "║      Production deployed successfully!        ║"
echo "╚══════════════════════════════════════════════╝"
echo -e "${NC}"
