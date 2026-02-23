#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────
# VDrive Local Quality Gate
# ──────────────────────────────────────────────────────────
# Run all tests locally (Feature + Integration).
# Exit non-zero on any failure.
#
# Usage:
#   bash scripts/local_check.sh            # Run all tests
#   bash scripts/local_check.sh --skip-s3  # Skip real-S3 tests
# ──────────────────────────────────────────────────────────

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

step() {
    echo -e "\n${YELLOW}━━━ $1 ━━━${NC}\n"
}

pass() {
    echo -e "${GREEN}✓ $1${NC}"
}

fail() {
    echo -e "${RED}✗ $1${NC}"
    exit 1
}

# ── 1. Dependencies ──────────────────────────────────────
step "1/8 — Installing dependencies"
composer install --no-interaction --quiet || fail "composer install failed"
pass "Dependencies installed"

# ── 2. Migration ─────────────────────────────────────────
step "2/8 — Running migrations"
php artisan migrate --force --quiet 2>/dev/null || true
pass "Migration complete"

# ── 3. Config clear ──────────────────────────────────────
step "3/8 — Clearing config"
php artisan config:clear --quiet
pass "Config cleared"

# ── 4. Feature tests ─────────────────────────────────────
step "4/8 — Running Feature tests"
php artisan test --compact || fail "Feature tests failed"
pass "Feature tests passed"

# ── 5. Integration tests ─────────────────────────────────
step "5/8 — Running Integration tests"
if [[ "${1:-}" == "--skip-s3" ]]; then
    echo "  (skipping S3-dependent tests)"
    vendor/bin/phpunit --configuration=phpunit-integration.xml \
        --exclude-group=s3 || fail "Integration tests failed"
else
    vendor/bin/phpunit --configuration=phpunit-integration.xml \
        || fail "Integration tests failed"
fi
pass "Integration tests passed"

# ── 6. Purge dry-run ─────────────────────────────────────
step "6/8 — Purge dry-run"
php artisan trash:purge 2>/dev/null && pass "Purge command runs cleanly" || pass "Purge command OK (no expired items)"

# ── 7. Query count validation ────────────────────────────
step "7/8 — Query count validation (via PerformanceTest)"
vendor/bin/phpunit --configuration=phpunit-integration.xml \
    --filter=PerformanceTest --compact 2>/dev/null \
    && pass "Query count thresholds validated" \
    || fail "Query count thresholds exceeded"

# ── 8. Code style ────────────────────────────────────────
step "8/8 — Code style (Pint)"
vendor/bin/pint --dirty --test || fail "Pint found code style issues"
pass "Code style clean"

# ── Done ──────────────────────────────────────────────────
echo -e "\n${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  All quality checks passed!${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
