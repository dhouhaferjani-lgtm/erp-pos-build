# Ticket: G3 shift-variance GL — the kill-switch window has NO replay or backfill (PRE-ENABLE)

Found by the fiscal-pos gate re-review of DPA lane G3 (finding N1), 2026-08-08. **This is the
sharpest of the three G3 carve-outs: it is not a gap in coverage, it is a permanently
unrecoverable one.**

`PostShiftCashVarianceAdjustment::handle()` (apps/api/app/Modules/Treasury/Application/Listeners
/PostShiftCashVarianceAdjustment.php:146-150) returns before any work when
`treasury.shift_variance_gl_enabled` is false. That is correct — it consumes nothing, marks
nothing and poisons no idempotency key, so disable→enable is clean. **But there is no way to
catch up afterwards**, because nothing will ever re-raise `CashCountRecorded` for a shift that
has already closed:

- OFFLINE: a re-sync of the same Z report short-circuits at `ZReportSyncController.php:164-172`
  with `200 duplicate` — **before** the dispatch block at `:266-276`.
- LIVE: `ReportGenerationService.php:200-206` early-returns on an existing Z for the shift, so
  the cash-count branch (and its event) never runs a second time.
- Upstream of both, `pos_z_reports.shift_id` is UNIQUE
  (`2026_04_23_000001_add_shift_id_unique_to_pos_z_reports.php:34`), so a shift can never acquire
  a second Z report to hang a second event off.

The reviewer's probe through the real endpoint:

```
flag OFF  → docs=0 movements=0 audit=0   (but shift_variance='-5.0000', alerts=1)
then flag ON, device re-syncs the SAME Z → HTTP 200 {"status":"duplicate"}
after enable+replay                     → docs=0 movements=0
```

Why it bites: the lane ships DISABLED until Treasury represents the opening float and drawer
operations in the whole-drawer basis (SV-3/SV-4), so **every shift closed between merge and enable is permanently without its
658/758 leg**. `pos_shifts.variance` is stamped and the fraud alert fires (that half is ungated —
see the deploy note), so the tenant has a recorded variance with no ledger counterpart: exactly
the document-per-action defect this lane exists to remove, reintroduced by its own safety gate.
`grep shift_variance_gl_enabled app/ config/ tests/` returns only the config entry, the provider
comment and the tests — no backfill command exists.

## Fix shape — an idempotent backfill artisan command

The lane's own idempotency design makes this cheap and safe, and that is the point: a backfill and
a live booking **converge on the same row** rather than racing to create two.

`treasury:backfill-shift-variance-gl` (tenant-scoped; must be a `TenantScopedCommand` — see
`project_staging_2026_08_04_upload_500_and_cross_tenant_jobs`, four commands were already
converted for exactly this reason):

- `--from=YYYY-MM-DD --to=YYYY-MM-DD` window, `--company=` filter, `--dry-run` default ON.
- Select closed `pos_shifts` with a non-null, non-zero `variance` and **no**
  `repository_adjustments` row carrying that `pos_shift_id` (the partial unique index from
  `2026_08_08_140000_unique_repository_adjustments_pos_shift.php` makes this a cheap anti-join).
- For each, rebuild the same intent the listener would have built and call the SAME port,
  `RepositoryAdjustmentServiceInterface::post()`. Reuse **verbatim**:
  - the derived document id `Uuid::uuid5(NAMESPACE_URL, 'urn:autoerp:pos-shift-cash-variance:{shiftId}')`,
  - the movement key leg `shift:{shiftId}`.
  So if the listener later fires for the same shift (or the command is re-run), `firstOrCreate` +
  the movement port's idempotency + the partial unique index all resolve to the existing row.
- Reuse the listener's refusal set unchanged — is_physical, cash-till type, currency match, the
  unattributable-tolerance probe, the aggregate/breakdown cross-check — and write the same
  `treasury.shift_variance_gl_skipped` audit rows, so a backfilled window is queryable the same
  way a live one is.
- Source of the amount: `pos_shifts.variance` (the C1 unification means that column now IS the
  aggregate the listener would have booked). Source of the per-tender attribution:
  `pos_z_report_counts` rows for the shift's Z report — the persisted equivalent of
  `CashCountRecorded::$tenderBreakdown`.
- Emit a summary: eligible / booked / refused-by-reason, so the operator can reconcile the window
  before and after.

**Do NOT flip `TREASURY_SHIFT_VARIANCE_GL_ENABLED` until either this command exists, or the owner
explicitly signs off that the disabled window is written off** (in which case record the window's
start date so the hole is bounded and documented rather than discovered later).

Related: `docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md`,
`docs/superpowers/tickets/2026-08-08-g3-v3-terminals-no-cashcount-producer.md`,
`docs/superpowers/tickets/2026-08-08-g3-legacy-closeshift-no-gl-leg.md`.
