#!/usr/bin/env bash
# vps-setup.sh — one-shot, idempotent dark-factory VPS bootstrap.
# Run from the repo root of a fresh clone on the VPS:  bash scripts/factory/vps-setup.sh
# Re-run any time; every step checks before acting. Interactive steps (codex login,
# gh auth login, .env secrets) are DETECTED and reported as TODO, never automated.
set -u

PASS=(); TODO=(); FAIL=()
ok()   { PASS+=("$1"); echo "  [ok]   $1"; }
todo() { TODO+=("$1"); echo "  [TODO] $1"; }
bad()  { FAIL+=("$1"); echo "  [FAIL] $1"; }

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || true)"
[ -z "$REPO_ROOT" ] && { echo "Run from inside the erp repo clone."; exit 1; }
cd "$REPO_ROOT"

echo "== Host =="
[ "$(uname -s)" = "Linux" ] && ok "Linux host (full PHPUnit suites allowed here)" \
  || todo "not Linux — this script targets the Ubuntu VPS"

echo "== Git =="
git remote get-url origin | grep -q "otospexsolutions/erp" \
  && ok "origin remote is the erp repo" || bad "origin remote is not otospexsolutions/erp"
[ -x .claude/hooks/git-dev-push-guard.sh ] \
  && ok "dev-push-guard hook present+executable (wired via .claude/settings.json)" \
  || { chmod +x .claude/hooks/git-dev-push-guard.sh 2>/dev/null && ok "dev-push-guard made executable" || bad "dev-push-guard hook missing"; }

echo "== Board worktree =="
if git worktree list | grep -q "erp.board"; then ok "board worktree exists"
else
  git fetch origin factory/board \
    && git worktree add ../erp.board factory/board \
    && ok "board worktree created at ../erp.board" \
    || bad "could not create board worktree (fetch factory/board failed?)"
fi

echo "== Node / pnpm =="
NODE_MAJ="$(node -v 2>/dev/null | sed 's/v\([0-9]*\).*/\1/' || echo 0)"
[ "${NODE_MAJ:-0}" -ge 20 ] && ok "node $(node -v)" || bad "node >=20 required (found: $(node -v 2>/dev/null || echo none))"
command -v pnpm >/dev/null 2>&1 && ok "pnpm $(pnpm -v)" \
  || { command -v corepack >/dev/null && corepack enable && ok "pnpm via corepack" || bad "pnpm missing (corepack enable)"; }
if [ -d node_modules ]; then ok "node_modules present"
else pnpm install --frozen-lockfile && ok "pnpm install" || bad "pnpm install failed"; fi
node -e "require('better-sqlite3')" 2>/dev/null && ok "better-sqlite3 native binding" \
  || { (cd "$(dirname "$(node -e "console.log(require.resolve('better-sqlite3/package.json'))" 2>/dev/null)" 2>/dev/null)" && npm run install >/dev/null 2>&1) && ok "better-sqlite3 rebuilt" || todo "better-sqlite3 binding — rerun after pnpm install (allowBuilds is set in pnpm-workspace.yaml)"; }

echo "== Playwright =="
if node -e "import('playwright-core')" 2>/dev/null; then
  if pnpm exec playwright-core install --with-deps chromium >/dev/null 2>&1 \
     || npx -y playwright@1.61.1 install --with-deps chromium >/dev/null 2>&1; then
    ok "chromium installed for playwright"
  else todo "playwright chromium: run 'npx playwright@1.61.1 install --with-deps chromium' (may need sudo for deps)"
  fi
else bad "playwright-core not resolvable — pnpm install first"; fi

echo "== PHP backend =="
PHP_V="$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo none)"
case "$PHP_V" in 8.4*|8.3*) ok "php $PHP_V";; *) bad "php 8.3/8.4 required (found $PHP_V)";; esac
for ext in dom curl libxml mbstring zip pcntl pdo pdo_pgsql redis; do
  php -m 2>/dev/null | grep -qi "^$ext$" || todo "php extension missing: $ext"
done
[ -d apps/api/vendor ] && ok "composer vendor present" \
  || { (cd apps/api && composer install --no-interaction >/dev/null 2>&1) && ok "composer install" || todo "composer install (needs composer + extensions)"; }
[ -f apps/api/.env ] && ok "apps/api/.env exists" \
  || todo "apps/api/.env — copy .env.example; recipe: EMPTY SANCTUM_STATEFUL_DOMAINS (token auth), central DB iziposcentral, API :8010, multi-queue worker incl. images/imports/enrichment (memory: reference_local_db_per_tenant_demo_launch)"

echo "== Docker infra =="
if command -v docker >/dev/null 2>&1; then
  docker compose ps --status running 2>/dev/null | grep -q postgres && ok "compose infra running" \
    || todo "start infra: docker compose up -d (postgres/pgbouncer/redis/meilisearch/minio)"
else bad "docker missing"; fi

echo "== CLIs =="
command -v gh >/dev/null 2>&1 \
  && { gh auth status >/dev/null 2>&1 && ok "gh authenticated" || todo "gh auth login (needed for PR creation)"; } \
  || bad "gh CLI missing"
command -v codex >/dev/null 2>&1 \
  && { codex login status >/dev/null 2>&1 && ok "codex authenticated" || todo "codex login (interactive)"; } \
  || todo "Codex CLI missing: npm i -g @openai/codex, then codex login"
command -v claude >/dev/null 2>&1 && ok "claude CLI present" || bad "claude CLI missing"

echo ""
echo "================ SUMMARY ================"
echo "PASS: ${#PASS[@]}   TODO: ${#TODO[@]}   FAIL: ${#FAIL[@]}"
[ ${#TODO[@]} -gt 0 ] && { echo "-- TODO --"; printf '  %s\n' "${TODO[@]}"; }
[ ${#FAIL[@]} -gt 0 ] && { echo "-- FAIL --"; printf '  %s\n' "${FAIL[@]}"; }
echo ""
echo "When everything passes: run the FIRST SUPERVISED session:"
echo "  claude \"\$(cat scripts/factory/vps-boot-prompt.md)\""
echo "Then update board task T-0013 via scripts/factory/board.mjs."
[ ${#FAIL[@]} -eq 0 ]
