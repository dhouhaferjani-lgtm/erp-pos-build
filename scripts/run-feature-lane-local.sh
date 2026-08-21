#!/usr/bin/env bash
#
# run-feature-lane-local.sh — execute ONE O-29 Feature lane on the local Docker
# stack, under a hard resource envelope.
#
# WHY THIS EXISTS
# ---------------
# The eight Feature lanes wired in .github/workflows/ci.yml are parked behind
# `vars.SELF_HOSTED_RUNNER_READY` until the owner registers a self-hosted runner
# (the GitHub Actions monthly quota is exhausted — LEDGER S-17). Until then this
# script IS the execution path: same lane definition, same group list, same
# database engine, read from the same manifest the CI checker verifies, so a lane
# that is green here is green for the same reasons it will be green there.
#
# WHAT IT WILL NOT DO
# -------------------
#   * It never runs the whole suite. It runs exactly one lane, one group at a
#     time, in the manifest's order. `composer test` on this laptop is a
#     standing prohibition (MEMORY: feedback_no_full_test_suite) and this script
#     has no flag that reaches it.
#   * It never runs two PHPUnit processes at once. Two pgsql RefreshDatabase
#     suites racing the same schema corrupt each other and throw fake 2BP01
#     errors that look like real failures (verified 2026-07-09; the same note
#     forbids matrixing the CI lanes).
#
# RESOURCE ENVELOPE — and an honest account of it
# -----------------------------------------------
# PHPUnit here runs on the HOST php, not in a container, and that is a
# deliberate trade rather than a shortcut:
#   * apps/api/Dockerfile is a PRODUCTION image — its composer stage installs
#     `--no-dev`, so the image contains no phpunit at all. Containerizing this
#     would mean a new test stage plus a second, Linux-native vendor tree
#     rebuilt on every composer.lock change, for minutes per run.
#   * The thing that actually needs bounding on a laptop is PHPUnit's appetite,
#     and PHPUnit is single-threaded: one process is a hard one-core ceiling,
#     `nice -n 19` puts it behind every interactive process, memory is capped by
#     phpunit.xml's own `<ini name="memory_limit" value="2G">`, and each group
#     gets a wall-clock alarm so a hung test cannot sit on the machine.
#   * The parts that DO benefit from containment already are: PostgreSQL and
#     Redis run in the docker-compose stack, inside the Docker Desktop VM with
#     its own CPU/RAM allocation, and this script talks to them over a port.
#   * On the self-hosted runner the same lane runs fully containerized anyway —
#     ephemeral runner + PG/Redis service containers — so the container property
#     exists where it is load-bearing.
# `--docker-db-limits` additionally clamps the database container's CPU/memory
# for the duration of the run, for the case where PG is the noisy neighbour.
#
# Usage:
#   scripts/run-feature-lane-local.sh --list
#   scripts/run-feature-lane-local.sh feature-lane-platform-misc
#   scripts/run-feature-lane-local.sh feature-lane-pos --group POS
#   scripts/run-feature-lane-local.sh feature-lane-catalog --sqlite
#
# The ambient environment is NOT trusted: every DB_*, DB_CENTRAL_* and REDIS_*
# variable is unset and rebuilt here, because these are RefreshDatabase suites and
# they drop every table in whatever database they are pointed at. The DB_CENTRAL_*
# family matters independently: the `central` connection is hardcoded pgsql and
# reads DB_CENTRAL_HOST/PORT/DATABASE/USERNAME/PASSWORD **in preference to** DB_*,
# so it is pinned to validated loopback values in BOTH modes — `--sqlite` is not a
# safe harbour for it. Deliberate overrides use a LANE_* namespace no application
# tooling exports, and each is validated:
#   LANE_DB_HOST      loopback only (127.0.0.1 / ::1 / localhost)
#   LANE_DB_PORT      default 5433 (the docker-compose stack)
#   LANE_DB_DATABASE  must match `autoerp_*test`; default autoerp_lane_test
#   LANE_DB_CENTRAL_DATABASE  same pattern; defaults to LANE_DB_DATABASE
#   LANE_REDIS_HOST   loopback only, same rule as LANE_DB_HOST
#   LANE_DB_USERNAME / LANE_DB_PASSWORD / LANE_REDIS_PORT
#   LANE_PG_CONTAINER the container to createdb in (and the ONLY one --docker-db-limits
#                     will clamp — the shared autoerp_postgres is refused by name AND
#                     by resolved container id)
#
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_DIR="$REPO_ROOT/apps/api"
MANIFEST="$API_DIR/tests/feature-lane-manifest.json"

