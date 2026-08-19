# Orchestrator ruling — M4 STOP B discharge and amended treasury gate

**Issued:** 2026-08-19

**Authority:** parent orchestrator, incorporating the treasury register

**Applies to:** Wave 3D M4–M5

**3D base:** `48cebf0f2c1b481c592bf35d478302499f9fda5d`

STOP B is discharged only far enough for M4 to author the account-map proposal. It does not approve an account map and does not authorize T20/T20b production implementation before the renewed owner gate.

1. Accepted 3C (M0–M3) is merged to `dev` at `1e8c0fa03`. Parent-side merge-seam reconciliation is at `3a8e4b05b`; it changes the V1 purpose manifest from 41 to 43 entries, re-derives citations, and marks the frozen fixture. Wave 3D is recreated from `3D_BASE_SHA = 48cebf0f2`.
2. **F-1:** `TunisiaChartOfAccountsSeeder`, `FranceChartOfAccountsSeeder`, and `GenericChartOfAccountsSeeder` are frozen and fingerprint-locked by `FrozenSeederDocblockTest`. T20 must author the shrinkage/gain codes in a new country-defaults chart-template version. Editing the seeders directly is forbidden. The proposal must state whether the frozen legacy fallback is explicitly re-pinned or deliberately omits the purposes behind a guarded no-op.
3. **F-2:** `InventoryGainIncome => AccountType::Revenue` and the publish-time type check currently foreclose a 603 symmetric-credit model. The M4 memo must present both owner choices: a class-7 other-income model, and a 603 model that reopens `expectedAccountType()`. Both carry the OQ-12/H-5 liasse caveat. The executor must not choose. M4 stops `blocked_owner` at map approval.
4. **F-3:** the map must classify every `MovementReason` as COGS, shrinkage/gain, or neither/another accounting lane. `Damage`, `Expiry`, and `WriteOff` must move off COGS; otherwise the shrinkage purpose is decorative and those losses continue to inflate COGS.
5. **F-7:** no code in this wave. Record a future-slice ticket: `GeneralLedgerService::createInventoryMovementEntry()` looks up idempotency by `(source_type, source_id)` without `company_id`, while the reversal lookup includes `company_id`.
6. **S-16:** the per-tenant duplicate-count query remains a parent-side hard pre-promotion gate. Wave 3D records it but does not execute or claim it; the vacuous M0 local probe is not evidence.
7. Wave 3D had not started before this ruling. Nothing in this ruling changes M5's dependency on accepted M4.
8. The M1-era `.github/workflows/ci.yml` change is grandfathered. Wave 3D must not touch `.github/workflows/**`; S-14 CI-dispatch verification remains a parent promotion obligation.

## Amended M4 exit condition

Commit the proposal and supporting non-code records, then set M4 and the wave to `blocked_owner`. The treasury owner must ratify one complete map before any T20/T20b implementation or M4 adversarial review begins. A proposal commit is not the M4 implementation commit and does not consume a review or fix round.
