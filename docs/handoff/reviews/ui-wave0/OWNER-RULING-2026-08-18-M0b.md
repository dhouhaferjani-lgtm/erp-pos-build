# Owner ruling — UI Wave 0 M0b

**Authority:** parent/owner messages delivered to the UI Wave 0 executor on 2026-08-17 and 2026-08-18. This file transcribes the operative ruling into the branch so fresh-session resumes and bridge reviews do not depend on chat history.

## 2026-08-17 — tests-only milestone authorized

- Option 2 is authorized: insert a tests-only milestone M0b before M1.
- Re-pin the branch to curated dev tip `d682b38ec9761a917b9716428091a482745795f6`; do not fetch and re-pin.
- Re-derive the failing set at that pinned base.
- Fix stale tests to assert current ruled behavior, with a one-line comment citing the promoted lane that changed it.
- M0b diffs are limited to test files; production changes are forbidden.
- If a failure expresses a real current product defect, do not bend the test to production behavior.
- Exit requires the remaining full web test set, typecheck, and lint, followed by the normal bridge review with `frontend-conventions` and `general` lenses.
- M0b commits use `Phase 0.0b.<seq>`.

## 2026-08-18 — real-defect exception amendment

- Arabic coverage is confirmed as a product defect owned by `CODEX-DISPATCH-arabic-i18n-backfill-2026-08-10.md`; Arabic parity is not launch scope.
- M0b may exit with an enumerated exception list of individually confirmed real-behavior defects. This is not a frozen allowlist.
- Every exception must remain a failing valid test, be recorded as a finding with its owning lane, and have its classification verified by the M0b bridge.
- Continue classifying and repairing other failures; do not hard-stop unless a defect is severe enough to invalidate this wave's own tasks.
- Later milestones inherit the same bridge-reviewed exception set.

Everything else in `CODEX-DISPATCH-ui-wave0-2026-08-11.md` remains unchanged.