# The docker-compose stack's published ports (docker-compose.yml maps
# POSTGRES_PORT/REDIS_PORT; the running local stack is 5433 / 6380).
DB_PORT_DEFAULT=5433
REDIS_PORT_DEFAULT=6380
DB_USER_DEFAULT=autoerp
DB_PASS_DEFAULT=autoerp_secret
DB_NAME_DEFAULT=autoerp_lane_test
PG_CONTAINER_DEFAULT=autoerp_postgres

LANE=""
ONLY_GROUP=""
USE_SQLITE=0
DOCKER_DB_LIMITS=0
DRY_RUN=0
GROUP_TIMEOUT=${LANE_GROUP_TIMEOUT:-2400}   # seconds, per GROUP

die() { echo "error: $*" >&2; exit 2; }

list_lanes() {
    jq -r '
      [ .lanes | to_entries[] | select(.value.execution_gate != null) ]
      | group_by(.value.job)[]
      | "\(.[0].value.job)\t\(length) group(s)\t\([.[].key] | map(sub(".*/";"")) | join(", "))"
    ' "$MANIFEST" | column -t -s $'\t'
}

[ -f "$MANIFEST" ] || die "manifest not found at $MANIFEST"
command -v jq >/dev/null || die "jq is required"

while [ $# -gt 0 ]; do
    case "$1" in
        --list) list_lanes; exit 0 ;;
        --group) ONLY_GROUP="${2:-}"; shift 2 ;;
        --sqlite) USE_SQLITE=1; shift ;;
        --docker-db-limits) DOCKER_DB_LIMITS=1; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        --timeout) GROUP_TIMEOUT="${2:-}"; shift 2 ;;
        -h|--help) sed -n '2,70p' "${BASH_SOURCE[0]}"; exit 0 ;;
        -*) die "unknown flag $1" ;;
        *) [ -z "$LANE" ] || die "one lane at a time (got '$LANE' and '$1')"; LANE="$1"; shift ;;
    esac
done

[ -n "$LANE" ] || { echo "usage: $(basename "$0") <lane-name> [--group G] [--sqlite]"; echo; list_lanes; exit 2; }

# Refused HERE, before anything is inspected or created, so the refusal does not
# depend on docker being reachable and can be exercised on its own (gate-r2 R2-6).
# The stronger identity check — comparing resolved container IDs — runs later, once
# docker is known to be available (gate-r2 R2-7).
if [ "$DOCKER_DB_LIMITS" = 1 ] && [ "${LANE_PG_CONTAINER:-$PG_CONTAINER_DEFAULT}" = "$PG_CONTAINER_DEFAULT" ]; then
    die "--docker-db-limits refuses to clamp the SHARED container '$PG_CONTAINER_DEFAULT', which other \
projects use. Start a dedicated PG for lane runs and pass LANE_PG_CONTAINER=<name>."
fi

# ---- resolve the lane from the manifest, never from a list in this file ------
# The manifest is the artifact CI verifies; duplicating the group list here is
# how a local run and a CI lane drift into meaning different things.
# (a read loop, not `mapfile`: macOS ships bash 3.2, where mapfile does not exist
# and — with no `set -e` — silently leaves the array empty)
LANE_GROUPS=()
while IFS= read -r _g; do
    [ -n "$_g" ] && LANE_GROUPS+=("$_g")
