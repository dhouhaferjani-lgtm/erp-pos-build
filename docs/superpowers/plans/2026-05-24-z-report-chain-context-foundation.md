# Z-Report Chain Context Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add first-class fiscal `chain_context` support so operational, Z-session, and training chains can keep independent sequence streams for the same terminal.

**Architecture:** Preserve current behavior by defaulting existing authoring to `operational`, then add an explicit context argument and immutable column on local/server fiscal events. The local engine selects the appropriate terminal-state head by context; server ingest verifies and stores context as part of the sealed wire contract.

**Tech Stack:** TypeScript POS fiscal engine and SQLite migrations/tests; Laravel fiscal ingest DTO, migration, Eloquent model, and feature tests.

---

### Task 1: POS Local Chain Context Schema

**Files:**
- Modify: `apps/pos/src/lib/db/migrations.ts`
- Modify: `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts`

- [x] **Step 1: Write failing POS schema test**

Add a test in `FiscalEventEngine.test.ts` that appends one default operational receipt and one `z_session` event with sequence `1`, then asserts two rows can share `(tenant_id, terminal_id, sequence_number)` only when `chain_context` differs.

- [x] **Step 2: Run failing test**

Run: `pnpm --filter @autoerp/pos exec vitest run src/lib/fiscal/__tests__/FiscalEventEngine.test.ts --runInBand`

Expected before implementation: failure because `chain_context` does not exist and the unique index is still `(tenant_id, terminal_id, sequence_number)`.

- [x] **Step 3: Add schema support**

In migration v37, add `chain_context TEXT NOT NULL DEFAULT 'operational'` to `fiscal_events`, change `idx_fiscal_events_chain_unique` to `(tenant_id, company_id, terminal_id, chain_context, sequence_number)`, and include `chain_context` in the append-only trigger column list. Add a new migration after v44 that backfills existing installations by adding the column, dropping the old chain unique index, and creating the new unique index.

- [x] **Step 4: Run test**

Run the same Vitest command.

Expected after implementation: the new schema assertion passes.

### Task 2: POS Engine Context-Aware Append

**Files:**
- Modify: `apps/pos/src/lib/fiscal/FiscalEventEngine.ts`
- Modify: `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts`

- [x] **Step 1: Add request/result contract tests**

Assert default appends return `chain_context: 'operational'` and canonical bytes include `chain_context`. Assert `z_session` appends advance `z_chain_sequence` without changing `fiscal_event_sequence`.

- [x] **Step 2: Implement context type and head selection**

Add `FiscalChainContext`, default request context to `operational`, persist and return `chain_context`, and choose terminal-state columns by context. Use existing operational columns for `operational`; use `z_last_hash`/`z_hash_sequence` as the initial bridge for `z_session`; add training columns through the schema migration for `training_operational` and `training_z_session`.

- [x] **Step 3: Run focused POS test**

Run: `pnpm --filter @autoerp/pos exec vitest run src/lib/fiscal/__tests__/FiscalEventEngine.test.ts --runInBand`

Expected: all FiscalEventEngine tests pass.

### Task 3: Server DTO, Schema, And Ingest

**Files:**
- Modify: `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalEventEnvelope.php`
- Modify: `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php`
- Modify: `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEvent.php`
- Create: `apps/api/database/migrations/*_add_chain_context_to_fiscal_events.php`
- Modify tests under `apps/api/tests/Feature/Fiscal`

- [x] **Step 1: Write API ingest tests**

Add tests proving two envelopes for the same terminal can both use sequence `1` when their contexts differ, and that missing or invalid `chain_context` is rejected as malformed.

- [x] **Step 2: Add DTO and model field**

Add `chainContext` to `FiscalEventEnvelope`, require it in `fromArray()`, validate it against the four allowed context strings, add it to the model fillable/casts, and store it in `fiscal_events`.

- [x] **Step 3: Update ingest sequencing**

Scope prior-row lookup, `ON CONFLICT`, and conflict handling by `chain_context`. Add a PostgreSQL migration replacing `fiscal_events_tenant_terminal_sequence_unique` with `(tenant_id, company_id, terminal_id, chain_context, sequence_number)`.

- [x] **Step 4: Run focused API tests**

Run: `cd apps/api && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan test tests/Feature/Fiscal/OutboxIngestorTest.php tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php`

Expected: chain-context tests pass and existing ingest coverage remains green.

### Task 4: Verification And Chokepoints

**Files:**
- Modify: `apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php`
- Modify: `apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php`
- Add or modify chokepoint tests under `apps/api/tests/Feature/Fiscal`

- [x] **Step 1: Add verifier tests**

Assert the command reports independent status per chain context and does not collapse production/training chains.

- [x] **Step 2: Implement per-context verification**

Group event walking by `(tenant_id, company_id, terminal_id, chain_context)`, include context in output, and preserve existing operational behavior as the default.

- [x] **Step 3: Add infrastructure chokepoint coverage**

Add a test that fails if `OutboxIngestor`, `FiscalEventEnvelope`, schema indexes, or verifier code omit `chain_context`.

### Task 5: Commit And Move To Z-Session Authoring

**Files:**
- Commit all files from Tasks 1-4.

- [x] **Step 1: Run focused verification**

Run POS and API focused commands from Tasks 2 and 3.

- [ ] **Step 2: Commit**

Run:

```bash
git add docs/superpowers/plans/2026-05-24-z-report-chain-context-foundation.md apps/pos apps/api
git commit -m "Phase 4.2.2: Add fiscal chain context foundation"
```

- [ ] **Step 3: Continue with the next plan**

Write the next plan for device-authored `SESSION_OPEN`, cash movement, `SESSION_CLOSE`, `X_REPORT`, and `Z_REPORT` payloads/projections once the shared chain context foundation is green.
