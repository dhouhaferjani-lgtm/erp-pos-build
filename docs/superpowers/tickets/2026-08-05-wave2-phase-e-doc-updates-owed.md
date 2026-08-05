# Ticket — Phase-E doc updates owed after the cat-(b) wave-2 verifier conversion

**Opened:** 2026-08-05
**Source:** the wave-2 fix round for the two adversarial reviews
(`docs/superpowers/reviews/2026-08-05-cat-b-wave2-fiscal-review.md` R5/M2,
`docs/superpowers/reviews/2026-08-05-cat-b-wave2-tenancy-review.md` M2).
**Status:** OPEN — line edits listed, not applied.

## Why this is a ticket and not a commit

The fix round updated every NON-Phase-E doc that the conversion invalidated
(`docs/runbooks/fiscal-verify-all-chains.md` rewritten for the new per-tenant contract;
`docs/superpowers/specs/2026-06-24-parapharmacy-launch-e2e-campaign-design.md` §4.2 recovery
command corrected). The documents below are **human-executed Phase-E gate sheets** whose Status /
Actual / Evidence cells are owner-owned and deliberately empty until execution. Editing their pass
criteria mid-flight is the launch program owner's call, not a side effect of a fix round — so the
exact edits are recorded here instead.

---

## 1. `docs/qa/2026-05-12-first-tenant-smoke.md`

Status header: *REPAIRED (v5.1 Lane D2-a, 2026-07-31) … executable but has not been run.*

### 1a. Line 117 (step D.1) — pass criterion must include the exit code

Current expected: `Exit 0; output ends "Status: ALL CHAINS VALID ✓"`

The string is now gated on a clean aggregate AND full tenant coverage, so it can no longer appear
on a failing run — but the criterion should still be read as a conjunction, and it should tell the
operator where to look when it is absent.

Proposed:

> Exit 0 **AND** output ends `Status: ALL CHAINS VALID ✓`. If the run ends
> `Status: INCOMPLETE`, read the `TENANT COVERAGE:` block immediately above it: a `SKIPPED` or
> `ERRORED` line names a tenant for which **nothing was verified**. That is a FAIL, not a partial
> pass.

### 1b. Line 118 (step D.2) — same treatment

Current expected: `Exit 0; output ends "All chains verified successfully."`

Proposed:

> Exit 0 **AND** output ends `All chains verified successfully.` A run that ends
> `Chain verification INCOMPLETE - N tenant(s) produced no verdict: …` is a FAIL.

### 1c. Line 123 — the `--fix` warning is now obsolete

Current: *Never pass `--fix` to `fiscal:verify-chains` unattended during this smoke (it rewrites
chain links).*

`--fix` was **removed** on 2026-08-05 (fiscal review R6): it was declared and documented as
dangerous but read by nothing, and a server-side "fix" of a device-authored chain would itself be a
fiscal-integrity defect. Passing it now fails with an unknown-option error.

Proposed replacement:

> `fiscal:verify-chains` has no `--fix` flag (removed 2026-08-05 — it was dead code, and a
> server-side rewrite of a device-authored chain would be a fiscal-integrity defect in its own
> right). If a chain is broken, page the on-call; do not attempt a repair from the CLI.

### 1d. Steps D.1 / D.2 — commands may now name the tenant

Both verifiers gained `--tenant=<uuid>`. `--company` still works, but on a fleet the smoke reads
more cleanly scoped:

- D.1: `php artisan fiscal:verify-chains --tenant=<TENANT_UUID> --company=<COMPANY_UUID>`
- D.2: `php artisan pos:verify-chains --tenant=<TENANT_UUID> --company=<COMPANY_UUID> --terminal=<TERMINAL_UUID> --type=all`

Optional; the existing `--company`-only form is still correct and still fails closed when the
filter matches nothing.

### 1e. Output-shape note for evidence capture

`pos:verify-chains` per-tenant output changed shape (fiscal review M1): `Verifying %d terminal(s)...`
is now `TENANT <id> (<slug>): verifying %d terminal(s)...`, and the historic `Terminal 'X' not
found.` line is replaced by `The --terminal / --company filter matched no terminal in any reachable
tenant.` Any transcript diff or grep in the evidence pack that keys on the old strings needs
updating. The D.2 pass string itself is unchanged.

---

## 2. `docs/qa/2026-08-01-money-test-plan.md`

Status header: *AUTHORED, NOT EXECUTED.*

### 2a. Line 1652 (Y-2) and 1653 (Y-3) — exit code + coverage block

Same conjunction as 1a/1b: the expected column should read "exit 0 AND the banner", plus the
`TENANT COVERAGE:` instruction.

### 2b. Line 1653 (Y-3) — drop the `--fix` warning

Current: *"**Never pass `--fix`** — it is destructive and would mask exactly what this gate is
looking for."* The flag no longer exists (see 1c). Replace with the same wording.

### 2c. Y-1 / Y-4 need no change

`fiscal:verify-event-chain` already passes `--tenant`, which is what the conversion made required.

---

## 3. Superseded specs — recorded, deliberately NOT edited

- `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v5.md:645` and
  `…-v4.md:633` document §15.2 as `{--fiscal-event-id=} {--tenant=}` with `--tenant` OPTIONAL.
  Both are **superseded by v7** (their own headers say so), so they are historical artifacts and
  must not be rewritten.
- `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md` §15.2 carries the same
  now-stale optionality. v7's header status is *"Drafted … Awaiting Codex re-review →
  writing-plans"*, i.e. it is an in-flight artifact owned by another workflow. The correction owed
  is one sentence: **`--tenant` is REQUIRED and BINDS the tenant database; there is deliberately no
  fleet mode, because the `--actor-id` permission gate resolves in exactly one tenant's `users`
  table.** Apply it when that spec next moves, not from a fix round.

---

## 4. PHPStan exclusion (tenancy review M2)

`apps/api/phpstan.neon:19` excludes `app/Console/Commands/MigrateParapharmacyDataCommand.php` from
`analyseAndScan`. Consequence: the wave's `larastan.console.undefinedOption` clearance covers 11 of
the 12 converted commands, not 12. The command declares its own options and reads only options it
declares, so there is no live defect — but the guarantee is 11/12, and any future option drift in
that file is unguarded.

Disposition owed: either remove the exclusion and fix whatever it was hiding, or record why it is
permanent. Note that the file was materially reworked on 2026-08-05 (M1 `--limit` cap, M3 dry-run
carve-out), so the original reason for the exclusion may no longer apply.

---

## Related

- `docs/superpowers/tickets/2026-08-05-cross-tenant-annotation-ast-check.md` — the companion AST
  check for `@cross-tenant-by-design` justifications. Wave 2 found 4 of 9 refreshed justifications
  factually false (tenancy review R4, fixed in this round); an AST check that verified the claim
  against the code would have caught them.