done < <(jq -r --arg job "$LANE" '
    .lanes | to_entries[] | select(.value.job == $job) | .key | sub(".*/";"")
' "$MANIFEST")
[ "${#LANE_GROUPS[@]}" -gt 0 ] || { echo "error: no lane named '$LANE'." >&2; echo >&2; list_lanes >&2; exit 2; }

if [ -n "$ONLY_GROUP" ]; then
    printf '%s\n' "${LANE_GROUPS[@]}" | grep -qx "$ONLY_GROUP" || die "group '$ONLY_GROUP' is not in lane '$LANE'"
    LANE_GROUPS=("$ONLY_GROUP")
fi

TOTAL_CLASSES=0
for g in "${LANE_GROUPS[@]}"; do
    n=$(find "$API_DIR/tests/Feature/$g" -name '*Test.php' 2>/dev/null | wc -l | tr -d ' ')
    TOTAL_CLASSES=$((TOTAL_CLASSES + n))
done

LOG_DIR="$REPO_ROOT/docs/sessions/feature-lanes/$LANE-$(date +%Y%m%d-%H%M%S)"
# A dry run creates nothing at all — no log directory, no .env.testing, no
# database, no test process. It exists to show the resolved targets and stop.
[ "$DRY_RUN" = 1 ] || mkdir -p "$LOG_DIR"

echo "=============================================================================="
echo " Feature lane: $LANE"
echo " groups:       ${#LANE_GROUPS[@]}  (${LANE_GROUPS[*]})"
echo " classes:      $TOTAL_CLASSES   (~$((TOTAL_CLASSES * 624 / 6000)) min at the measured 6.24 s/class on sqlite)"
echo " engine:       $([ "$USE_SQLITE" = 1 ] && echo 'sqlite :memory: (forced)' || echo "postgres ${LANE_DB_HOST:-127.0.0.1}:${LANE_DB_PORT:-$DB_PORT_DEFAULT}/${LANE_DB_DATABASE:-$DB_NAME_DEFAULT}")"
echo " envelope:     one process, nice 19, ${GROUP_TIMEOUT}s per group, serial"
echo " logs:         $LOG_DIR"
echo "=============================================================================="

# ---- environment -------------------------------------------------------------
# Same two steps the CI lanes run ("Copy environment file" + "Configure test
# environment"): .env.* is gitignored, so this cannot dirty the worktree.
if [ ! -f "$API_DIR/.env.testing" ] && [ "$DRY_RUN" = 0 ]; then
    echo "-- creating apps/api/.env.testing from .env.example"
    cp "$API_DIR/.env.example" "$API_DIR/.env.testing"
    # .env.example ships BROADCAST_CONNECTION=redis, which breaks bootstrap
    # because config/broadcasting.php has no "redis" connection defined.
    sed -i '' -e 's/^BROADCAST_CONNECTION=.*/BROADCAST_CONNECTION=null/' "$API_DIR/.env.testing" 2>/dev/null \
        || sed -i -e 's/^BROADCAST_CONNECTION=.*/BROADCAST_CONNECTION=null/' "$API_DIR/.env.testing"
    ( cd "$API_DIR" && php artisan key:generate --env=testing --force >/dev/null 2>&1 ) \
        || echo "   (key:generate failed; set APP_KEY in .env.testing by hand if the run cannot boot)"
fi

# THE AMBIENT ENVIRONMENT IS NOT TRUSTED (gate-r1 R1-3). This script runs
# RefreshDatabase suites, which DROP EVERY TABLE in the database they are pointed
# at. The first version defaulted each connection variable from the caller's
# environment (`${DB_HOST:-127.0.0.1}`), so a shell that had exported DB_* for the
# local dev database — or a staging one — silently redirected the whole lane onto
# it. `--sqlite` did not save you either: phpunit.xml pins DB_CONNECTION=sqlite
# via <env> WITHOUT force="true", so an inherited DB_CONNECTION=pgsql still won.
#
# So: every connection variable is UNSET and then constructed here. Deliberate
# overrides use a LANE_* namespace that no application tooling exports, and every
# one of them is validated below.
#
# gate-r2 R2-3 — THE DB_CENTRAL_* FAMILY. Rebuilding DB_* was NOT enough. The
# `central` connection in config/database.php is hardcoded `driver => pgsql` and
# reads DB_CENTRAL_URL / DB_CENTRAL_HOST / DB_CENTRAL_PORT / DB_CENTRAL_DATABASE /
# DB_CENTRAL_USERNAME / DB_CENTRAL_PASSWORD **in preference to** the DB_* values
# this script rebuilds. A shell exporting DB_CENTRAL_HOST=<staging> therefore
# passed both the loopback and the database-name guard, and every
# `connection('central')` site — including TenantProvisioningService's
# unconditional DELETEs — would have run against staging. The whole family is
# unset here and re-pinned to validated loopback values below, in BOTH branches:
# `central` is pgsql even when the default connection is sqlite, so "--sqlite" is
# not a safe harbour for it.
unset DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SOCKET DB_URL \
      DB_CENTRAL_URL DB_CENTRAL_HOST DB_CENTRAL_PORT DB_CENTRAL_DATABASE \
      DB_CENTRAL_USERNAME DB_CENTRAL_PASSWORD \
      REDIS_URL REDIS_HOST REDIS_PORT REDIS_PASSWORD REDIS_CLIENT REDIS_DB REDIS_CACHE_DB

export AUTOERP_QUARANTINE=1          # honour tests/quarantine.json, exactly as the CI lanes do
export APP_ENV=testing
export BROADCAST_CONNECTION=null
export CACHE_STORE=array             # worktree gotcha: config cache resolves to the main tree otherwise

# ---- destructive-run guards, as functions so every connection uses the SAME ones
require_loopback() {
    # $1 = host, $2 = the variable a caller would set to change it
    case "$1" in
        127.0.0.1|::1|localhost) ;;
        *) die "refusing to run destructive RefreshDatabase suites against non-loopback host '$1'. \
This script only ever talks to the local docker stack; point $2 at loopback or unset it." ;;
    esac
}
require_throwaway_database() {
    # An explicit throwaway-test-database name pattern. `autoerp`, `autoerp_dev`,
    # `synerivia_central`, `tenant_<uuid>` and every other real database fail this
    # by construction.
    case "$1" in
        autoerp_*test) ;;
        *) die "refusing to run destructive RefreshDatabase suites against database '$1' ($2). \
The lane database name must match 'autoerp_*test' (default: $DB_NAME_DEFAULT)." ;;
    esac
}

