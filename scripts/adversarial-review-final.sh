#!/usr/bin/env bash
# adversarial-review-final.sh — PARENT-ONLY final-gate bridge for the enforcement-guards packages.
# (gate-r7 R7-C-1 / gate-r8 R8-C-1..H-2 / gate-r9 R9-C-1..H-4 of CODEX-DISPATCH-enforcement-guards-2026-08-12.md)
#
# Trust design (r20 — receipt-driven; header updated per gate-r11 R11-M-1):
#   * --receipt is the SOLE base/manifest authority (gate-r10 R10-C-1): the PARENT DISPATCH RECEIPT
#     (out-of-repo, parent-owned) supplies base_sha, the canonical ABSOLUTE manifest path, and the
#     expected manifest sha256. There are NO --base / --manifest / --expected-manifest-sha256 options.
#     The manifest is verified against the receipt digest BEFORE it is parsed; its digest is emitted
#     in the register and pin-tag data (gate-r9 R9-H-3).
#   * BASE must be a strict ancestor of A (and != A); the candidate progress YAML is field-checked
#     fail-closed (PyYAML required): base_sha == receipt base, structured control_manifest ==
#     receipt {path,sha256}, exactly one final milestone at status: review, fix_rounds <= max,
#     top-level max_fix_rounds == manifest projection (gate-r9 R9-C-1, gate-r10 R10-H-1).
#   * The receipt's four projection fields (progress_path, final_milestone, lenses, max_fix_rounds)
#     are CROSS-CHECKED against the authenticated manifest's package block — mismatch = exit 3
#     (gate-r11 R11-M-2).
#   * SEAL (gate-r9 R9-H-2): the handed-over worktree may contain EXACTLY ONE dirty entry — the
#     untracked handback at --handback-source. The bridge copies+hashes it ITSELF to --handback (the
#     parent's immutable dest) and rejects any other status entry or a tracked-modified handback.
#   * The reviewer runs from a NEUTRAL EMPTY working directory (gate-r9 R9-H-1) — neither the parent's
#     control checkout nor the candidate snapshot is the cwd, so no project-local CLAUDE.md/settings/
#     hooks from either tree can load. All paths in the prompt are absolute. The VERIFIED brief and
#     harness CONTENTS are injected as binding inputs. After the run the snapshot is re-asserted
#     (HEAD == A, clean).
#   * Verdict parsing is EXACT (gate-r9 R9-H-4): exactly one VERDICT: line; string equality;
#     anything else = exit 3.
# Exit: 0 = ACCEPT · 2 = CHANGES-REQUIRED · 3 = tool error (parent-owned retry; never an executor fix)

set -euo pipefail

RECEIPT="" PACKAGE="" CANDIDATE="" ACCEPTED="" HANDBACK_SRC="" HANDBACK="" ROUND="" ATTEMPT="1" OUT=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --receipt)         RECEIPT="$2";      shift 2 ;;
    --package)         PACKAGE="$2";      shift 2 ;;
    --candidate)       CANDIDATE="$2";    shift 2 ;;
    --accepted)        ACCEPTED="$2";     shift 2 ;;
    --handback-source) HANDBACK_SRC="$2"; shift 2 ;;
    --handback)        HANDBACK="$2";     shift 2 ;;
    --round)           ROUND="$2";        shift 2 ;;
    --attempt)         ATTEMPT="$2";      shift 2 ;;
    --out)             OUT="$2";          shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 3 ;;
  esac
done
for v in RECEIPT PACKAGE CANDIDATE ACCEPTED HANDBACK_SRC HANDBACK ROUND OUT; do
  [[ -n "${!v}" ]] || { echo "missing required argument for $v" >&2; exit 3; }
done

sha() { shasum -a 256 "$1" | awk '{print $1}'; }
abspath() { python3 -c 'import os,sys; p=os.path.abspath(sys.argv[1]); sys.exit(0) if False else print(p)' "$1"; }

