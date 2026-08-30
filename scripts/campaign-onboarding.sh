#!/usr/bin/env bash

set -u

campaign_web="${CAMPAIGN_WEB_URL:-http://localhost:5173}"
campaign_api="${CAMPAIGN_API_URL:-http://127.0.0.1:8010}"
campaign_country="${CAMPAIGN_COUNTRY:-TN}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --web|--api|--country)
      if [[ $# -lt 2 || -z "$2" ]]; then
        echo "Missing value for $1" >&2
        exit 2
      fi
      case "$1" in
        --web) campaign_web="$2" ;;
        --api) campaign_api="$2" ;;
        --country) campaign_country="$2" ;;
      esac
      shift 2
      ;;
    *)
      echo "Unknown argument: $1" >&2
      echo "Usage: $0 [--web URL] [--api URL] [--country TN]" >&2
      exit 2
      ;;
  esac
done

if ! curl --fail --silent --show-error --max-time 10 "${campaign_api%/}/api/v1/health" >/dev/null; then
  echo "Campaign aborted: API health check failed at ${campaign_api%/}/api/v1/health" >&2
  exit 1
fi

if ! curl --fail --silent --show-error --max-time 10 "${campaign_web%/}/" >/dev/null; then
  echo "Campaign aborted: web check failed at ${campaign_web%/}/" >&2
  exit 1
fi

campaign_run_id="${CAMPAIGN_RUN_ID:-$(date -u +%Y%m%d%H%M%S)-$$}"
export CAMPAIGN_WEB_URL="$campaign_web"
export CAMPAIGN_API_URL="$campaign_api"
export CAMPAIGN_COUNTRY="$campaign_country"
export CAMPAIGN_RUN_ID="$campaign_run_id"

echo "Reminder: the target must run a queue worker consuming fiscal-projections (L6/L7 poll and fail clearly if absent)."
echo "Onboarding campaign run-id: $campaign_run_id"

set +e
pnpm --dir apps/web campaign:onboarding
campaign_status=$?
set -e

echo "Ledger: apps/web/test-results/campaign-${campaign_run_id}/ledger.json"
echo "HTML report: apps/web/playwright-report/index.html"
exit "$campaign_status"