# The `central` connection is pgsql in every mode, so it is pinned in every mode.
export DB_CENTRAL_HOST=${LANE_DB_HOST:-127.0.0.1}
export DB_CENTRAL_PORT=${LANE_DB_PORT:-$DB_PORT_DEFAULT}
export DB_CENTRAL_DATABASE=${LANE_DB_CENTRAL_DATABASE:-${LANE_DB_DATABASE:-$DB_NAME_DEFAULT}}
export DB_CENTRAL_USERNAME=${LANE_DB_USERNAME:-$DB_USER_DEFAULT}
export DB_CENTRAL_PASSWORD=${LANE_DB_PASSWORD:-$DB_PASS_DEFAULT}
require_loopback "$DB_CENTRAL_HOST" LANE_DB_HOST
require_throwaway_database "$DB_CENTRAL_DATABASE" "the central connection"

if [ "$USE_SQLITE" = 1 ]; then
    # FORCED, not merely "left alone": with the PG variables unset above, this
    # makes the sqlite path unambiguous no matter what the caller exported. The
    # central connection keeps the validated loopback pin set above — it is pgsql
    # regardless of this setting.
    export DB_CONNECTION=sqlite
    export DB_DATABASE=":memory:"
    export REDIS_HOST=${LANE_REDIS_HOST:-127.0.0.1}
    export REDIS_PORT=${LANE_REDIS_PORT:-$REDIS_PORT_DEFAULT}
    require_loopback "$REDIS_HOST" LANE_REDIS_HOST
else
    # Real PostgreSQL, set the same way treasury-spine-pgsql sets it: phpunit.xml
    # pins DB_CONNECTION=sqlite via <env> WITHOUT force="true", so a real
    # environment variable wins and the PG-only tests execute instead of skipping.
    export DB_CONNECTION=pgsql
    export DB_HOST=${LANE_DB_HOST:-127.0.0.1}
    export DB_PORT=${LANE_DB_PORT:-$DB_PORT_DEFAULT}
    export DB_DATABASE=${LANE_DB_DATABASE:-$DB_NAME_DEFAULT}
    export DB_USERNAME=${LANE_DB_USERNAME:-$DB_USER_DEFAULT}
    export DB_PASSWORD=${LANE_DB_PASSWORD:-$DB_PASS_DEFAULT}
    export REDIS_HOST=${LANE_REDIS_HOST:-127.0.0.1}
    export REDIS_PORT=${LANE_REDIS_PORT:-$REDIS_PORT_DEFAULT}

    require_loopback "$DB_HOST" LANE_DB_HOST
    require_throwaway_database "$DB_DATABASE" "the default connection"
    require_loopback "$REDIS_HOST" LANE_REDIS_HOST