# ── -1. PyYAML is REQUIRED on the parent host (gate-r10 R10-H-1 — no partial fallback, fail closed) ──
python3 -c 'import yaml' 2>/dev/null || { echo "CONTROL FAIL: PyYAML required on the parent host (pip3 install pyyaml)" >&2; exit 3; }

# ── 0a. Canonicalize + validate every path (gate-r10 R10-H-4) ───────────────────────────────────────
RECEIPT=$(abspath "$RECEIPT");   [[ -f "$RECEIPT" ]]   || { echo "PATH FAIL: receipt $RECEIPT missing" >&2; exit 3; }
CANDIDATE=$(abspath "$CANDIDATE"); [[ -d "$CANDIDATE" ]] || { echo "PATH FAIL: candidate $CANDIDATE missing" >&2; exit 3; }
HANDBACK=$(abspath "$HANDBACK")
OUT=$(abspath "$OUT")
case "$HANDBACK" in "$CANDIDATE"/*) echo "PATH FAIL: handback dest inside candidate" >&2; exit 3 ;; esac
case "$OUT"      in "$CANDIDATE"/*) echo "PATH FAIL: register dest inside candidate" >&2; exit 3 ;; esac

# ── 0b. Load the PARENT DISPATCH RECEIPT (gate-r10 R10-C-1 — the named independent record) ──────────
receipt_get() {
  python3 - "$RECEIPT" "$1" <<'PY'
import sys, yaml
d = yaml.safe_load(open(sys.argv[1]))
cur = d
for part in sys.argv[2].split("."): cur = cur[part]
print(cur)
PY
}
R_PKG=$(receipt_get package)
[[ "$R_PKG" == "$PACKAGE" ]] || { echo "RECEIPT FAIL: receipt package $R_PKG != --package $PACKAGE" >&2; exit 3; }
BASE=$(receipt_get base_sha)
MANIFEST=$(receipt_get manifest_path)          # canonical ABSOLUTE path per the receipt
EXPECTED_MANIFEST_SHA=$(receipt_get manifest_sha256)
[[ "$MANIFEST" = /* ]] || { echo "RECEIPT FAIL: manifest_path must be absolute" >&2; exit 3; }
[[ -f "$MANIFEST" ]] || { echo "RECEIPT FAIL: canonical manifest $MANIFEST missing" >&2; exit 3; }

# ── 0. Authenticate the manifest ITSELF before parsing anything from it (gate-r9 R9-H-3) ────────────
MANIFEST_SHA=$(sha "$MANIFEST")
[[ "$MANIFEST_SHA" == "$EXPECTED_MANIFEST_SHA" ]] || { echo "CONTROL FAIL: manifest $MANIFEST_SHA != expected $EXPECTED_MANIFEST_SHA" >&2; exit 3; }

manifest_get() {
  python3 - "$MANIFEST" "$1" <<'PY'
import sys
key = sys.argv[2].split(".")
data = {}; stack = [(0, data)]
for line in open(sys.argv[1]):
    if not line.strip() or line.lstrip().startswith("#"): continue
    indent = len(line) - len(line.lstrip())
    k, _, v = line.strip().partition(":")
    while stack and stack[-1][0] >= indent and len(stack) > 1: stack.pop()
    parent = stack[-1][1]; v = v.strip()
    if v == "": parent[k] = {}; stack.append((indent, parent[k]))
    else: parent[k] = v.strip('"')
cur = data
for part in key: cur = cur[part]
print(cur)
PY
}

# ── 1. Verify EVERY control input against the authenticated manifest (fail closed) ──────────────────
SELF_EXPECTED=$(manifest_get "controls.bridge_final_sha256")
SELF_ACTUAL=$(sha "$0")
[[ "$SELF_ACTUAL" == "$SELF_EXPECTED" ]] || { echo "CONTROL FAIL: this bridge ($SELF_ACTUAL) != manifest pin ($SELF_EXPECTED)" >&2; exit 3; }
CONTROL_LINES="manifest_sha256: ${MANIFEST_SHA}"$'\n'"control_sha256: adversarial-review-final.sh=$SELF_ACTUAL"
BRIEF_PATH=$(manifest_get controls.brief_path); HARNESS_PATH=$(manifest_get controls.harness_path)
for pair in "brief=$BRIEF_PATH" "harness=$HARNESS_PATH"; do
  name="${pair%%=*}"; path="${pair#*=}"
  expected=$(manifest_get "controls.${name}_sha256"); actual=$(sha "$path")
  [[ "$actual" == "$expected" ]] || { echo "CONTROL FAIL: $name ($path) $actual != $expected" >&2; exit 3; }
  CONTROL_LINES+=$'\n'"control_sha256: ${name}=${actual}"
done
LENS_BLOCK=""
LENSES=$(manifest_get "packages.$PACKAGE.lenses")
IFS=',' read -ra LARR <<< "$LENSES"
for lens in "${LARR[@]}"; do
  lp=$(manifest_get "lens_contracts.$lens.path"); le=$(manifest_get "lens_contracts.$lens.sha256")
  la=$(sha "$lp")
  [[ "$la" == "$le" ]] || { echo "CONTROL FAIL: lens $lens ($lp) $la != $le" >&2; exit 3; }
  CONTROL_LINES+=$'\n'"control_sha256: lens:${lens}=${la}"
  LENS_BLOCK+=$'\n'"===== LENS CONTRACT: ${lens} ====="$'\n'"$(cat "$lp")"$'\n'
done
MAX_ROUNDS=$(manifest_get "packages.$PACKAGE.max_fix_rounds")
MILESTONE=$(manifest_get "packages.$PACKAGE.final_milestone")
PROGRESS_PATH=$(manifest_get "packages.$PACKAGE.progress_path")
# Receipt projection fields must EQUAL the authenticated manifest's package block (gate-r11 R11-M-2)
for pair in "progress_path=$PROGRESS_PATH" "final_milestone=$MILESTONE" "max_fix_rounds=$MAX_ROUNDS" "lenses=$LENSES"; do
  key="${pair%%=*}"; mval="${pair#*=}"
  rval=$(receipt_get "$key")
  [[ "$rval" == "$mval" ]] || { echo "RECEIPT FAIL: receipt $key '$rval' != manifest '$mval' (stale receipt half — gate-r11 R11-M-2)" >&2; exit 3; }
done

# ── 2. Bind the review base to the PARENT'S record, never candidate YAML (gate-r9 R9-C-1) ───────────
git -C "$CANDIDATE" merge-base --is-ancestor "$BASE" "$ACCEPTED" || { echo "BASE FAIL: $BASE not an ancestor of $ACCEPTED" >&2; exit 3; }
[[ "$BASE" != "$ACCEPTED" ]] || { echo "BASE FAIL: base == accepted (empty review range)" >&2; exit 3; }
# Field-check candidate progress YAML against the receipt + manifest projection (gate-r10 R10-H-1:
# COMPLETE and FAIL-CLOSED — PyYAML availability was asserted at startup; no partial fallback exists)
python3 - "$CANDIDATE/$PROGRESS_PATH" "$BASE" "$MILESTONE" "$MAX_ROUNDS" "$MANIFEST" "$EXPECTED_MANIFEST_SHA" <<'PY' || exit 3
import sys, yaml
d = yaml.safe_load(open(sys.argv[1]))
base, milestone, maxr = sys.argv[2], sys.argv[3], int(sys.argv[4])
man_path, man_sha = sys.argv[5], sys.argv[6]
if str(d.get("base_sha")) != base:
    sys.exit(f"YAML FIELD FAIL: candidate base_sha {d.get('base_sha')} != receipt base {base}")
if int(d.get("max_fix_rounds")) != maxr:
    sys.exit("YAML FIELD FAIL: top-level max_fix_rounds != manifest projection")
cm = d.get("control_manifest")
if not isinstance(cm, dict) or str(cm.get("path")) not in (man_path,) or str(cm.get("sha256")) != man_sha:
    sys.exit(f"YAML FIELD FAIL: control_manifest {{path,sha256}} != dispatch receipt ({cm!r})")
ms = d.get("milestones")
if not isinstance(ms, list):
    sys.exit("YAML FIELD FAIL: milestones missing/unparseable")
final = [m for m in ms if m.get("id") == milestone]
if len(final) != 1:
    sys.exit(f"YAML FIELD FAIL: expected exactly one milestone {milestone}, found {len(final)}")
if final[0].get("status") != "review":
    sys.exit(f"YAML FIELD FAIL: {milestone} status {final[0].get('status')} != review")
if int(final[0].get("fix_rounds") or 0) > maxr:
    sys.exit("YAML FIELD FAIL: fix_rounds exceeds max")
PY
# Control-file + manifest-surrogate preflight over the review range (gate-r10 R10-C-1 tail):
CHANGED=$(git -C "$CANDIDATE" diff --name-only "$BASE".."$ACCEPTED")
while IFS= read -r p; do
  case "$p" in
    scripts/adversarial-review.sh|scripts/adversarial-review-final.sh|docs/handoff/SELF-REVIEW-HARNESS.md|docs/handoff/enforcement-control-manifest.yaml|docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md|.claude/agents/*-reviewer.md)
      echo "PREFLIGHT FAIL: control file changed in range: $p" >&2; exit 3 ;;
    *control-manifest*|*control_manifest*)
      echo "PREFLIGHT FAIL: manifest surrogate added/changed in range: $p" >&2; exit 3 ;;
  esac
done <<< "$CHANGED"

# ── 3. Seal: exactly ONE dirty entry (the untracked handback), bridge copies+hashes it itself ───────
STATUS=$(git -C "$CANDIDATE" status --porcelain)
EXPECTED_STATUS="?? ${HANDBACK_SRC}"
[[ "$STATUS" == "$EXPECTED_STATUS" ]] || { echo "SEAL FAIL: status must be exactly '$EXPECTED_STATUS', got:" >&2; echo "$STATUS" >&2; exit 3; }
HEAD_SHA=$(git -C "$CANDIDATE" rev-parse HEAD)
[[ "$HEAD_SHA" == "$ACCEPTED" ]] || { echo "SEAL FAIL: candidate HEAD $HEAD_SHA != accepted $ACCEPTED" >&2; exit 3; }
mkdir -p "$(dirname "$HANDBACK")"
cp "$CANDIDATE/$HANDBACK_SRC" "$HANDBACK"; chmod a-w "$HANDBACK"
HB_SHA=$(sha "$HANDBACK")
SNAPROOT=$(mktemp -d); SNAP="$SNAPROOT/snap"
git -C "$CANDIDATE" worktree add --detach "$SNAP" "$ACCEPTED" >/dev/null 2>&1
trap 'git -C "$CANDIDATE" worktree remove --force "$SNAP" >/dev/null 2>&1 || true' EXIT
chmod -R a-w "$SNAP" 2>/dev/null || true

# ── 4. Invoke the reviewer from a NEUTRAL EMPTY cwd (gate-r9 R9-H-1); brief+harness injected ────────
mkdir -p "$(dirname "$OUT")"
NEUTRAL=$(mktemp -d)/run; mkdir -p "$NEUTRAL"
PROMPT=$(cat <<PROMPT_EOF
You are the ADVERSARIAL FINAL-GATE reviewer for enforcement package ${PACKAGE} milestone ${MILESTONE},
round ${ROUND}. READ-ONLY. Review the SEALED snapshot at ${SNAP} (detached at ${ACCEPTED}); range
${BASE}..${ACCEPTED}. Use ONLY absolute paths — your working directory is intentionally empty and
neutral. The BINDING acceptance authority is the brief reproduced below (its package section for
${PACKAGE}) and the harness contract; apply EVERY lens contract reproduced below in full — binding
checklists, not labels. Additionally open and evaluate the handback file at ${HANDBACK}: its
census/classification tables are review targets; your register MUST quote, for each required table,
the row count and the first and last row keys (content-derived evidence of inspection). End with
EXACTLY ONE line that is EXACTLY:
VERDICT: ACCEPT
or
VERDICT: CHANGES-REQUIRED

===== BINDING BRIEF (${BRIEF_PATH}) =====
$(cat "$BRIEF_PATH")

===== HARNESS CONTRACT (${HARNESS_PATH}) =====
$(cat "$HARNESS_PATH")
${LENS_BLOCK}
PROMPT_EOF
)
TMP="$(mktemp)"
if ! (cd "$NEUTRAL" && claude -p "$PROMPT" --model opus --permission-mode bypassPermissions --output-format text) > "$TMP" 2>"${TMP}.err"; then
  echo "reviewer invocation failed (parent-owned tool_error; retry with --attempt $((ATTEMPT+1)))" >&2
  tail -5 "${TMP}.err" >&2; rm -f "$TMP" "${TMP}.err"; exit 3
fi

# ── 5. Post-run re-assertions (gate-r9 R9-H-1) ─────────────────────────────────────────────────────
SNAP_HEAD=$(git -C "$SNAP" rev-parse HEAD)
[[ "$SNAP_HEAD" == "$ACCEPTED" ]] || { echo "POST FAIL: snapshot HEAD moved to $SNAP_HEAD" >&2; exit 3; }
SNAP_DIRTY=$(git -C "$SNAP" status --porcelain)
[[ -z "$SNAP_DIRTY" ]] || { echo "POST FAIL: snapshot dirtied during review" >&2; exit 3; }
HB_SHA2=$(sha "$HANDBACK")
[[ "$HB_SHA2" == "$HB_SHA" ]] || { echo "POST FAIL: immutable handback changed" >&2; exit 3; }

# ── 6. Register + EXACT verdict parsing (gate-r9 R9-H-4) ────────────────────────────────────────────
{
  echo "# FINAL-GATE REGISTER — package ${PACKAGE} ${MILESTONE} round ${ROUND} attempt ${ATTEMPT}"
  echo "accepted_sha: ${ACCEPTED}"
  echo "base_sha: ${BASE}   # from the parent DISPATCH RECEIPT (${RECEIPT}), ancestry-verified"
  echo "dispatch_receipt: ${RECEIPT}"
  echo "snapshot: detached-worktree @ ${ACCEPTED} (sealed; re-asserted post-run)"
  echo "handback_sha256: ${HANDBACK}=${HB_SHA}   # copied+hashed by this bridge; not in the prompt"
  echo "${CONTROL_LINES}"
  echo "max_fix_rounds: ${MAX_ROUNDS}"
  echo "---"
  cat "$TMP"
} > "$OUT"
rm -f "$TMP" "${TMP}.err"

VCOUNT=$(grep -c '^VERDICT:' "$OUT" || true)
[[ "$VCOUNT" == "1" ]] || { echo "NO SINGLE VERDICT LINE ($VCOUNT found) — tool_error, fail closed" >&2; exit 3; }
VERDICT_LINE="$(grep '^VERDICT:' "$OUT")"
LAST_NONEMPTY="$(grep -v '^[[:space:]]*$' "$OUT" | tail -1)"
[[ "$VERDICT_LINE" == "$LAST_NONEMPTY" ]] || { echo "VERDICT NOT FINAL: trailing content after verdict line — tool_error, fail closed (gate-r10 R10-H-3)" >&2; exit 3; }
case "$VERDICT_LINE" in
  "VERDICT: ACCEPT")            echo "ACCEPT";           exit 0 ;;
  "VERDICT: CHANGES-REQUIRED")  echo "CHANGES-REQUIRED"; exit 2 ;;
  *) echo "NON-EXACT VERDICT '$VERDICT_LINE' — tool_error, fail closed" >&2; exit 3 ;;
esac
