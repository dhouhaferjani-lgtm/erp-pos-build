#!/usr/bin/env bash

set -euo pipefail

# M7 test evidence only. This runner deliberately rebuilds a disposable local
# database, then crosses the real authenticated HTTP boundary for certification
# and assignment. It must never be used as staging/production certification.

: "${PGHOST:?set PGHOST}"
: "${PGPORT:?set PGPORT}"
: "${PGUSER:?set PGUSER}"
: "${PGPASSWORD:?set PGPASSWORD to a non-empty value}"
: "${PHASE_A_MIGRATE_DB:?set PHASE_A_MIGRATE_DB}"
: "${PHASE_A_FIXTURE_CONFIRM:?set PHASE_A_FIXTURE_CONFIRM}"
: "${PHASE_A_FIXTURE_PORT:?set PHASE_A_FIXTURE_PORT}"

if [[ "$PHASE_A_FIXTURE_CONFIRM" != "I_UNDERSTAND_THIS_REBUILDS_A_DISPOSABLE_DATABASE" ]]; then
  echo "Refusing without the disposable-database confirmation phrase." >&2
  exit 64
fi

case "$PHASE_A_MIGRATE_DB" in
  autoerp_country_defaults_test|autoerp_country_defaults_*_test|autoerp_country_defaults_scratch|autoerp_country_defaults_*_scratch)
    ;;
  *)
    echo "Refusing non-disposable database name: $PHASE_A_MIGRATE_DB" >&2
    exit 64
    ;;
esac

if [[ ! "$PHASE_A_FIXTURE_PORT" =~ ^[0-9]+$ ]] \
  || (( PHASE_A_FIXTURE_PORT < 1024 || PHASE_A_FIXTURE_PORT > 65535 )); then
  echo "PHASE_A_FIXTURE_PORT must be an unprivileged TCP port (1024-65535)." >&2
  exit 64
fi

mode=full
case "${1:-}" in
  '')
    ;;
  --preflight-only)
    mode=preflight
    ;;
  --migrate-only)
    mode=migrate
    ;;
  *)
    echo "Usage: $0 [--preflight-only|--migrate-only]" >&2
    exit 64
    ;;