fi

# ---- resolved connection targets, printed and (optionally) nothing else ------
# `--dry-run` exits HERE: after every guard has run, before the first side effect
# (createdb, docker update, phpunit). That makes the neutralisation of a hostile
# ambient environment OBSERVABLE and therefore testable — the ambient value being
# ignored looks identical to a normal run otherwise.
echo "-- resolved: default=$DB_CONNECTION://${DB_HOST:-}:${DB_PORT:-}/${DB_DATABASE:-}"
echo "-- resolved: central=pgsql://$DB_CENTRAL_HOST:$DB_CENTRAL_PORT/$DB_CENTRAL_DATABASE"
echo "-- resolved: redis=${REDIS_HOST:-}:${REDIS_PORT:-}"
if [ "$DRY_RUN" = 1 ]; then
    echo "-- dry run: guards passed, no database created and no test executed"
    exit 0
fi

if [ "$USE_SQLITE" = 0 ]; then
    PG_CONTAINER=${LANE_PG_CONTAINER:-$PG_CONTAINER_DEFAULT}
    docker inspect "$PG_CONTAINER" >/dev/null 2>&1 \
        || die "postgres container '$PG_CONTAINER' is not running — start the stack with 'docker compose up -d postgres redis'"

    if ! docker exec -e PGPASSWORD="$DB_PASSWORD" "$PG_CONTAINER" \
            psql -U "$DB_USERNAME" -lqt 2>/dev/null | cut -d\| -f1 | grep -qw "$DB_DATABASE"; then
        echo "-- creating database $DB_DATABASE in $PG_CONTAINER"
        docker exec -e PGPASSWORD="$DB_PASSWORD" "$PG_CONTAINER" \
            createdb -U "$DB_USERNAME" "$DB_DATABASE" || die "could not create $DB_DATABASE"
    fi

    if [ "$DOCKER_DB_LIMITS" = 1 ]; then
        # gate-r1 R1-4: this used to clamp the SHARED autoerp_postgres — the
        # container every other project on this machine uses — and never put the
        # limits back, so one lane run permanently degraded the dev stack. Two
        # changes: the shared container is refused outright, and a dedicated one is
        # restored on EVERY exit path.
        # gate-r2 R2-7: the name check (done at argument-validation time, above) is
        # spoofable — a second name, an alias or an ID prefix can resolve to the
        # SAME container. Compare what docker actually resolved.
        REQUESTED_ID=$(docker inspect -f '{{.Id}}' "$PG_CONTAINER" 2>/dev/null || echo '')
        SHARED_ID=$(docker inspect -f '{{.Id}}' "$PG_CONTAINER_DEFAULT" 2>/dev/null || echo '')
        if [ -n "$REQUESTED_ID" ] && [ "$REQUESTED_ID" = "$SHARED_ID" ]; then
            die "--docker-db-limits: '$PG_CONTAINER' resolves to the SAME container as the shared \
'$PG_CONTAINER_DEFAULT' (id $(printf '%.12s' "$REQUESTED_ID")…). Naming it differently does not make it \
a different container."
        fi
        PRIOR_NANO_CPUS=$(docker inspect -f '{{.HostConfig.NanoCpus}}' "$PG_CONTAINER" 2>/dev/null || echo 0)
        PRIOR_MEMORY=$(docker inspect -f '{{.HostConfig.Memory}}' "$PG_CONTAINER" 2>/dev/null || echo 0)
        restore_db_limits() {
            echo "-- restoring $PG_CONTAINER limits (cpus=$PRIOR_NANO_CPUS nano, memory=$PRIOR_MEMORY bytes)"
            docker update \
                --cpus="$(awk "BEGIN{printf \"%.3f\", $PRIOR_NANO_CPUS/1000000000}")" \
                --memory="$PRIOR_MEMORY" --memory-swap="$PRIOR_MEMORY" \
                "$PG_CONTAINER" >/dev/null 2>&1 \
                || echo "   (restore failed — check 'docker inspect $PG_CONTAINER' by hand)"
        }
        trap restore_db_limits EXIT INT TERM
        echo "-- clamping $PG_CONTAINER to 2 CPUs / 2g for this run (restored on exit)"
        docker update --cpus=2 --memory=2g --memory-swap=2g "$PG_CONTAINER" >/dev/null \
            || die "docker update refused; not continuing with an unclamped container after asking for a clamp"
    fi
