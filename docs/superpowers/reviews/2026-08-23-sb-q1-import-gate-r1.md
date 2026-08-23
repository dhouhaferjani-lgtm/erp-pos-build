# Gate record — Session B lane Q-1 (import re-execution guard), imports-reviewer r1

Commit `649514944`, branch `fix/sb-q1-import-reexecution-guard`, merged to local dev under S-17.

**Verdict: ACCEPT** (spec ✅, quality approved with recorded follow-ups). Red-first proven REAL
by revert-probe (6/7 red at `649514944^`, 7/7 green at tip). PG leg re-run by the gate on a
throwaway 5433 DB: lane file 7/7; full `tests/Feature/Import` on PG = 122 tests with 1 error +
1 failure both PROVEN pre-existing on the base commit (sqlite-masks-PG test defects, see LEDGER
C-10). Scope discipline verified (4 files, all Import module + new test). Route middleware
untouched.

**Brief correction verified**: `ImportJob::canBeExecuted()` does not exist; the real guard
`canStart()` was already called at `ImportController.php:508`. Audit #23 items 1–2 stale on
this tree. Attribution correction (finding 3): the load-bearing fix is (b)+(d)
(Pending-before-dispatch + is_imported filter) closing the clobbered-`Pending` replay; the
typed 422 (a) is a refusal-quality improvement — a genuinely `Completed` job was already
refused by `canStartImport()`.

**Recorded follow-ups (audit #23 closes as PARTIAL, not done):**
1. [Important] `updateOptions` (`ImportController.php:375`) re-arms a dispatched job: PATCH
   allowed while `Pending` (which now also means "queued") → `validateJob()` writes
   `Validating`/`Validated` over a worker's `Importing` → Execute re-appears in the wizard →
   second dispatch claims successfully. Fix shape: a distinct `Queued` status or
   `dispatched_at` discriminator. → LEDGER C-9.
2. [Important] Sync twin `ImportService::executeImport()` (`:327-336`) still has no claim —
   two concurrent executes on a ≤100-row job both apply. 6-line mirror of the worker claim.
   → LEDGER C-9.
3. [Minor] Refusal message wording covers Failed/Validating inaccurately; FE never surfaces
   `error.code` (same gap as IMPORT_TYPE_RETIRED) — FE i18n mapping follow-up.
4. [Minor] "resume-shaped" comment overstates: `finalizeImport` phases select on
   `is_imported = true` (replay-shaped); downstream guards hold.
5. [Minor] Product-images `Pending` write is a no-op (job created Pending); harmless.
6. [Info] 202 body may now report `importing`/`completed` on a fast worker; wizard tolerates.
