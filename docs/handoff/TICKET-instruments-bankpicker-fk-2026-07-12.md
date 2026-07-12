# TICKET — Instruments adopt BankPicker + real FK on payment_instruments.bank_id

> Phase-② interlock unlock, created at bank-directory merge (2026-07-12). Small, self-contained; dispatch as a mini-brief when convenient.

## Context

Treasury Phase ② shipped `payment_instruments.bank_id` as a **plain uuid** (no FK, no picker) because the bank directory didn't exist yet. The directory is now merged (`feat/bank-reference-verification`, review `docs/superpowers/audits/2026-07-12-bank-directory-final-review.md`): `banks` table per tenant, `GET /api/v1/banks`, `<BankPicker>` molecule (`apps/web/src/components/molecules/pickers/BankPicker.tsx`), and the `PaymentRepository.bank_id` wiring pattern to copy.

## Scope

1. **Migration** (tenant, guarded, re-runnable-safe per the `2026_07_12_11xxxx` precedent): add FK `payment_instruments.bank_id → banks.id` `nullOnDelete`. Existing rows hold free-uuid values that may not match any bank — decide: null-out orphans in the migration (log count) or leave column values and add FK as NOT VALID-equivalent (Laravel can't; so null orphans first, then constrain).
2. **FE**: instrument create/edit forms adopt `<BankPicker>` (mirror `AddRepositoryModal`'s integration incl. auto-derive/clear IBAN pattern if instrument forms capture RIB/IBAN).
3. **Warn-but-allow** stays: checksum failures never block instrument save.
4. Tests: migration orphan handling, picker integration test, FK behavior.

## Constraints

- PaymentInstrument is fiscal-spine-adjacent: do NOT touch lifecycle/GL/movement logic — this is a reference-data wiring change only.
- Review tier: Opus lanes; escalate to Fable only if instrument money paths get touched (they must not).
- Deploy note: FK migration must run AFTER the banks backfill on existing tenants (`docs/handoff/bank-directory-deploy-checklist.md` §2) — otherwise every existing bank_id is an orphan.
