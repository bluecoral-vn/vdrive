#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────
# vDrive — Deploy to STAGING + PRODUCTION
# ──────────────────────────────────────────────────────────
# Runs staging deploy (full reset) first, then production
# deploy (code only) in sequence.
#
# Usage:
#   bash scripts/deploy-all.sh            # Deploy both
#   bash scripts/deploy-all.sh --dry-run  # Preview both
# ──────────────────────────────────────────────────────────

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

echo -e "${BOLD}${CYAN}"
echo "╔══════════════════════════════════════════════╗"
echo "║   vDrive — Deploy to STAGING + PRODUCTION   ║"
echo "╚══════════════════════════════════════════════╝"
echo -e "${NC}"

ARGS="${1:-}"

# ── Stage 1: Staging ─────────────────────────────────
echo -e "${CYAN}▸ Stage 1/2: Deploying to STAGING...${NC}\n"
bash "$ROOT/scripts/deploy-staging.sh" $ARGS

if [[ "$ARGS" == "--dry-run" ]]; then
    echo ""
    echo -e "${CYAN}▸ Stage 2/2: Production (dry-run)...${NC}\n"
    bash "$ROOT/scripts/deploy-prod.sh" $ARGS
    exit 0
fi

echo ""
echo -e "${GREEN}━━━ Staging complete ━━━${NC}"
echo ""

# ── Stage 2: Production (--yes skips interactive prompt) ──
echo -e "${CYAN}▸ Stage 2/2: Deploying to PRODUCTION...${NC}\n"
bash "$ROOT/scripts/deploy-prod.sh" --yes $ARGS

echo -e "\n${BOLD}${GREEN}"
echo "╔══════════════════════════════════════════════╗"
echo "║    Both environments deployed successfully!   ║"
echo "╚══════════════════════════════════════════════╝"
echo -e "${NC}"