esac
if [[ $# -gt 1 ]]; then
  echo "Usage: $0 [--preflight-only|--migrate-only]" >&2
  exit 64
fi

for variable in DATABASE_URL DB_URL DB_CENTRAL_URL; do
  if [[ -n "${!variable:-}" ]]; then
    echo "Refusing inherited database URL override: $variable" >&2
    exit 64
  fi
done

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo_root=$(cd "$script_dir/.." && pwd)
config_cache_path=${APP_CONFIG_CACHE:-bootstrap/cache/config.php}
if [[ "$config_cache_path" != /* ]]; then
  config_cache_path="$repo_root/apps/api/$config_cache_path"
fi
if [[ -f "$config_cache_path" ]]; then
  echo "Refusing cached Laravel configuration: $config_cache_path" >&2
  exit 64
fi

for executable in jq php; do
  command -v "$executable" >/dev/null || {
    echo "Missing required executable: $executable" >&2
    exit 69
  }
done

cd "$repo_root/apps/api"

export DB_CONNECTION=central
export DATABASE_URL=
export DB_URL=
export DB_CENTRAL_URL=
export DB_HOST="$PGHOST"
export DB_PORT="$PGPORT"
export DB_DATABASE="$PHASE_A_MIGRATE_DB"
export DB_USERNAME="$PGUSER"
export DB_PASSWORD="$PGPASSWORD"
export DB_CENTRAL_HOST="$PGHOST"
export DB_CENTRAL_PORT="$PGPORT"
export DB_CENTRAL_DATABASE="$PHASE_A_MIGRATE_DB"
export DB_CENTRAL_USERNAME="$PGUSER"
export DB_CENTRAL_PASSWORD="$PGPASSWORD"

effective_config=$(php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
(new Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables)->bootstrap($app);
(new Illuminate\Foundation\Bootstrap\LoadConfiguration)->bootstrap($app);
$config = $app["config"];
echo json_encode([
    "cached" => $app->configurationIsCached(),
    "default" => $config->get("database.default"),
    "driver" => $config->get("database.connections.central.driver"),
    "url" => $config->get("database.connections.central.url"),
    "host" => (string) $config->get("database.connections.central.host"),
    "port" => (string) $config->get("database.connections.central.port"),
    "database" => $config->get("database.connections.central.database"),
    "username" => $config->get("database.connections.central.username"),
], JSON_THROW_ON_ERROR);
')
if ! jq -e \
  --arg host "$PGHOST" \
  --arg port "$PGPORT" \
  --arg database "$PHASE_A_MIGRATE_DB" \
  --arg username "$PGUSER" \
  '.cached == false
    and .default == "central"
    and .driver == "pgsql"
    and (.url == null or .url == "")
    and .host == $host
    and .port == $port
    and .database == $database
    and .username == $username' >/dev/null <<<"$effective_config"; then
  echo "Refusing because Laravel effective central connection does not match the disposable target." >&2
  echo "$effective_config" | jq -c . >&2
  exit 64
fi
echo "Laravel effective central connection matches $PHASE_A_MIGRATE_DB."

if [[ "$mode" == preflight ]]; then
  echo "Disposable fixture preflight passed for $PHASE_A_MIGRATE_DB."
  exit 0
fi

for executable in curl psql; do
  command -v "$executable" >/dev/null || {
    echo "Missing required executable: $executable" >&2
    exit 69
  }
done

actual_database=$(PGPASSWORD="$PGPASSWORD" psql \
  -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d "$PHASE_A_MIGRATE_DB" \
  -Atc 'select current_database()')
if [[ "$actual_database" != "$PHASE_A_MIGRATE_DB" ]]; then
  echo "Database identity mismatch: expected $PHASE_A_MIGRATE_DB, got $actual_database" >&2
  exit 64
fi

laravel_databases=$(php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode([
    "default" => $app["db"]->connection()->scalar("select current_database()"),
    "central" => $app["db"]->connection("central")->scalar("select current_database()"),
], JSON_THROW_ON_ERROR);
')
if ! jq -e --arg database "$PHASE_A_MIGRATE_DB" \
  '.default == $database and .central == $database' >/dev/null <<<"$laravel_databases"; then
  echo "Laravel database identity mismatch; refusing destructive migration." >&2
  echo "$laravel_databases" | jq -c . >&2
  exit 64
fi
echo "Laravel default and central connections both resolve to $PHASE_A_MIGRATE_DB."

php artisan migrate:fresh --database=central --force

if [[ "$mode" == migrate ]]; then
  php artisan migrate:status --database=central
  echo "Scratch-only migration evidence complete."
  exit 0
fi

export PHASE_A_FIXTURE_EMAIL="phase-a-m7-$RANDOM-$RANDOM@example.test"
export PHASE_A_FIXTURE_PASSWORD="CountryDefaults-M7-Only-2026!"
php artisan tinker --execute="App\\Models\\SuperAdmin::query()->create(['name' => 'Phase A M7 HTTP Certifier', 'email' => getenv('PHASE_A_FIXTURE_EMAIL'), 'password' => Illuminate\\Support\\Facades\\Hash::make((string) getenv('PHASE_A_FIXTURE_PASSWORD')), 'role' => App\\Models\\Enums\\SuperAdminRole::SuperAdmin->value, 'is_active' => true]);"

api_log="${TMPDIR:-/tmp}/phase-a-m7-api-$PHASE_A_FIXTURE_PORT.log"
php artisan serve --host=127.0.0.1 --port="$PHASE_A_FIXTURE_PORT" >"$api_log" 2>&1 &
api_pid=$!
cleanup() {
  kill "$api_pid" 2>/dev/null || true
  wait "$api_pid" 2>/dev/null || true
}
trap cleanup EXIT

api_origin="http://127.0.0.1:$PHASE_A_FIXTURE_PORT"
api_ready=false
for _attempt in {1..30}; do
  if curl --silent --output /dev/null "$api_origin/api/v1/admin/auth/login"; then
    api_ready=true
    break
  fi
  sleep 1
done
if [[ "$api_ready" != true ]]; then
  echo "Local fixture API did not start; see $api_log" >&2
  exit 70
fi

login_json=$(curl --silent --show-error --fail-with-body \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$PHASE_A_FIXTURE_EMAIL\",\"password\":\"$PHASE_A_FIXTURE_PASSWORD\"}" \
  "$api_origin/api/v1/admin/auth/login")
token=$(jq -er '.data.token' <<<"$login_json")

template_id() {
  local bootstrap_key="$1"
  PGPASSWORD="$PGPASSWORD" psql \
    -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d "$PHASE_A_MIGRATE_DB" \
    -Atc "select id from admin_templates where bootstrap_key='$bootstrap_key'"
}

publish_and_assign() {
  local id="$1"
  local country_code="$2"
  local scope="$3"
  local route_country="$country_code"
  local publish_json
  local assignment_json

  if [[ "$route_country" == "*" ]]; then
    route_country='%2A'
  fi

  publish_json=$(curl --silent --show-error --fail-with-body \
    -X POST \
    -H "Authorization: Bearer $token" \
    -H 'Content-Type: application/json' \
    -d "{\"standard_ref\":\"Phase A M7 authenticated HTTP verification fixture\",\"certified_country_codes\":[\"$scope\"]}" \
    "$api_origin/api/v1/admin/country-defaults/templates/$id/publish")
  jq -e '.data.status == "published" and (.data.certified_by != null)' >/dev/null <<<"$publish_json"

  assignment_json=$(curl --silent --show-error --fail-with-body \
    -X PUT \
    -H "Authorization: Bearer $token" \
    -H 'Content-Type: application/json' \
    -d "{\"domain\":\"chart_of_accounts\",\"template_id\":\"$id\"}" \
    "$api_origin/api/v1/admin/country-defaults/assignments/$route_country")
  jq -e --arg template_id "$id" '.data.template_id == $template_id' >/dev/null <<<"$assignment_json"

  echo "Authenticated HTTP publish + assignment passed for $country_code."
}

tn_id=$(template_id 'coa.tn.legacy-v1')
fr_id=$(template_id 'coa.fr.legacy-v1')
generic_id=$(template_id 'coa.generic.legacy-v1')
if [[ -z "$tn_id" || -z "$fr_id" || -z "$generic_id" ]]; then
  echo "Migration did not create all three bootstrap drafts." >&2
  exit 70
fi

publish_and_assign "$tn_id" TN TN
publish_and_assign "$fr_id" FR FR
publish_and_assign "$generic_id" '*' '*'

php artisan country-defaults:verify
echo "Scratch-only evidence complete. Human staging/production certification remains required."
