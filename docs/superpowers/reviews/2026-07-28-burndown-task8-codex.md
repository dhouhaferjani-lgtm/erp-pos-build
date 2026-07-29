# Codex adversarial review — burn-down Task 8 (productized Phase ② backfills)

Diff: be95f49bc..6351fd9a3 · Reviewer: Codex CLI · 2026-07-29
(Transcribed by the orchestrator; controller adjudications inline.)

## Round 1 Verdict: REJECT

### Findings requiring fixes (in scope — the new commands/tests)

- **[Important]** `BackfillBanksCommand:80-114` — per-company rollback preview
  overcounts when companies share a tenant-scoped directory; preview must run once per
  tenant batch in a single rolled-back transaction.
- **[Important]** `BackfillBanksCommand:114-134` — output reports creations only;
  `BanksSeeder` also refreshes bic/position/city → dry-run must report updates too.
- **[Important]** `SeedChartsCommand:95-113` — same honesty gap for `is_system`
  promotions and `parent_id` rewrites.
- **[Important]** `BackfillBanksCommand:25-37` — documented fleet form
  `tenants:run treasury:backfill-banks --dry-run` is invalid; correct syntax is
  `--option=dry-run=1` (checklist style). Fix docblocks/report.
- **[Important]** Tests (both) — no failure-surfacing coverage (delegate throw → exit 1
  + tenant-aware log literal + abort marker), no multi-company same-country preview
  accuracy case, chart assertions don't cover the checklist's seven Phase-② codes.
- **[Minor]** `SeedChartsCommand:58-116` — "across 0 companies" exits 0 silently; keep
  exit 0 but assert the stable marker in tests so grep gates can reject it.

### Adjudicated out of scope (controller) — pre-existing platform/seeder behavior

- **[Critical→platform, mitigated]** `stancl tenants:run` discards child exit codes —
  vendor property true of every existing command incl. the pattern command; the deploy
  checklists already mandate grep gates for exactly this. Reviewer's grep-gate strings
  adopted into the command docblocks (see fix round). An exit-aggregating batch
  contract is a platform change — 🎫 ticketed, not Task 8.
- **[Important→ticket]** `BanksSeeder:35-58` custom-bank identity collision can touch
  custom rows — pre-existing Phase-② seeder semantics; changing it alters shipped
  behavior. 🎫 ticketed.
- **[Important→ticket]** TN/FR/Generic chart seeders unconditionally re-issue
  parent-link updates (apply not a strict write-no-op) — pre-existing seeder
  semantics. 🎫 ticketed.
- **[Important→documented]** `--tenant/--all` scoping: `tenants:run --tenants=` already
  provides fleet scoping (pattern parity); FK-boundary preflight unnecessary now the FK
  migration is fleet-applied — ordering assumption documented instead.
- **[Important→documented]** missing-directory skip keeps exit 0 (only TN directory
  ships today; non-TN skip is legitimate) with the stable `skipped (no directory)`
  marker as the grep-gate signal.

### Reviewer-supplied grep-gate strings — adopted verbatim into the docblocks.
