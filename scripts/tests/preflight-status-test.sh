#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PREFLIGHT="$REPO_ROOT/scripts/preflight.sh"
FIXTURE="$(mktemp -d "${TMPDIR:-/tmp}/autoerp-preflight-status.XXXXXX")"
trap 'rm -rf "$FIXTURE"' EXIT

mkdir -p \
    "$FIXTURE/repo/scripts/tests" \
    "$FIXTURE/repo/apps/api/vendor/bin" \
    "$FIXTURE/repo/apps/web/src/hooks" \
    "$FIXTURE/repo/apps/pos" \
    "$FIXTURE/repo/packages/shared/types" \
    "$FIXTURE/bin"
cp "$PREFLIGHT" "$FIXTURE/repo/scripts/preflight.sh"
printf 'declare(strict_types=1);\n' > "$FIXTURE/repo/packages/shared/types/generated.d.ts"
printf 'export const permissions = {};\n' > "$FIXTURE/repo/apps/web/src/hooks/permissionsMap.generated.ts"

make_stub() {
    local path="$1"
    mkdir -p "$(dirname "$path")"
    cp "$FIXTURE/stub-command" "$path"
    chmod +x "$path"
}

cat > "$FIXTURE/stub-command" <<'STUB'
#!/bin/bash
set -u
invocation="$(basename "$0")"
if [[ "$0" == */vendor/bin/* ]]; then
    invocation="vendor/bin/$invocation"
fi
printf '%s %s\n' "$invocation" "$*" >> "${PREFLIGHT_STUB_LOG:?}"
if [[ -n "${PREFLIGHT_STUB_FAIL_MATCH:-}" && "$invocation $*" == *"$PREFLIGHT_STUB_FAIL_MATCH"* ]]; then
    printf 'stub failure: %s %s\n' "$invocation" "$*" >&2
    exit "${PREFLIGHT_STUB_FAIL_CODE:-1}"
fi
exit 0
STUB

for command in php pnpm git bash; do
    make_stub "$FIXTURE/bin/$command"
done
make_stub "$FIXTURE/repo/apps/api/vendor/bin/pint"
make_stub "$FIXTURE/repo/apps/api/vendor/bin/phpstan"
make_stub "$FIXTURE/repo/apps/api/vendor/bin/phpunit"

run_preflight() {
    local case_name="$1"
    shift
    local output="$FIXTURE/$case_name.out"
    local log="$FIXTURE/$case_name.log"
    : > "$log"
    set +e
    env \
        -u PREFLIGHT_SCOPE \
        -u PREFLIGHT_TEST_PATHS \
        -u PREFLIGHT_PINT_PATHS \
        -u PREFLIGHT_PHPSTAN_PATHS \
        -u PREFLIGHT_VITEST_PATHS \
        -u PREFLIGHT_STUB_FAIL_MATCH \
        -u PREFLIGHT_STUB_FAIL_CODE \
        PATH="$FIXTURE/bin:/usr/bin:/bin" \
        PREFLIGHT_STUB_LOG="$log" \
        "$@" \
        /bin/bash "$FIXTURE/repo/scripts/preflight.sh" > "$output" 2>&1
    RUN_EXIT=$?
    set -e
    RUN_OUTPUT="$output"
    RUN_LOG="$log"
}

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    if [[ -n "${RUN_OUTPUT:-}" && -f "$RUN_OUTPUT" ]]; then
        sed -n '1,240p' "$RUN_OUTPUT" >&2
    fi
    if [[ -n "${RUN_LOG:-}" && -f "$RUN_LOG" ]]; then
        printf 'stub invocations:\n' >&2
        sed -n '1,240p' "$RUN_LOG" >&2
    fi
    exit 1
}

assert_exit() {
    local expected="$1"
    local label="$2"
    [[ "$RUN_EXIT" -eq "$expected" ]] || fail "$label: expected exit $expected, got $RUN_EXIT"
}

assert_output_contains() {
    local needle="$1"
    local label="$2"
    grep -Fq -- "$needle" "$RUN_OUTPUT" || fail "$label: output did not contain '$needle'"
}

assert_log_contains() {
    local needle="$1"
    local label="$2"
    grep -Fq -- "$needle" "$RUN_LOG" || fail "$label: stub log did not contain '$needle'"
}

run_preflight empty-paths PREFLIGHT_SCOPE=paths
assert_exit 2 "empty paths are incomplete"
assert_output_contains "PREFLIGHT_TEST_PATHS is required" "empty paths explain the configuration error"
[[ ! -s "$RUN_LOG" ]] || fail "empty paths must fail before dependency execution"

run_preflight whitespace-paths PREFLIGHT_SCOPE=paths "PREFLIGHT_TEST_PATHS=   "
assert_exit 2 "whitespace-only paths are incomplete"
assert_output_contains "PREFLIGHT_TEST_PATHS is required" "whitespace-only paths explain the configuration error"
[[ ! -s "$RUN_LOG" ]] || fail "whitespace-only paths must fail before dependency execution"

run_preflight invalid-scope PREFLIGHT_SCOPE=unknown PREFLIGHT_TEST_PATHS=tests/Architecture/ExampleTest.php
assert_exit 2 "invalid scope is a configuration error"
assert_output_contains "Unknown PREFLIGHT_SCOPE='unknown'" "invalid scope is explained"
[[ ! -s "$RUN_LOG" ]] || fail "invalid scope must fail before dependency execution"

run_preflight scoped-success \
    PREFLIGHT_SCOPE=paths \
    PREFLIGHT_TEST_PATHS=tests/Architecture/FeatureLaneManifestCheckerTest.php
assert_exit 0 "valid scoped preflight"
assert_log_contains "php artisan test tests/Architecture/FeatureLaneManifestCheckerTest.php" "scoped test dispatch"
assert_log_contains "pnpm typecheck" "web typecheck dispatch"
assert_log_contains "/apps/pos typecheck" "POS typecheck dispatch"
assert_output_contains "All preflight checks passed!" "scoped success summary"

run_preflight child-failure \
    PREFLIGHT_SCOPE=paths \
    PREFLIGHT_TEST_PATHS=tests/Architecture/FeatureLaneManifestCheckerTest.php \
    PREFLIGHT_STUB_FAIL_MATCH="pnpm typecheck" \
    PREFLIGHT_STUB_FAIL_CODE=23
assert_exit 23 "child failure status is propagated"
assert_output_contains "stub failure: pnpm typecheck" "child stderr is preserved"

run_preflight full-dispatch PREFLIGHT_SCOPE=full
assert_exit 0 "stubbed full-mode control"
grep -Fxq "php artisan test" "$RUN_LOG" || fail "full mode must dispatch exactly 'php artisan test'"
if grep -Fq "php artisan test tests/" "$RUN_LOG"; then
    fail "full mode must dispatch php artisan test without a selected path"
fi

printf 'preflight status tests passed\n'
