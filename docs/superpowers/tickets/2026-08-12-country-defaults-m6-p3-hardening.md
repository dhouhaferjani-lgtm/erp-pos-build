# Country defaults M6 P3 hardening

Source register:

- `docs/handoff/reviews/country-defaults-phase-a/M6-round1.md`
- `docs/handoff/reviews/country-defaults-phase-a/M6-round2.md`
- `docs/handoff/reviews/country-defaults-phase-a/M6-round3.md`
- M6 execution record: `docs/sessions/codex-country-defaults-phase-a-report.md`

This ticket is the durable disposition for the M6 P3 and process notes that are broader than the
scoped adversarial-review UI fixes.

## Code and test follow-ups

- **Round 1 #7 — behavioral red-first evidence:** CLOSED in M6 round 2. The round-2 execution record
  preserves both the initial behavioral RED failures and an explicit scope-gate mutation/revert
  replay that fails only the named debounce regression test before returning green.
- **Round 1 #9 — production route-tree inventory:** replace or complement the hand-maintained admin
  route manifest test with an inventory derived from the production route tree. The ratchet must
  fail when any `/admin` child route is added without the required `RequireAdminRole` policy
  boundary. Owner: web platform. Status: OPEN.
- **Round 1 #6 — modal platform behavior:** country-defaults now uses the shared canonical `Modal`
  for both dialogs. The shared modal still owns the cross-application work to add and test focus
  containment, Escape-to-close, and inert/background interaction semantics. Owner: web platform
  and accessibility. Status: OPEN outside the M6 feature scope.
- **Round 2 #4 — actionable row validation detail:** map Laravel's nested
  `error.errors.rows.N.<field>` response onto the matching grid row and field rather than collapsing
  every 422 into one generic message. Owner: country-defaults frontend. Status: OPEN; visible error
  handling is safe today, but field-level guidance needs a deliberate API-error adapter and per-cell
  presentation design.
- **Round 2 #8 — aggregate validation findings:** replace the backend validator's first-exception
  report with a stable ordered accumulator so one preview can return all independent certification
  violations. Owner: country-defaults domain. Status: OPEN; the current singular report satisfies
  the milestone's persistent-panel contract but requires repeated fix/validate cycles.

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

## Closed in the round-3 response

- A validation-preview 422 is a permanent scope rejection with explicit localized timbre and
  wildcard guidance; transport failures retain the temporary-unavailable message.
- The persistent panel shows a localized incomplete-scope hint, including after Publish is closed.
- The unused frontend `updateTemplate` export and its test double are removed. The backend metadata
  endpoint remains available to non-M6 consumers; metadata editing is not an M6 deliverable.
- Row-save completion relies on the mutation hook's awaited query invalidation before releasing
  local edits, producing one post-save GET rather than an explicit second refetch.
- Deleting a row removes its keyed grid error, including when a generated row identity is reused.
