# Country defaults M6 P3 hardening

Source register:

- `docs/handoff/reviews/country-defaults-phase-a/M6-round1.md`
- M6 execution record: `docs/sessions/codex-country-defaults-phase-a-report.md`

This ticket is the durable disposition for the M6 P3 and process notes that are broader than the
scoped round-1 UI fixes.

## Code and test follow-ups

- **Round 1 #7 — behavioral red-first evidence:** replay the M6 acceptance behaviors with an
  intentional production mutation or reverted implementation, and preserve the failing command
  output in the execution record. The original M6 RED run was dominated by unresolved-module
  failures, so it proved that the new files did not exist but did not independently demonstrate
  each behavior failing. Owner: execution harness. Status: PROCESS FOLLOW-UP.
- **Round 1 #9 — production route-tree inventory:** replace or complement the hand-maintained admin
  route manifest test with an inventory derived from the production route tree. The ratchet must
  fail when any `/admin` child route is added without the required `RequireAdminRole` policy
  boundary. Owner: web platform. Status: OPEN.
- **Round 1 #6 — modal platform behavior:** country-defaults now uses the shared canonical `Modal`
  for both dialogs. The shared modal still owns the cross-application work to add and test focus
  containment, Escape-to-close, and inert/background interaction semantics. Owner: web platform
  and accessibility. Status: OPEN outside the M6 feature scope.

## Closed in the round-1 response

- Visible localized query and mutation errors distinguish failures from valid empty/invalid states.
- Template-validation scope is normalized and omitted when empty, with frontend boundary and
  backend endpoint coverage for empty and spaced `TN, FR` input.
- Assignment rows retain the authoritative current template option while eligibility is loading,
  missing, or failed.
- Generic admin shell copy uses the shared `admin` namespace; country-default navigation uses its
  feature namespace explicitly, preserving Arabic support-access copy.
- Canonical modals, actual plural counts, post-save row-edit reset, and localized unknown protection
  sources are implemented in the M6 UI.
