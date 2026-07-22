# Gate 3-partial — Wave 3 Treasury Tasks 1–3 — Re-Review (Round 2)

> Controller-run 2026-07-20 (post Codex fix commits, tip `399cf4df6`). Reviewer: treasury-reviewer (Opus). Round 1: `.gates/gate-3a-verdict.md` (REJECT).

Worktree `/Users/houssamr/Projects/syneriva/apps/erp.multiloc`, tip `399cf4df6`. Diff scope per the request brief (`git diff origin/dev...HEAD` over Treasury/Fiscal/POS/tenant-migrations/AddRepositoryModal/features-treasury).

## Round-1 findings — disposition

**[IMPORTANT] FE test RED / feature untested — FIXED.**
`apps/web/src/components/organisms/AddRepositoryModal/AddRepositoryModal.test.tsx:26-27` now mocks `@/features/locations/hooks/useTransactionLocations` (returning `[{ id: 'loc-a', name: 'Store A', isActive: true }]`) — the exact hook the component consumes (`AddRepositoryModal.tsx:18,202`). Ran it: `pnpm vitest run …AddRepositoryModal.test.tsx` → **4 passed**, including "assigns a cash register to a selected location" which asserts `apiPost('/payment-repositories', objectContaining({ location_id: 'loc-a' }))` (test line 132). The `location_id` FE payload path is now genuinely covered.

**[IMPORTANT] PosBridge test was reflection-only — FIXED.**
`apps/api/tests/Feature/Fiscal/PosBridgeLocationAttributionTest.php` is fully rewritten to exercise real `apply()`:
- **Precedence (terminal > repository):** `test_receipt_bridge_apply_persists_terminal_location_over_repository_location_without_context` (lines 116-125) runs `PosCoreReceiptProjection::apply` then `TreasuryReceiptBridge::apply`, asserts persisted `Payment.location_id === terminal location` AND `!== repository location` (repo deliberately seeded with a different location, line 106).
- **Fallback-to-repository (no terminal):** `test_deposit_apply_uses_repository_location_fallback_when_terminal_is_absent` (127-134) uses a foreign-tenant terminal id so `resolveTerminalLocationId` returns null → asserts payment falls back to `repository.location_id`. Doubles as cross-tenant terminal-isolation proof.
- **Maturity-leg instrument propagation:** `test_account_payment_maturity_apply_propagates_terminal_location_to_instrument` (136-145) asserts both `Payment.location_id` and `PaymentInstrument.location_id === terminal location`.
- **Worker reality (rule 20):** `CompanyContext` is cleared at end of `setUp` (line 113) before any `apply()`.
Ran `phpunit …PaymentRepositoryLocationTest.php …PosBridgeLocationAttributionTest.php` → **OK (6 tests, 13 assertions)**.

**[MINOR] QueryException swallowed with no log — FIXED.**
`ResolvesTerminalLocation.php:19-27` now emits `Log::warning('Treasury terminal location lookup failed', ['fiscal_event_id', 'terminal_id', 'error'])`, matching the canonical `PosCoreReceiptProjection::resolveTerminal` pattern.

**[MINOR] Undocumented fallback asymmetry — FIXED.**
Explanatory comments added at all three sites: `TreasuryReceiptBridge.php:618-621` and `TreasuryAccountPaymentBridge.php:166-169` (device-authored, terminal-only, no fallback) vs `TreasuryDepositBridge.php:181-184` (server-authored, `?? $repository->location_id`).

## Fresh adversarial pass — findings

**[MINOR] TreasuryDepositBridge.php:145,167 — deposit repository-location fallback is applied to the payment row but NOT to a maturity instrument created in the same deposit.** The payment gets `$terminalLocationId ?? $repository->location_id` (line 184), but `handleMaturityLeg(...)` is passed the bare `$terminalLocationId` (lines 145/167 → instrument `location_id`). In the degraded no-terminal deposit path a cheque instrument lands `location_id = NULL` while its own payment carries the repository custody location — split attribution within one event. Impact is bounded: only triggers when the terminal lookup returns null (missing/cross-company terminal), and it produces an *absent* attribution, never a wrong one or wrong money; also untested (deposit test covers CASH only). Fix: pass `$terminalLocationId ?? $repository->location_id` to `handleMaturityLeg` in the deposit bridge so the instrument shares the payment's fallback grain.

**[MINOR / verification caveat] Tests are green on sqlite:memory, not PostgreSQL.** `apps/api/phpunit.xml` pins `DB_CONNECTION=sqlite` / `:memory:` / `TENANCY_DB_PER_TENANT=false`; there is no local `.env`/`.env.testing` and no shell override, so PostgreSQL execution could not be confirmed locally (this is the repo's standard local harness; PG runs in CI). The attribution logic is driver-agnostic and the migration column types (`uuid`, nullable FK, index) are portable, and the tenant migration demonstrably loaded (the `payments.location_id` assertions passed), so this does not undermine the contract — but the request's "confirm they run on PostgreSQL" cannot be attested from this environment. Recommend the CI PG run be the merge gate. Note: the sqlite run skips the `pg_advisory_xact_lock` branches (pre-existing, not introduced here).

## What was re-confirmed sound
- **Money precision (rule 19):** the entire diff adds a `location_id` UUID dimension only — no float cast, no `parseFloat`/`Number()` on money (FE sends strings), no bcmath/scale-resolver drift. Clean.
- **Terminal resolution:** `resolveTerminalLocationId` runs once per `apply()`, outside the txn, deterministic — idempotency/freeze reasoning intact; existing `$existing` guards prevent re-stamping on retry. `InstrumentLifecycleService::receive` writes `location_id` once (line 94).
- **Tenant/company scoping:** `PaymentRepositoryController` store/update validate `location_id` with `ScopedExists::company('locations', $companyId)` (correct — `locations` has `company_id`, no `tenant_id`); `location_id` is persisted in `create` (line 105) and both update branches (`$lockedAttributes` derives from `$validated`). Sibling-company rejection covered by `PaymentRepositoryLocationTest`.
- **Module boundary (rule 6):** `ResolvesTerminalLocation` reads `pos_terminals` via `DB::table(...)`, not the POS `Terminal` Eloquent model.
- **Projection-vs-device authority:** `location_id` is a downstream projection attribution derived from the terminal at projection time — no re-authoring of device-signed facts.
- **Migration self-guarding:** `2026_07_16_110000_add_location_id_to_payments_and_instruments.php` — `Schema::hasColumn` guards, nullable indexed FK, `nullOnDelete()`, reversible. The companion authz migrations (`…100000_backfill_user_company_memberships`, `…100100_register_manage_location_access_permission`) are idempotent, never-fail, and log ambiguity — safe under push=auto-deploy.

## Assessment
Both round-1 IMPORTANT defects are genuinely closed with real, green tests (verified by running them), both MINOR items fixed, and the fresh pass surfaced only two MINOR items (a degraded-path deposit-instrument attribution asymmetry, and a PG-vs-sqlite verification caveat) — no Critical or Important findings. The partial Wave 3 contract is complete.

**What to fix before/after merge (non-blocking):** pass `$terminalLocationId ?? $repository->location_id` to `handleMaturityLeg` in `TreasuryDepositBridge` so a no-terminal cheque deposit's instrument shares the payment's custody-location fallback; and let the CI PostgreSQL run be the merge gate.

VERDICT: APPROVE