fi

# ---- run, one group at a time ------------------------------------------------
cd "$API_DIR" || die "cannot enter $API_DIR"
[ -x ./vendor/bin/phpunit ] || die "vendor/bin/phpunit missing — run 'composer install' in apps/api"

SUMMARY="$LOG_DIR/summary.tsv"
printf 'group\tclasses\tresult\tseconds\ttests\tfailures\terrors\tskipped\n' > "$SUMMARY"
lane_status=0

for group in "${LANE_GROUPS[@]}"; do
    log="$LOG_DIR/$group.log"
    classes=$(find "tests/Feature/$group" -name '*Test.php' 2>/dev/null | wc -l | tr -d ' ')
    printf '\n>>> tests/Feature/%s  (%s classes)\n' "$group" "$classes"
    started=$(date +%s)

    # `perl -e alarm` is the portable `timeout(1)` — coreutils' timeout is not
    # installed on macOS. nice -n 19 keeps the run behind everything interactive.
    nice -n 19 perl -e 'alarm shift @ARGV; exec @ARGV or exit 127' \
        "$GROUP_TIMEOUT" ./vendor/bin/phpunit "tests/Feature/$group/" --do-not-cache-result \
        > "$log" 2>&1
    rc=$?
    elapsed=$(( $(date +%s) - started ))

    counts=$(grep -Eo '(Tests|OK \()[^.]*' "$log" | tail -1)
    # A green run prints "OK (N tests, …)"; a red one prints the "Tests: N, …" line.
    tests=$(grep -Eo 'Tests: [0-9]+' "$log" | tail -1 | grep -Eo '[0-9]+' || true)
    [ -n "$tests" ] || tests=$(grep -Eo 'OK \(([0-9]+) tests' "$log" | tail -1 | grep -Eo '[0-9]+' || true)
    failures=$(grep -Eo 'Failures: [0-9]+' "$log" | tail -1 | grep -Eo '[0-9]+' || true)
    errors=$(grep -Eo 'Errors: [0-9]+' "$log" | tail -1 | grep -Eo '[0-9]+' || true)
    skipped=$(grep -Eo 'Skipped: [0-9]+' "$log" | tail -1 | grep -Eo '[0-9]+' || true)

    if [ "$rc" -eq 0 ]; then
        result=PASS
    elif [ "$rc" -eq 142 ] || [ "$rc" -eq 14 ]; then
        result=TIMEOUT; lane_status=1
    else
        result=FAIL; lane_status=1
    fi

    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
        "$group" "$classes" "$result" "$elapsed" "${tests:-0}" "${failures:-0}" "${errors:-0}" "${skipped:-0}" \
        >> "$SUMMARY"
    printf '    %-8s %4ss   %s\n' "$result" "$elapsed" "${counts:-see $log}"
    tail -3 "$log" | sed 's/^/    | /'
done

echo
echo "=============================================================================="
column -t -s $'\t' < "$SUMMARY"
echo "=============================================================================="
if [ "$lane_status" -ne 0 ]; then
    cat <<EOF

Lane '$LANE' is RED. If this is its FIRST execution, that is expected and the
triage posture applies (design doc §6): baseline what fails, decide fix-vs-
quarantine per class, and record every quarantined target in
apps/api/tests/quarantine.json with a lane, a reason and today's date — then
raise \`ceiling\` to the new count in the SAME commit. The ceiling only ever
comes back down.
EOF
fi
exit "$lane_status"
