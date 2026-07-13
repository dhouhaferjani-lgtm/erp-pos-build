# TICKET — Instruments adopt BankPicker + real FK on payment_instruments.bank_id

> Phase-② interlock unlock, created at bank-directory merge (2026-07-12). Small, self-contained; dispatch as a mini-brief when convenient.

## Context

Treasury Phase ② shipped `payment_instruments.bank_id` as a **plain uuid** (no FK, no picker) because the bank directory didn't exist yet. The directory is now merged (`feat/bank-reference-verification`, review `docs/superpowers/audits/2026-07-12-bank-directory-final-review.md`): `banks` table per tenant, `GET /api/v1/banks`, `<BankPicker>` molecule (`apps/web/src/components/molecules/pickers/BankPicker.tsx`), and the `PaymentRepository.bank_id` wiring pattern to copy.

## Scope

1. **Migration** (tenant, guarded, re-runnable-safe per the `2026_07_12_11xxxx` precedent): add FK `payment_instruments.bank_id → banks.id` `nullOnDelete`. Existing rows hold free-uuid values that may not match any bank — decide: null-out orphans in the migration (log count) or leave column values and add FK as NOT VALID-equivalent (Laravel can't; so null orphans first, then constrain).
2. **FE (SCOPE RULING 2026-07-13 — verified against code):** the ONLY instrument-creating surface is `PaymentForm.tsx` (bank as 3 free-text inputs `:966-975`; payload `:654-661` does NOT send `bank_id` today). Adopt `<BankPicker>` there (mirror `AddRepositoryModal` incl. auto-derive/clear IBAN pattern) and ADD `bank_id` to the nested `instrument:{}` payload (`PaymentController.php:333` already accepts it). **Do NOT build an edit-details UI** — `InstrumentDetailPage` is lifecycle-only by design; instrument edit semantics (which lifecycle states permit bank changes) is an unmade product decision, out of scope. Noted as a possible future ticket.
3. **Backend validation hardening (broadened from "update only"):** ALL THREE `bank_id` write paths currently accept any well-formed UUID with no banks check — `PaymentInstrumentController::store` (`:151`), `::update` (`:219`), and `PaymentController` nested `instrument.bank_id` (`:333`). Add the exists-in-`banks` rule on all three, mirroring the `partner_id` `ScopedExists` pattern (`PaymentInstrumentController.php:224`).
4. **Warn-but-allow** stays: checksum failures never block instrument save.
5. Tests: migration orphan handling, picker integration test (incl. `bank_id` now present in the `/payments` payload), FK behavior, and 422 on nonexistent `bank_id` for each of the three write paths. POS verified FK-safe: `apps/pos` never writes `payment_instruments.bank_id` (fiscal `instrument_type/serial` is a different concept).

## Constraints

- PaymentInstrument is fiscal-spine-adjacent: do NOT touch lifecycle/GL/movement logic — this is a reference-data wiring change only.
- Review tier: Opus lanes; escalate to Fable only if instrument money paths get touched (they must not).
- Deploy note: FK migration must run AFTER the banks backfill on existing tenants (`docs/handoff/bank-directory-deploy-checklist.md` §2) — otherwise every existing bank_id is an orphan.
