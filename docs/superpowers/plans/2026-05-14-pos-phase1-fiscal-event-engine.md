# POS Phase 1 — Fiscal Event Engine + Receipt-Chain Clean Rebuild — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish the one device-authority fiscal pattern — device authors/seals locally, server verifies-verbatim and mirrors — and rebuild the existing receipt chain clean on it as a consumer of the engine.

**Architecture:** A new bounded `Fiscal` module owns the server-side verify-only mirror (`OutboxIngestor`, projection registry, strict parser, integrity providers, the `fiscal_events` ledger). The Tauri device owns authoring via `FiscalEventEngine.append()`. Business projection out of the ledger is a per-module, retryable concern: a POS-core projector (always runs) plus a Treasury bridge (gated by a `ModuleActivationResolver`). `SALE_RECEIPT` becomes a first-class fiscal event; the legacy server-recompute receipt chain and `/pos/receipts/sync` are discarded.

**Tech Stack:** Laravel 12 / PHP 8.2 strict types (hexagonal modules), PostgreSQL 16 (immutability triggers), Redis 7 + Horizon (projection queue), Tauri 2 + React 19 + TypeScript strict, SQLite (device store), Vitest (device tests), PHPUnit `RefreshDatabase` (server tests).

**Source spec:** `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md` (APPROVED — Codex re-review 2026-05-14, 0 findings).

**Grounding:** SoT v3 (`research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md`, LOCKED), codebase reality audit (`research/2026-05-14-pos-fiscal-codebase-reality.md`), roadmap v2.

**Revision history:**
- **v4 (current)** — v3 → Codex re-review BLOCK (1 P1 / 1 P3 — `reviews/2026-05-14-pos-phase1-fiscal-event-engine-plan-v3-codex-review.md`). **P1:** Task 19's green test asserted `>0` `fiscal_event_projections` rows, but the production registry's tagged-projector set is empty until Tasks 21/22 — Task 19 couldn't pass task-by-task. v4 fixes Task 19 to use a **test-local `FakeSaleReceiptProjector`** tagged in `setUp()` (asserts exactly 1 row) plus a companion test for the genuinely-empty-registry case (event stores, 0 rows); the real POS/Treasury tags stay in Tasks 21/22. **P3:** Task 26's provider note wrongly listed Task 31 as "already wired" — Task 31 runs after 26; corrected.
- **v3** — v2 → Codex BLOCK (1 P1 — `reviews/2026-05-14-pos-phase1-fiscal-event-engine-plan-v2-codex-review.md`): provider wiring (route loading, interface bindings, command registration) was deferred to Task 26 but Tasks 17/20/24 needed it earlier — the same defect class as v1's Task-1 finding. v3 resolves it with the **incremental provider-wiring convention** (see Conventions): every task that introduces a binding/route/command wires it into `FiscalServiceProvider` (or the owning module's provider, for projectors) in that same task. Tasks 6/17/18/20/21/22/24/26/31 annotated; Task 26 no longer "the provider task". The 8 v1 findings remain resolved (v2).
- **v2** — v1 → Codex BLOCK (1 BLOCKER / 3 P1 / 3 P2 / 1 P3 — `reviews/2026-05-14-pos-phase1-fiscal-event-engine-plan-codex-review.md`) → v2 resolved all 8:
- **BLOCKER** — Task 8's immutability trigger forbade the `failed → parsed` transition Task 24's parse-failure resume requires. Task 8 now adds the named `failed → parsed` transition (gated on the parse-failure-resolution conditions) + builds its test there.
- **P1** — `PosCoreReceiptProjection`'s idempotency guard used a `pos_receipts.fiscal_event_id` column no task created. Task 11 now adds `pos_receipts.fiscal_event_id UUID NULL UNIQUE FK`; Tasks 11 + 21 test the linkage + guard.
- **P1** — the ingestion route dropped `EnforceTokenTenantClaim`. Task 20 now uses the full POS middleware tuple + a mismatched-tenant-claim test.
- **P1** — Task 1's command couldn't pass before provider registration. Task 1 now ships a minimal `FiscalServiceProvider` registered in `bootstrap/providers.php`; later tasks grow the provider incrementally when they introduce bindings, routes, or commands.
- **P2** — sequence-gap detection only checked hash linkage. Task 19's `linkage_ok` now checks numeric continuity + hash linkage; adds the numeric-gap-with-valid-hash test.
- **P2** — Task 12's Treasury `Payment` model path was wrong (`Domain/Models/Payment.php`). Corrected to `Domain/Payment.php` everywhere + a writer-import verification step.
- **P2** — Task 27's `onlineCheckout` deletion test was tautological. Replaced with a source-level guard that fails today.
- **P3** — Task 30's chokepoint grep was brittle. Now a manifest-reconciliation gate with explicit receiver-type resolution + an `unrelated`-method allowlist.

---

## Conventions for every task

- **TDD:** write the failing test, run it red, implement minimally, run it green, commit. Backend = PHPUnit with `RefreshDatabase` + real Eloquent models + `RolesAndPermissionsSeeder` (never faked responses). Device = Vitest (mocking hooks/providers is acceptable; do not mock data shapes).
- **Strict typing:** no `mixed` (PHP), no `any` (TS). JSONB columns get a PHP DTO.
- **Constructor injection only** — `private readonly`, never `app()`.
- **Cross-module communication only via `app/Shared/Contracts/`** interfaces, Events, or a module's public Service class.
- **Pre-flight before each commit:** `cd apps/api && ./vendor/bin/pint && ./vendor/bin/phpstan` for PHP changes; `cd apps/web && pnpm typecheck` / `cd apps/pos && pnpm typecheck` for TS changes. Full `./scripts/preflight.sh` before the task is considered done.
- **Module token discipline:** every reference to the Treasury module activation token is the canonical PascalCase string `'Treasury'` — `CompanyConfig::hasModule()` strict-compares (`in_array(..., true)`), so a lowercase token silently disables the bridge.
- **Provider wiring is incremental — wire on introduction, never defer.** A file under `app/Modules/Fiscal/...` is **not** auto-discovered: a container binding, a route file, or an Artisan command only takes effect once `FiscalServiceProvider` registers it. Therefore **any task that introduces a binding, a route file, or a command also edits `FiscalServiceProvider` in that same task** (and `git add`s it in that task's commit) — so the task's own red/green tests pass through the container. `FiscalServiceProvider` is created minimal in Task 1 and grows task-by-task; no task defers another task's wiring. The wiring each task owns: Task 1 → `fiscal:preflight-gate` command in `FiscalServiceProvider`; Task 6 → `FiscalIntegrityProvider → HashChainIntegrityProvider` binding in `FiscalServiceProvider`; Task 17 → `ModuleActivationResolver → DefaultModuleActivationResolver` binding in `FiscalServiceProvider`; Task 18 → `FiscalEventProjectionRegistry` singleton in `FiscalServiceProvider` (over `app->tagged(FiscalEventProjector::class)` — an empty tag set is fine until projectors land); Task 20 → `loadRoutesFrom` the fiscal `routes.php` in `FiscalServiceProvider`; Task 21 → tag `PosCoreReceiptProjection` as `FiscalEventProjector` **in `POSServiceProvider`** (the POS module owns its projector — module boundary discipline); Task 22 → tag `TreasuryReceiptBridge` as `FiscalEventProjector` **in `TreasuryServiceProvider`** (the Treasury module owns its bridge); Task 24 → `fiscal:enqueue-resolved-event-projections` command in `FiscalServiceProvider`; Task 31 → `fiscal:verify-event-chain` command in `FiscalServiceProvider`. Task 26 wires **only** what Task 26 introduces (the snapshot service path) — it does not register routes or commands belonging to earlier/later tasks. The registry consumes `app->tagged(FiscalEventProjector::class)` regardless of which module's provider did the tagging — that is the composition seam.
- **Frozen names used across tasks** (defined here so later tasks match earlier ones exactly):
  - Server module namespace: `App\Modules\Fiscal\…`
  - Shared contracts: `App\Shared\Contracts\Fiscal\{FiscalEventProjector, ModuleActivationResolver, FiscalIntegrityProvider, SignatureProviderInterface}`
  - Enum: `App\Modules\Fiscal\Domain\Enums\FiscalEventType`
  - Server services: `OutboxIngestor`, `FiscalEventProjectionRegistry`, `StrictCanonicalParser`, `HashChainIntegrityProvider`, `DefaultModuleActivationResolver`
  - Projectors: `PosCoreReceiptProjection` (in POS module), `TreasuryReceiptBridge` (in Treasury module), both named `pos_core_receipt` / `treasury_receipt_bridge`
  - Device (Tauri, `apps/pos/src/lib/fiscal/`): `FiscalEventEngine`, `FiscalEventCanonicalEncoder`, `HashChainIntegrityProvider` (TS), `FiscalEventPayloadRegistry`
  - Ingestion endpoint: `POST /api/v1/pos/sync/fiscal-events`
  - Commands: `fiscal:verify-event-chain`, `fiscal:enqueue-resolved-event-projections`
  - Permissions: `fiscal.events.verify_chain`, `fiscal.events.resolve_quarantine`

---

## File Structure

**New server module — `apps/api/app/Modules/Fiscal/`:**
- `Domain/Enums/FiscalEventType.php` — the reserved-value enum + per-value `isImplementedInPhase1()`.
- `Domain/Enums/IntegrityExceptionClass.php` — `canonical_hash_mismatch | canonical_parse_failure | time_anomaly | sequence_gap | sequence_conflict`.
- `Domain/Enums/PayloadParseStatus.php`, `Domain/Enums/IntegrityStatus.php`, `Domain/Enums/ProjectionStatus.php`, `Domain/Enums/SignatureStatus.php`.
- `Domain/Models/FiscalEvent.php`, `Domain/Models/FiscalEventProjectionRow.php`, `Domain/Models/FiscalEventQuarantine.php` — Eloquent models.
- `Domain/DTOs/` — one payload DTO per implemented event type (`SaleReceiptPayload`, `ChainBreakDetectedPayload`, `ChainRestartPayload`, `TerminalRegistrySnapshotPayload`) plus the reserved `CompanyDayClosureManifestPayload` schema.
- `Application/Services/OutboxIngestor.php`, `Application/Services/StrictCanonicalParser.php`, `Application/Services/FiscalEventProjectionRegistry.php`, `Application/Services/HashChainIntegrityProvider.php`, `Application/Services/DefaultModuleActivationResolver.php`, `Application/Services/FiscalEventPayloadRegistry.php`.
- `Application/Jobs/ApplyFiscalEventProjectionJob.php`.
- `Application/DTOs/FiscalEventEnvelope.php`, `Application/DTOs/IngestionResult.php`.
- `Presentation/Controllers/FiscalEventIngestionController.php`, `Presentation/Requests/IngestFiscalEventsRequest.php`, `routes.php`.
- `Infrastructure/Commands/VerifyEventChainCommand.php`, `Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php`.
- `Providers/FiscalServiceProvider.php`.

**Shared contracts — `apps/api/app/Shared/Contracts/Fiscal/`:** the four interfaces above (so POS / Treasury implement projectors without importing Fiscal internals).

**POS module additions:** `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`.

**Treasury module additions:** `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php`; `apps/api/app/Modules/Treasury/Domain/Enums/PaymentOrigin.php`.

**Server migrations — `apps/api/database/migrations/`:** the seven migrations of §16 (preflight is a task, not a migration).

**Device — `apps/pos/src/lib/fiscal/`:** `FiscalEventEngine.ts`, `FiscalEventCanonicalEncoder.ts`, `HashChainIntegrityProvider.ts`, `FiscalEventPayloadRegistry.ts`, `types.ts`, `__tests__/`.

**Device reworked:** `apps/pos/src/lib/offline/receiptService.ts`, `apps/pos/src/lib/offline/offlineCheckoutService.ts`, `apps/pos/src/api/receiptApi.ts`, `apps/pos/src/lib/sync/syncService.ts`, `apps/pos/src/lib/fetchWithTimeout.ts`, device SQLite migration set.

**Golden vectors (shared fixture):** `apps/api/tests/Fixtures/Fiscal/canonical-golden-vectors.json` + device copy or symlinked path consumed by both test suites.

**CI:** `apps/api/scripts/check-saleReceipt-chokepoints.sh` (the §14.3 grep gate), wired into `scripts/preflight.sh` and CI.

---

## Task 1: Preflight verification gate

**This task blocks every schema-destructive task (Tasks 7–13, 28–30). It must complete and be signed off before they begin.** It produces a written artifact, not code.

**Files:**
- Create: `apps/api/app/Modules/Fiscal/Infrastructure/Commands/PreflightFiscalGateCommand.php`
- Create: `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php` (minimal — only the preflight command bound here; later tasks incrementally add their own bindings, route loading, and commands)
- Modify: `apps/api/bootstrap/providers.php` (register `FiscalServiceProvider` — module providers are listed there, e.g. `ComplianceServiceProvider`, `POSServiceProvider`)
- Create: `apps/api/docs/sessions/2026-05-14-fiscal-preflight-signoff.md` (the sign-off artifact — moved to a permanent location on owner sign-off)
- Test: `apps/api/tests/Feature/Fiscal/PreflightFiscalGateCommandTest.php`

> **Why the provider starts in Task 1:** a command under `app/Modules/Fiscal/...` is **not** auto-discovered — module commands are registered in a module `ServiceProvider` that is listed in `apps/api/bootstrap/providers.php` (the pattern `POSServiceProvider` uses). Without the provider, `php artisan fiscal:preflight-gate` does not exist and Task 1 cannot pass. Task 1 ships a **minimal** `FiscalServiceProvider` (binds only the preflight command); later tasks edit the **same** provider when they introduce each new binding, route file, or command.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Fiscal;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class PreflightFiscalGateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_passes_when_no_live_fiscal_data_exists(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->artisan('fiscal:preflight-gate')
            ->expectsOutputToContain('SERVER SURFACE: clear')
            ->expectsOutputToContain('DEVICE SURFACE: requires manual inventory')
            ->expectsOutputToContain('WEB-POS SURFACE: live receipt-creation path detected')
            ->assertExitCode(0);
    }

    public function test_gate_fails_when_pos_receipts_present(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        \DB::table('pos_receipts')->insert($this->minimalReceiptRow());
        $this->artisan('fiscal:preflight-gate')->assertExitCode(1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/PreflightFiscalGateCommandTest.php`
Expected: FAIL — command `fiscal:preflight-gate` not registered.

- [ ] **Step 3: Implement the command + the minimal `FiscalServiceProvider`**

`FiscalServiceProvider` (minimal): `boot()` registers `PreflightFiscalGateCommand` via `$this->commands([...])` inside `runningInConsole()`, matching `POSServiceProvider::boot()`. Add `use App\Modules\Fiscal\Providers\FiscalServiceProvider;` to `apps/api/bootstrap/providers.php` and add `FiscalServiceProvider::class` to the returned provider array.

The command queries every staging/production tenant PostgreSQL database for non-empty `pos_receipts`, `pos_z_reports`, `pos_terminals` with non-default chain state (`last_hash IS NOT NULL OR current_sequence > 0`), and receipt-print records. It reports the **device surface** as "requires manual inventory" (it cannot reach Tauri SQLite stores — the operator must inventory each deployed terminal or record "no deployed terminals exist"). It reports the **web-POS surface** by checking whether the `POST /pos/receipts` route is registered and reachable. Exit `0` only if the server surface is clear; exit `1` if any non-disposable fiscal data is found.

```php
public function handle(): int
{
    $serverClear = $this->serverSurfaceClear();   // queries the 4 sources above across tenants
    $this->line($serverClear ? 'SERVER SURFACE: clear' : 'SERVER SURFACE: NON-EMPTY — see report');
    $this->line('DEVICE SURFACE: requires manual inventory — record per-terminal SQLite findings in the sign-off');
    $this->line($this->webPosPathLive()
        ? 'WEB-POS SURFACE: live receipt-creation path detected — disposition per §14.2'
        : 'WEB-POS SURFACE: no live receipt-creation path');
    return $serverClear ? self::SUCCESS : self::FAILURE;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/PreflightFiscalGateCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Produce the sign-off artifact and obtain written owner sign-off**

Run `php artisan fiscal:preflight-gate` against each environment. Record in `docs/sessions/2026-05-14-fiscal-preflight-signoff.md`: (a) server-surface output per environment; (b) the device-side inventory of every deployed Tauri terminal's SQLite store (`offline_receipts`, `terminal_state`, `z_reports`, pending-sync rows) — **or the explicit operational fact "no deployed terminals exist"**; (c) the web-POS disposition decision. **STOP and get a written owner sign-off** covering all three surfaces before any later schema-destructive task. If non-disposable fiscal data is found: stop, define an archival/export path, re-scope.

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Fiscal/Infrastructure/Commands/PreflightFiscalGateCommand.php apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php apps/api/bootstrap/providers.php apps/api/tests/Feature/Fiscal/PreflightFiscalGateCommandTest.php apps/api/docs/sessions/2026-05-14-fiscal-preflight-signoff.md
git commit -m "feat(fiscal): preflight verification gate command + minimal FiscalServiceProvider"
```

---

## Task 2: `FiscalEventType` enum + the reserved-value contract

**Files:**
- Create: `apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php`
- Test: `apps/api/tests/Unit/Fiscal/FiscalEventTypeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Tests\Unit\Fiscal;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use PHPUnit\Framework\TestCase;

final class FiscalEventTypeTest extends TestCase
{
    public function test_phase1_implemented_types(): void
    {
        $this->assertTrue(FiscalEventType::SALE_RECEIPT->isImplementedInPhase1());
        $this->assertTrue(FiscalEventType::CHAIN_BREAK_DETECTED->isImplementedInPhase1());
        $this->assertTrue(FiscalEventType::CHAIN_RESTART->isImplementedInPhase1());
        $this->assertTrue(FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT->isImplementedInPhase1());
    }

    public function test_reserved_types_are_not_implemented(): void
    {
        $this->assertFalse(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST->isImplementedInPhase1());
        $this->assertFalse(FiscalEventType::ACCOUNT_PAYMENT->isImplementedInPhase1());
        $this->assertFalse(FiscalEventType::SALE_VOID->isImplementedInPhase1());
        $this->assertFalse(FiscalEventType::REFUND_RECEIPT->isImplementedInPhase1());
    }

    public function test_check_constraint_list_matches_appendix_a(): void
    {
        // 4 implemented + 24 reserved = 28 cases (Appendix A)
        $this->assertCount(28, FiscalEventType::cases());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventTypeTest.php`
Expected: FAIL — enum class not found.

- [ ] **Step 3: Implement the enum**

A backed string enum with every value from Appendix A. `isImplementedInPhase1()` returns `true` only for `SALE_RECEIPT`, `CHAIN_BREAK_DETECTED`, `CHAIN_RESTART`, `TERMINAL_REGISTRY_SNAPSHOT`. Add a `public static function checkConstraintList(): string` returning the comma-quoted SQL value list (consumed by the Task 7 migration's `CHECK (event_type IN (...))`).

```php
enum FiscalEventType: string
{
    case SALE_RECEIPT = 'SALE_RECEIPT';
    case CHAIN_BREAK_DETECTED = 'CHAIN_BREAK_DETECTED';
    case CHAIN_RESTART = 'CHAIN_RESTART';
    case TERMINAL_REGISTRY_SNAPSHOT = 'TERMINAL_REGISTRY_SNAPSHOT';
    case COMPANY_DAY_CLOSURE_MANIFEST = 'COMPANY_DAY_CLOSURE_MANIFEST';
    case ACCOUNT_PAYMENT = 'ACCOUNT_PAYMENT';
    // ... ACCOUNT_CHARGE, ACCOUNT_REFUND, ACCOUNT_PAYMENT_RECONCILED, ACCOUNT_CREDIT_ISSUE,
    //     ACCOUNT_CREDIT_USAGE, DEPOSIT_RECEIPT, IDENTITY_ALIAS_RECONCILED, SALE_VOID,
    //     SALE_CORRECTION, REFUND_RECEIPT, PARTIAL_REFUND, RETURN_WITHOUT_RECEIPT,
    //     OPENING_FLOAT, CASH_IN, CASH_OUT, SAFE_DROP, CASH_CORRECTION, SESSION_OPEN,
    //     SESSION_CLOSE, X_REPORT, Z_REPORT, REPRINT_COPY  (full Appendix A list)

    public function isImplementedInPhase1(): bool
    {
        return in_array($this, [
            self::SALE_RECEIPT, self::CHAIN_BREAK_DETECTED,
            self::CHAIN_RESTART, self::TERMINAL_REGISTRY_SNAPSHOT,
        ], true);
    }

    public static function checkConstraintList(): string
    {
        return collect(self::cases())
            ->map(fn (self $c) => "'".$c->value."'")
            ->implode(', ');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventTypeTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php apps/api/tests/Unit/Fiscal/FiscalEventTypeTest.php
git commit -m "feat(fiscal): FiscalEventType enum with Appendix A reserved values"
```

---

## Task 3: Supporting fiscal enums

**Files:**
- Create: `apps/api/app/Modules/Fiscal/Domain/Enums/IntegrityExceptionClass.php`, `IntegrityStatus.php`, `PayloadParseStatus.php`, `ProjectionStatus.php`, `SignatureStatus.php`
- Test: `apps/api/tests/Unit/Fiscal/FiscalEnumsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Tests\Unit\Fiscal;
use App\Modules\Fiscal\Domain\Enums\{IntegrityExceptionClass, IntegrityStatus, PayloadParseStatus, ProjectionStatus, SignatureStatus};
use PHPUnit\Framework\TestCase;

final class FiscalEnumsTest extends TestCase
{
    public function test_integrity_exception_classes(): void
    {
        $this->assertSame('canonical_hash_mismatch', IntegrityExceptionClass::CanonicalHashMismatch->value);
        $this->assertSame('sequence_conflict', IntegrityExceptionClass::SequenceConflict->value);
        // sequence_conflict is the only class whose storage target is the quarantine table
        $this->assertFalse(IntegrityExceptionClass::SequenceConflict->isAdmissibleToLedger());
        $this->assertTrue(IntegrityExceptionClass::CanonicalHashMismatch->isAdmissibleToLedger());
    }

    public function test_projection_status_values(): void
    {
        $this->assertSame(
            ['pending', 'running', 'applied', 'dead_lettered'],
            array_map(fn ($c) => $c->value, ProjectionStatus::cases()),
        );
    }

    public function test_payload_parse_status_and_integrity_status_and_signature_status(): void
    {
        $this->assertSame(['pending', 'parsed', 'failed'], array_map(fn ($c) => $c->value, PayloadParseStatus::cases()));
        $this->assertSame(['verified', 'quarantined'], array_map(fn ($c) => $c->value, IntegrityStatus::cases()));
        $this->assertSame(['not_required', 'pending', 'signed', 'failed'], array_map(fn ($c) => $c->value, SignatureStatus::cases()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEnumsTest.php`
Expected: FAIL — enum classes not found.

- [ ] **Step 3: Implement the five enums**

`IntegrityExceptionClass` carries the five classes from §8 plus `isAdmissibleToLedger(): bool` returning `false` only for `SequenceConflict` (the only class that cannot enter `fiscal_events` and goes to `fiscal_event_quarantine`). The other four are simple backed string enums matching §3.2 column defaults: `IntegrityStatus` (`verified|quarantined`), `PayloadParseStatus` (`pending|parsed|failed`), `ProjectionStatus` (`pending|running|applied|dead_lettered`), `SignatureStatus` (`not_required|pending|signed|failed`).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEnumsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Fiscal/Domain/Enums/ apps/api/tests/Unit/Fiscal/FiscalEnumsTest.php
git commit -m "feat(fiscal): integrity/parse/projection/signature status enums"
```

---

## Task 4: Canonical serialization golden vectors + PHP-side golden test

The canonical contract (§4): sorted-key JSON (RFC 8785 / JCS), integers only, money as `CurrencyScale::bcformat()` decimal strings, UTC ISO-8601 second precision, UTF-8 NFC with U+2028/U+2029 stripped at the producer. The **device serializes once; the server never re-serializes** — the PHP side only asserts `sha256(expected_canonical_string) == expected_sha256_hex`.

**Files:**
- Create: `apps/api/tests/Fixtures/Fiscal/canonical-golden-vectors.json`
- Create: `apps/api/tests/Unit/Fiscal/CanonicalGoldenVectorPhpTest.php`

- [ ] **Step 1: Write the fixture file**

A JSON array of vectors, each `{ "name", "payload_dto_input", "expected_canonical_string", "expected_sha256_hex" }`. The matrix **must** include: TND 3-decimal currency, a 2-decimal currency, a 0-decimal currency, a negative amount, empty arrays / null optionals, multibyte/NFC strings, U+2028/U+2029 normalization, non-ASCII key ordering. Generate `expected_canonical_string` by hand-applying the §4 contract for each input, and `expected_sha256_hex` as `sha256` of the UTF-8 bytes of that string.

```json
[
  {
    "name": "tnd_3dp_simple_sale",
    "payload_dto_input": { "tenant_id": "…", "company_id": "…", "terminal_id": "…",
      "operator_id": "…", "event_type": "SALE_RECEIPT", "event_version": 1,
      "signature_version": "hash-chain-integrity-v1", "sequence_number": 1,
      "event_time_device": "2026-05-14T10:00:00Z", "business_date": "2026-05-14",
      "previous_hash": "<genesis seed 64 hex>", "reference_document_id": "…",
      "reference_event_id": null,
      "payload": { "currency": "TND", "total": "12.345", "lines": [] } },
    "expected_canonical_string": "{\"business_date\":\"2026-05-14\",\"company_id\":\"…\",…}",
    "expected_sha256_hex": "<64 hex>"
  }
]
```

- [ ] **Step 2: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Tests\Unit\Fiscal;
use PHPUnit\Framework\TestCase;

final class CanonicalGoldenVectorPhpTest extends TestCase
{
    /** The PHP side NEVER serializes — it only confirms the hash of the device's canonical string. */
    public function test_every_golden_vector_hash_matches(): void
    {
        $vectors = json_decode(
            file_get_contents(__DIR__.'/../../Fixtures/Fiscal/canonical-golden-vectors.json'),
            true, 512, JSON_THROW_ON_ERROR,
        );
        $this->assertNotEmpty($vectors);
        foreach ($vectors as $v) {
            $this->assertSame(
                $v['expected_sha256_hex'],
                hash('sha256', $v['expected_canonical_string']),
                "Golden vector '{$v['name']}' hash mismatch",
            );
        }
    }

    public function test_matrix_coverage(): void
    {
        $vectors = json_decode(file_get_contents(__DIR__.'/../../Fixtures/Fiscal/canonical-golden-vectors.json'), true);
        $names = array_column($vectors, 'name');
        foreach (['tnd_3dp', '2dp', '0dp', 'negative_amount', 'empty_arrays_null_optionals',
                  'multibyte_nfc', 'line_separator_normalization', 'non_ascii_key_order'] as $required) {
            $this->assertTrue(
                (bool) array_filter($names, fn ($n) => str_contains($n, $required)),
                "Golden-vector matrix missing case: {$required}",
            );
        }
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/CanonicalGoldenVectorPhpTest.php`
Expected: FAIL initially if any hand-computed `expected_sha256_hex` is wrong — fix the fixture until green (this is the point: the fixture is the contract).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/CanonicalGoldenVectorPhpTest.php`
Expected: PASS — all vectors hash-consistent, matrix complete.

- [ ] **Step 5: Commit**

```bash
git add apps/api/tests/Fixtures/Fiscal/canonical-golden-vectors.json apps/api/tests/Unit/Fiscal/CanonicalGoldenVectorPhpTest.php
git commit -m "feat(fiscal): canonical serialization golden vectors + PHP hash-only golden test"
```

---

## Task 5: `FiscalEventCanonicalEncoder` (device, TypeScript)

The device-side canonical encoder. Reuses the structural logic of the existing `CanonicalJsonEncoder` pattern (`apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/CanonicalJsonEncoder.php` — V3 receipt pattern); applies §4 string normalization. Not mirrored in PHP.

**Files:**
- Create: `apps/pos/src/lib/fiscal/FiscalEventCanonicalEncoder.ts`
- Create: `apps/pos/src/lib/fiscal/types.ts`
- Test: `apps/pos/src/lib/fiscal/__tests__/FiscalEventCanonicalEncoder.test.ts`

- [ ] **Step 1: Write the failing test (golden-vector reproduction + matrix)**

```ts
import { describe, it, expect } from 'vitest';
import { FiscalEventCanonicalEncoder } from '../FiscalEventCanonicalEncoder';
import goldenVectors from '../../../../../api/tests/Fixtures/Fiscal/canonical-golden-vectors.json';

describe('FiscalEventCanonicalEncoder', () => {
  const encoder = new FiscalEventCanonicalEncoder();

  it.each(goldenVectors)('reproduces canonical string + hash for $name', (vector) => {
    const canonical = encoder.encode(vector.payload_dto_input);
    expect(canonical).toBe(vector.expected_canonical_string);
    expect(encoder.sha256Hex(canonical)).toBe(vector.expected_sha256_hex);
  });

  it('sorts object keys and arrays, rejects floats, formats money as decimal strings', () => {
    const out = encoder.encode({
      b: 2, a: 1,
      payload: { lines: [{ z: 1 }, { a: 1 }], total: '0.000' },
    } as never);
    expect(out.indexOf('"a"')).toBeLessThan(out.indexOf('"b"'));
  });

  it('strips U+2028 / U+2029 and applies NFC normalization at the producer', () => {
    const out = encoder.encode({ note: 'a b c' } as never);
    expect(out).not.toContain(' ');
    expect(out).not.toContain(' ');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/FiscalEventCanonicalEncoder.test.ts`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement the encoder**

`encode(obj)` produces sorted-key JCS JSON: recursively sort object keys; sort arrays; serialize integers without exponent; reject `number` values that are non-integer floats (throw `CanonicalEncodingError` — money must arrive pre-formatted as `CurrencyScale`-style decimal strings); normalize all strings to NFC and strip U+2028/U+2029. `sha256Hex(str)` returns lowercase hex SHA-256 of the UTF-8 bytes (use the Web Crypto API via Tauri, or a vetted sync SHA-256 — match whatever the existing V3 receipt code uses). `types.ts` defines `FiscalEventCanonicalInput` and the shared `FiscalEvent` row shape.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/FiscalEventCanonicalEncoder.test.ts`
Expected: PASS — every golden vector reproduced, hashes match the PHP side.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/lib/fiscal/FiscalEventCanonicalEncoder.ts apps/pos/src/lib/fiscal/types.ts apps/pos/src/lib/fiscal/__tests__/FiscalEventCanonicalEncoder.test.ts
git commit -m "feat(pos): FiscalEventCanonicalEncoder reproducing cross-language golden vectors"
```

---

## Task 6: `FiscalIntegrityProvider` interface + `HashChainIntegrityProvider` (PHP + TS) + `SignatureProviderInterface`

`HashChainIntegrityProvider` is the first and only provider in Phase 1 — **sequence integrity, not authorship** — `signature_version = 'hash-chain-integrity-v1'`. `SignatureProviderInterface` is **designed-for, not built**: interface + nullable columns only.

**Files:**
- Create: `apps/api/app/Shared/Contracts/Fiscal/FiscalIntegrityProvider.php`
- Create: `apps/api/app/Shared/Contracts/Fiscal/SignatureProviderInterface.php`
- Create: `apps/api/app/Modules/Fiscal/Application/Services/HashChainIntegrityProvider.php`
- Create: `apps/pos/src/lib/fiscal/HashChainIntegrityProvider.ts`
- Test: `apps/api/tests/Unit/Fiscal/HashChainIntegrityProviderTest.php`, `apps/pos/src/lib/fiscal/__tests__/HashChainIntegrityProvider.test.ts`

- [ ] **Step 1: Write the failing PHP test**

```php
<?php
declare(strict_types=1);
namespace Tests\Unit\Fiscal;
use App\Modules\Fiscal\Application\Services\HashChainIntegrityProvider;
use PHPUnit\Framework\TestCase;

final class HashChainIntegrityProviderTest extends TestCase
{
    public function test_version_and_hash_and_verify(): void
    {
        $p = new HashChainIntegrityProvider();
        $this->assertSame('hash-chain-integrity-v1', $p->version());

        $bytes = '{"business_date":"2026-05-14"}';
        $hash = $p->computeHash($bytes);
        $this->assertSame(hash('sha256', $bytes), $hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);

        $this->assertTrue($p->verify($bytes, $hash));
        $this->assertFalse($p->verify($bytes, str_repeat('0', 64))); // tamper
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/HashChainIntegrityProviderTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the interfaces + providers**

`FiscalIntegrityProvider` interface: `version(): string`, `computeHash(string $canonicalBytes): string` (lowercase-hex SHA-256), `verify(string $canonicalBytes, string $currentHash): bool` (recompute and compare). `HashChainIntegrityProvider` implements it with `version()` returning `'hash-chain-integrity-v1'`. `SignatureProviderInterface`: `sign(...)` may return a `pending` result; capability flags `requiresConnectivity(): bool`, `signsSynchronously(): bool`, `assignsTransactionId(): bool` — **no concrete implementation in Phase 1**. The TS `HashChainIntegrityProvider` mirrors the PHP interface (delegating `sha256Hex` to the canonical encoder's primitive).

**Provider wiring (this task):** edit `FiscalServiceProvider::register()` to bind `FiscalIntegrityProvider::class → HashChainIntegrityProvider::class` (the interface is what `OutboxIngestor` and `VerifyEventChainCommand` will typehint; without the binding `app()` cannot resolve it). Add a `FiscalServiceProvider` test asserting `app(FiscalIntegrityProvider::class)` is a `HashChainIntegrityProvider`. `git add` the provider file in this task's commit.

- [ ] **Step 4: Write + run the TS test, then run both green**

```ts
import { describe, it, expect } from 'vitest';
import { HashChainIntegrityProvider } from '../HashChainIntegrityProvider';

describe('HashChainIntegrityProvider (device)', () => {
  const p = new HashChainIntegrityProvider();
  it('version, deterministic hash, verify true/false', () => {
    expect(p.version()).toBe('hash-chain-integrity-v1');
    const bytes = '{"business_date":"2026-05-14"}';
    const h = p.computeHash(bytes);
    expect(h).toMatch(/^[0-9a-f]{64}$/);
    expect(p.verify(bytes, h)).toBe(true);
    expect(p.verify(bytes, '0'.repeat(64))).toBe(false);
  });
});
```

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/HashChainIntegrityProviderTest.php` → PASS.
Run: `cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/HashChainIntegrityProvider.test.ts` → PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Shared/Contracts/Fiscal/ apps/api/app/Modules/Fiscal/Application/Services/HashChainIntegrityProvider.php apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php apps/pos/src/lib/fiscal/HashChainIntegrityProvider.ts apps/api/tests/Unit/Fiscal/HashChainIntegrityProviderTest.php apps/pos/src/lib/fiscal/__tests__/HashChainIntegrityProvider.test.ts
git commit -m "feat(fiscal): FiscalIntegrityProvider + HashChainIntegrityProvider (PHP+TS) + SignatureProviderInterface"
```

---

## Task 7: `create_fiscal_events_table` migration (server PostgreSQL)

**Precondition:** Task 1 signed off.

**Files:**
- Create: `apps/api/database/migrations/2026_05_14_100001_create_fiscal_events_table.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEvent.php`
- Test: `apps/api/tests/Feature/Fiscal/FiscalEventsTableTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Fiscal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FiscalEventsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_all_chain_and_integrity_columns(): void
    {
        $this->assertTrue(Schema::hasTable('fiscal_events'));
        foreach (['id','tenant_id','company_id','terminal_id','operator_id','event_type',
                  'event_version','signature_version','sequence_number','event_time_device',
                  'business_date','server_received_at','canonical_bytes','previous_hash',
                  'current_hash','signature_status','integrity_status','integrity_exception_class',
                  'payload','payload_parse_status','created_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('fiscal_events', $col), "missing {$col}");
        }
    }

    public function test_unique_sequence_key_blocks_duplicate_slot(): void
    {
        $a = $this->insertEvent(['sequence_number' => 1]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->insertEvent(['sequence_number' => 1, 'id' => \Str::uuid()->toString()]); // same (tenant,terminal,seq)
    }

    public function test_hash_format_check_constraint(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->insertEvent(['current_hash' => 'NOT-HEX']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventsTableTest.php`
Expected: FAIL — table does not exist.

- [ ] **Step 3: Implement the migration + model**

Migration creates `fiscal_events` exactly per spec §3.2 — all columns with the listed types, `server_received_at` set by the ingestor (not defaulted), `canonical_bytes BYTEA NOT NULL`, the nullable signature columns, the integrity columns (`integrity_status` default `'verified'`), `payload JSONB` + `payload_parse_status` default `'pending'`. Add the §3.2 indexes/constraints: `UNIQUE (tenant_id, terminal_id, sequence_number)`, `UNIQUE (source_event_class, source_event_id) WHERE source_event_id IS NOT NULL`, partial indexes on `reference_event_id` / `reference_document_id` / `integrity_status <> 'verified'`, `CHECK (sequence_number > 0)`, `CHECK (current_hash ~ '^[0-9a-f]{64}$')`, `CHECK (previous_hash ~ '^[0-9a-f]{64}$')`, the source-event paired-null CHECK, and `CHECK (event_type IN (...))` using `FiscalEventType::checkConstraintList()`. The `FiscalEvent` model: `$table = 'fiscal_events'`, `$incrementing = false`, `$keyType = 'string'`, casts `event_type → FiscalEventType`, `integrity_status → IntegrityStatus`, `payload → array`, `payload_parse_status → PayloadParseStatus`, `signature_status → SignatureStatus`, timestamps `business_date`/`event_time_device`/`server_received_at`. Use raw `DB::statement` for the CHECK constraints and `BYTEA` column (Laravel's `binary()` maps correctly on PG).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventsTableTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/database/migrations/2026_05_14_100001_create_fiscal_events_table.php apps/api/app/Modules/Fiscal/Domain/Models/FiscalEvent.php apps/api/tests/Feature/Fiscal/FiscalEventsTableTest.php
git commit -m "feat(fiscal): create_fiscal_events_table migration + FiscalEvent model"
```

---

## Task 8: `create_fiscal_events_immutability` triggers (server PostgreSQL)

Modelled on the existing `pos_receipts` immutability trigger (`[reality §1.5]`).

**Files:**
- Create: `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php`
- Create: `apps/api/docs/runbooks/fiscal-events-break-glass.md`
- Test: `apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_allowed_column_update_succeeds(): void
{
    $e = $this->insertEvent(['payload_parse_status' => 'pending']);
    DB::table('fiscal_events')->where('id', $e)->update([
        'payload' => json_encode(['ok' => true]), 'payload_parse_status' => 'parsed',
    ]); // no exception
    $this->assertSame('parsed', DB::table('fiscal_events')->where('id', $e)->value('payload_parse_status'));
}

public function test_forbidden_column_update_raises(): void
{
    $e = $this->insertEvent();
    $this->expectException(\Illuminate\Database\QueryException::class);
    DB::table('fiscal_events')->where('id', $e)->update(['current_hash' => str_repeat('a', 64)]);
}

public function test_payload_is_write_once(): void
{
    $e = $this->insertEvent(['payload_parse_status' => 'pending']);
    DB::table('fiscal_events')->where('id', $e)->update(['payload' => json_encode(['v' => 1]), 'payload_parse_status' => 'parsed']);
    $this->expectException(\Illuminate\Database\QueryException::class);
    DB::table('fiscal_events')->where('id', $e)->update(['payload' => json_encode(['v' => 2])]);
}

/**
 * The parse-failure resume path (Task 24 / spec §7.5): a quarantined canonical_parse_failure
 * event must be resolvable failed -> parsed. Without this named transition the BLOCKER stands —
 * Task 24 would be unbuildable. Built here so the trigger supports the resume contract from the start.
 */
public function test_parse_failure_resume_transition_failed_to_parsed_is_allowed(): void
{
    $e = $this->insertEvent([
        'payload' => null, 'payload_parse_status' => 'failed',
        'integrity_status' => 'quarantined', 'integrity_exception_class' => 'canonical_parse_failure',
    ]);
    DB::table('fiscal_events')->where('id', $e)->update([
        'payload' => json_encode(['resolved' => true]),
        'payload_parse_status' => 'parsed',
        'integrity_status' => 'verified',
        'integrity_resolved_at' => now(),
        'integrity_resolved_by' => \Str::uuid()->toString(),
    ]); // no exception — the resolution update is one atomic update
    $this->assertSame('parsed', DB::table('fiscal_events')->where('id', $e)->value('payload_parse_status'));
}

public function test_failed_to_parsed_is_rejected_when_not_a_parse_failure_resolution(): void
{
    // failed -> parsed is allowed ONLY for a quarantined canonical_parse_failure being resolved to verified
    $e = $this->insertEvent(['payload' => null, 'payload_parse_status' => 'failed', 'integrity_status' => 'verified']);
    $this->expectException(\Illuminate\Database\QueryException::class);
    DB::table('fiscal_events')->where('id', $e)->update(['payload' => json_encode(['x' => 1]), 'payload_parse_status' => 'parsed']);
}

public function test_delete_and_truncate_raise(): void
{
    $e = $this->insertEvent();
    try { DB::table('fiscal_events')->where('id', $e)->delete(); $this->fail('delete allowed'); }
    catch (\Illuminate\Database\QueryException) {}
    $this->expectException(\Illuminate\Database\QueryException::class);
    DB::statement('TRUNCATE fiscal_events');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php`
Expected: FAIL — updates/deletes not blocked.

- [ ] **Step 3: Implement the migration**

`DB::unprepared` to create: a `BEFORE UPDATE` trigger function that `RAISE EXCEPTION` unless **only** the allowed columns changed (`payload`, `payload_parse_status`, `integrity_status`, `integrity_exception_class`, `integrity_exception_reason`, `integrity_resolved_at`, `integrity_resolved_by`); within the allowed set enforce the state transitions:

- **`payload_parse_status`** — three named allowed transitions, everything else raises:
  1. `pending → parsed` — the normal ingestion parse success.
  2. `pending → failed` — the normal ingestion parse failure.
  3. **`failed → parsed`** — the parse-failure resume path (spec §7.5 / Task 24), allowed **only** when **all** of these hold in the same `UPDATE`: `OLD.payload IS NULL`, `NEW.payload IS NOT NULL`, `OLD.integrity_exception_class = 'canonical_parse_failure'`, `OLD.integrity_status = 'quarantined'`, `NEW.integrity_status = 'verified'`, and `NEW.integrity_resolved_at` / `NEW.integrity_resolved_by` are both non-NULL. Any `failed → parsed` update missing one of these conditions raises.
- **`payload`** — write-once: settable only when `payload_parse_status` goes `pending → parsed` **or** `failed → parsed` (per the resume rule above). A second `payload` write raises.
- **`integrity_status`** — only `verified → quarantined` (ingestor) or `quarantined → verified` (resolver — must also set `integrity_resolved_at` / `integrity_resolved_by`); no other transition.

A `BEFORE DELETE` trigger that always `RAISE EXCEPTION`. A `BEFORE TRUNCATE ... FOR EACH STATEMENT` trigger that always `RAISE EXCEPTION`. `REVOKE TRUNCATE ON fiscal_events FROM <application_role>`. The runbook documents the DBA-only break-glass: signed full export first. `down()` drops the triggers/functions.

> **Resolves the plan-review BLOCKER:** the trigger is built to support the §7.5 parse-failure resume contract from the start — Task 24's resolver `UPDATE` (`payload` write + `failed → parsed` flip + `quarantined → verified` + resolution stamps, all in one update) is a named allowed transition here, tested in Step 1 above, not discovered as a contradiction in Task 24.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php apps/api/docs/runbooks/fiscal-events-break-glass.md apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php
git commit -m "feat(fiscal): fiscal_events immutability triggers + break-glass runbook"
```

---

## Task 9: `create_fiscal_event_projections_table` migration

Mutable tracking table — **no immutability trigger** (§7.5).

**Files:**
- Create: `apps/api/database/migrations/2026_05_14_100003_create_fiscal_event_projections_table.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventProjectionRow.php`
- Test: `apps/api/tests/Feature/Fiscal/FiscalEventProjectionsTableTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_table_columns_and_unique_constraint(): void
{
    $this->assertTrue(Schema::hasTable('fiscal_event_projections'));
    foreach (['id','fiscal_event_id','projector_name','projection_status','attempts',
              'last_error','last_attempted_at','applied_at','dead_lettered_at','created_at','updated_at'] as $c) {
        $this->assertTrue(Schema::hasColumn('fiscal_event_projections', $c), "missing {$c}");
    }
    $event = $this->insertEvent();
    DB::table('fiscal_event_projections')->insert($this->row($event, 'pos_core_receipt'));
    $this->expectException(\Illuminate\Database\QueryException::class);
    DB::table('fiscal_event_projections')->insert($this->row($event, 'pos_core_receipt')); // dup (fiscal_event_id, projector_name)
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventProjectionsTableTest.php`
Expected: FAIL — table missing.

- [ ] **Step 3: Implement migration + model**

Migration creates `fiscal_event_projections` per §7.5: `id` UUID PK, `fiscal_event_id` UUID FK → `fiscal_events(id)`, `projector_name` VARCHAR(64), `projection_status` VARCHAR(20) default `'pending'`, `attempts` default 0, `last_error` TEXT, `last_attempted_at`/`applied_at`/`dead_lettered_at` TIMESTAMPTZ nullable, `created_at`/`updated_at`, `UNIQUE (fiscal_event_id, projector_name)`. No immutability trigger. Model casts `projection_status → ProjectionStatus`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventProjectionsTableTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/database/migrations/2026_05_14_100003_create_fiscal_event_projections_table.php apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventProjectionRow.php apps/api/tests/Feature/Fiscal/FiscalEventProjectionsTableTest.php
git commit -m "feat(fiscal): create_fiscal_event_projections_table migration + model"
```

---

## Task 10: `create_fiscal_event_quarantine_table` migration

The non-admissible-envelope partition (§8) — `sequence_conflict` envelopes that physically cannot enter `fiscal_events`.

**Files:**
- Create: `apps/api/database/migrations/2026_05_14_100004_create_fiscal_event_quarantine_table.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventQuarantine.php`
- Test: `apps/api/tests/Feature/Fiscal/FiscalEventQuarantineTableTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_quarantine_table_has_envelope_and_metadata_columns(): void
{
    $this->assertTrue(Schema::hasTable('fiscal_event_quarantine'));
    foreach (['id','tenant_id','company_id','terminal_id','operator_id','envelope_event_id',
              'event_type','event_version','claimed_sequence_number','event_time_device','business_date',
              'previous_hash','current_hash','canonical_bytes','raw_envelope','payload_parse_status',
              'integrity_exception_class','integrity_exception_reason','conflicting_event_id',
              'server_received_at','resolved_at','resolved_by','created_at'] as $c) {
        $this->assertTrue(Schema::hasColumn('fiscal_event_quarantine', $c), "missing {$c}");
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventQuarantineTableTest.php`
Expected: FAIL — table missing.

- [ ] **Step 3: Implement migration + model**

Create `fiscal_event_quarantine` exactly per the §8 schema block: the query-critical metadata columns mirrored from the envelope, `canonical_bytes BYTEA NOT NULL`, `raw_envelope JSONB NOT NULL`, `payload_parse_status` nullable, the incident-metadata columns (`integrity_exception_class` NOT NULL, `integrity_exception_reason` TEXT NOT NULL, `conflicting_event_id` UUID NOT NULL, `server_received_at` NOT NULL, `resolved_at`/`resolved_by` nullable). No immutability trigger. Model casts `raw_envelope → array`, `integrity_exception_class → IntegrityExceptionClass`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventQuarantineTableTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/database/migrations/2026_05_14_100004_create_fiscal_event_quarantine_table.php apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventQuarantine.php apps/api/tests/Feature/Fiscal/FiscalEventQuarantineTableTest.php
git commit -m "feat(fiscal): create_fiscal_event_quarantine_table migration + model"
```

---

## Task 11: `add_canonical_bytes_and_fiscal_event_id_to_pos_receipts` + convert chain columns to mirrors

`pos_receipts` / `offline_receipts` become **projection rows** — `fiscal_hash` / `previous_hash` / `hash_sequence` mirror the authoritative `fiscal_events` row; they are no longer an independent chain. `pos_receipts` also gains `fiscal_event_id` — the **linkage to the authoritative event** and the idempotency anchor `PosCoreReceiptProjection` (Task 21) relies on.

**Files:**
- Create: `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php`
- Modify: the `pos_receipts` Eloquent model — add `fiscal_event_id` + `canonical_bytes` to `$fillable`
- Test: `apps/api/tests/Feature/Fiscal/PosReceiptsCanonicalBytesTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_pos_receipts_gains_canonical_bytes_bytea_nullable(): void
{
    $this->assertTrue(Schema::hasColumn('pos_receipts', 'canonical_bytes'));
    // existing chain columns retained as mirrors — still present, now documented as mirror columns
    $this->assertTrue(Schema::hasColumn('pos_receipts', 'fiscal_hash'));
}

public function test_pos_receipts_gains_fiscal_event_id_unique_fk(): void
{
    $this->assertTrue(Schema::hasColumn('pos_receipts', 'fiscal_event_id'));
    // UNIQUE — one pos_receipts row per fiscal event; this is the PosCoreReceiptProjection idempotency anchor
    $event = $this->insertFiscalEvent();
    \DB::table('pos_receipts')->insert($this->minimalReceiptRow(['fiscal_event_id' => $event]));
    $this->expectException(\Illuminate\Database\QueryException::class);
    \DB::table('pos_receipts')->insert($this->minimalReceiptRow(['fiscal_event_id' => $event])); // duplicate linkage rejected
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/PosReceiptsCanonicalBytesTest.php`
Expected: FAIL — `canonical_bytes` / `fiscal_event_id` columns missing.

- [ ] **Step 3: Implement migration**

Add `pos_receipts.canonical_bytes BYTEA NULL`. Add `pos_receipts.fiscal_event_id UUID NULL` with `UNIQUE` and FK → `fiscal_events(id)` — nullable because pre-rebuild rows have none, `UNIQUE` because one `pos_receipts` row maps to exactly one `SALE_RECEIPT` fiscal event (this is the `apply()` idempotency guard in Task 21). Add `fiscal_event_id` + `canonical_bytes` to the `pos_receipts` model `$fillable`. Add a migration comment documenting that `fiscal_hash` / `previous_hash` / `hash_sequence` are now **backward-compatible mirror columns** of the `fiscal_events` row's values, populated by `PosCoreReceiptProjection` (Task 21), not advanced independently. **Do not drop** the legacy columns (read-compat). The device `offline_receipts.canonical_bytes TEXT` column is added in the Task 13 device migration.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/PosReceiptsCanonicalBytesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php apps/api/app/Modules/POS/Domain/Models/PosReceipt.php apps/api/tests/Feature/Fiscal/PosReceiptsCanonicalBytesTest.php
git commit -m "feat(fiscal): add canonical_bytes + fiscal_event_id to pos_receipts; chain columns become mirrors"
```
*(Confirm the `pos_receipts` model path when implementing — `grep -rl "pos_receipts" apps/api/app/Modules/POS --include=*.php` for the Eloquent model.)*

---

## Task 12: `add_origin_and_fiscal_event_id_to_payments` + `PaymentOrigin` enum

Treasury-module integration migration (§13). The FK runs `payments → fiscal_events` — a module depending on the engine, the correct direction.

**Files:**
- Create: `apps/api/database/migrations/2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php`
- Create: `apps/api/app/Modules/Treasury/Domain/Enums/PaymentOrigin.php`
- Modify: `apps/api/app/Modules/Treasury/Domain/Payment.php` — the Treasury `Payment` model, class `App\Modules\Treasury\Domain\Payment` (**not** `Domain/Models/Payment.php` — that path does not exist; verified against the codebase). Add `origin` + `fiscal_event_id` to `$fillable`, cast `origin → PaymentOrigin`.
- Test: `apps/api/tests/Feature/Fiscal/PaymentsOriginColumnsTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_payments_gains_origin_and_fiscal_event_id(): void
{
    $this->assertTrue(Schema::hasColumn('payments', 'origin'));
    $this->assertTrue(Schema::hasColumn('payments', 'fiscal_event_id'));
}

public function test_payment_origin_enum_values(): void
{
    $this->assertSame(
        ['pos','web_admin','mobile','api','unknown_legacy'],
        array_map(fn ($c) => $c->value, \App\Modules\Treasury\Domain\Enums\PaymentOrigin::cases()),
    );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/PaymentsOriginColumnsTest.php`
Expected: FAIL — columns/enum missing.

- [ ] **Step 3: Implement migration + enum + model edit**

Migration: `payments.origin VARCHAR(32) NULL`, `payments.fiscal_event_id UUID NULL` with FK → `fiscal_events(id)`. `PaymentOrigin` backed string enum: `pos | web_admin | mobile | api | unknown_legacy`. Edit the Treasury `Payment` model at `apps/api/app/Modules/Treasury/Domain/Payment.php` (class `App\Modules\Treasury\Domain\Payment`) — add `origin` + `fiscal_event_id` to `$fillable`, cast `origin → PaymentOrigin`. **Verification step:** confirm `App\Modules\Treasury\Domain\Payment` is the model imported by the §13 writers (`PaymentController`, `MultiPaymentService`, `PaymentRefundService`, `VendorRefundService`) — `grep -rn "use App\\\\Modules\\\\Treasury\\\\Domain\\\\Payment" apps/api/app/Modules/Treasury` — before editing, so the writer updates in Task 22 target the same class. No GL/allocation behavior change — purely additive.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/PaymentsOriginColumnsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/database/migrations/2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php apps/api/app/Modules/Treasury/Domain/Enums/PaymentOrigin.php apps/api/app/Modules/Treasury/Domain/Payment.php apps/api/tests/Feature/Fiscal/PaymentsOriginColumnsTest.php
git commit -m "feat(treasury): add origin + fiscal_event_id to payments; PaymentOrigin enum"
```

---

## Task 13: Device SQLite `fiscal_events` table + triggers + `terminal_state` chain head

Device SQLite is **authoritative**. Follow the existing Tauri device-migration pattern (locate it: `grep -rl "CREATE TABLE" apps/pos/src --include=*.ts` / the migrations runner the app uses for `offline_receipts`).

**Files:**
- Create: a device SQLite migration in the existing device-migration set (path per the established pattern)
- Test: `apps/pos/src/lib/fiscal/__tests__/deviceSchema.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect, beforeEach } from 'vitest';
import { openTestDb, runDeviceMigrations } from '../../../test-utils/db';

describe('device fiscal_events schema', () => {
  let db;
  beforeEach(async () => { db = await openTestDb(); await runDeviceMigrations(db); });

  it('creates fiscal_events with chain + sync-lifecycle columns', async () => {
    const cols = await db.all(`PRAGMA table_info(fiscal_events)`);
    const names = cols.map((c) => c.name);
    for (const c of ['id','tenant_id','terminal_id','event_type','sequence_number',
                     'canonical_bytes','previous_hash','current_hash','sync_status','created_at']) {
      expect(names).toContain(c);
    }
  });

  it('terminal_state gains the single fiscal-event chain head', async () => {
    const cols = (await db.all(`PRAGMA table_info(terminal_state)`)).map((c) => c.name);
    expect(cols).toContain('fiscal_event_genesis_seed');
    expect(cols).toContain('fiscal_event_last_hash');
    expect(cols).toContain('fiscal_event_sequence');
  });

  it('blocks UPDATE of non-sync columns and any DELETE', async () => {
    await insertFiscalEvent(db, { sequence_number: 1 });
    await expect(db.run(`UPDATE fiscal_events SET current_hash='x'`)).rejects.toThrow();
    await db.run(`UPDATE fiscal_events SET sync_status='synced'`); // allowed
    await expect(db.run(`DELETE FROM fiscal_events`)).rejects.toThrow();
  });

  it('adds offline_receipts.canonical_bytes', async () => {
    const cols = (await db.all(`PRAGMA table_info(offline_receipts)`)).map((c) => c.name);
    expect(cols).toContain('canonical_bytes');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/deviceSchema.test.ts`
Expected: FAIL — `fiscal_events` table / columns missing.

- [ ] **Step 3: Implement the device migration**

`CREATE TABLE fiscal_events` per §3.1 (all columns, `canonical_bytes TEXT NOT NULL`, sync-lifecycle columns). `CREATE TRIGGER` `BEFORE UPDATE` → `RAISE(ABORT)` unless only `sync_status`/`sync_error`/`synced_at` changed; `BEFORE DELETE` → `RAISE(ABORT)`. `ALTER TABLE terminal_state ADD COLUMN` × 3: `fiscal_event_genesis_seed`, `fiscal_event_last_hash`, `fiscal_event_sequence`. `ALTER TABLE offline_receipts ADD COLUMN canonical_bytes TEXT`. The legacy `last_hash`/`hash_sequence` columns on `terminal_state` stay (mirror only).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/deviceSchema.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/.../migrations apps/pos/src/lib/fiscal/__tests__/deviceSchema.test.ts
git commit -m "feat(pos): device SQLite fiscal_events table + immutability triggers + chain head"
```

---

## Task 14: `FiscalEventPayloadRegistry` + payload DTOs (server + device)

Resolves the payload DTO + `event_version` per event type; `append()` throws `FiscalEventTypeNotImplemented` for types with no Phase-1 handler.

**Files:**
- Create: `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/DTOs/{SaleReceiptPayload,ChainBreakDetectedPayload,ChainRestartPayload,TerminalRegistrySnapshotPayload,CompanyDayClosureManifestPayload}.php`
- Create: `apps/api/app/Modules/Fiscal/Domain/Exceptions/FiscalEventTypeNotImplemented.php`
- Create: `apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts`
- Test: `apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php`, `apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts`

- [ ] **Step 1: Write the failing PHP test**

```php
public function test_resolves_implemented_payload_dto_and_version(): void
{
    $r = new FiscalEventPayloadRegistry();
    $this->assertSame(SaleReceiptPayload::class, $r->dtoClassFor(FiscalEventType::SALE_RECEIPT));
    $this->assertSame(1, $r->eventVersionFor(FiscalEventType::SALE_RECEIPT));
}

public function test_reserved_type_throws_not_implemented(): void
{
    $r = new FiscalEventPayloadRegistry();
    $this->expectException(FiscalEventTypeNotImplemented::class);
    $r->dtoClassFor(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the registry + DTOs**

Each payload DTO is a `readonly` class with typed properties + a `fromArray(array): self` and `toArray(): array`. `SaleReceiptPayload` carries the receipt business document fields (lines, totals, VAT breakdown, payment lines, voucher redemptions, currency, scale). `ChainBreakDetectedPayload` (reason, last-good sequence + hash, offending record reference); `ChainRestartPayload` (new genesis reference, last-good anchor, operator authorization evidence, provenance link). `TerminalRegistrySnapshotPayload` (authoritative terminal list, snapshot hash, prior-snapshot link). `CompanyDayClosureManifestPayload` — **schema only, reserved**. Registry maps `FiscalEventType → [dtoClass, eventVersion]` for the four implemented types; `dtoClassFor()` throws `FiscalEventTypeNotImplemented` for everything else. The TS registry mirrors this for the device side.

- [ ] **Step 4: Write/run the TS test, run both green**

TS test asserts `dtoFor('SALE_RECEIPT')` resolves and `dtoFor('SALE_VOID')` throws `FiscalEventTypeNotImplemented`.
Run both suites → PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php apps/api/app/Modules/Fiscal/Domain/DTOs/ apps/api/app/Modules/Fiscal/Domain/Exceptions/FiscalEventTypeNotImplemented.php apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts
git commit -m "feat(fiscal): FiscalEventPayloadRegistry + Phase 1 payload DTOs (PHP+TS)"
```

---

## Task 15: `FiscalEventEngine.append()` (device, TypeScript)

The authority. Runs **inside the caller's SQLite transaction**; `append()` does not commit.

**Files:**
- Create: `apps/pos/src/lib/fiscal/FiscalEventEngine.ts`
- Test: `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect, beforeEach } from 'vitest';
import { FiscalEventEngine } from '../FiscalEventEngine';
import { openTestDb, runDeviceMigrations } from '../../../test-utils/db';

describe('FiscalEventEngine.append', () => {
  let db, engine;
  beforeEach(async () => {
    db = await openTestDb(); await runDeviceMigrations(db);
    await seedTerminalState(db, { fiscal_event_genesis_seed: 'a'.repeat(64), fiscal_event_sequence: 0 });
    engine = new FiscalEventEngine(db, /* encoder, integrityProvider, payloadRegistry */);
  });

  it('first event links previous_hash to the genesis seed; sequence starts at 1', async () => {
    const e = await db.transaction((tx) => engine.append(tx, saleReceiptRequest()));
    expect(e.sequence_number).toBe(1);
    expect(e.previous_hash).toBe('a'.repeat(64));
    expect(e.current_hash).toMatch(/^[0-9a-f]{64}$/);
  });

  it('second event chains previous_hash to the first event current_hash; sequence increments', async () => {
    const first = await db.transaction((tx) => engine.append(tx, saleReceiptRequest()));
    const second = await db.transaction((tx) => engine.append(tx, saleReceiptRequest()));
    expect(second.sequence_number).toBe(2);
    expect(second.previous_hash).toBe(first.current_hash);
  });

  it('runs inside the caller transaction — a rollback leaves no row and no chain-head advance', async () => {
    await expect(db.transaction(async (tx) => {
      await engine.append(tx, saleReceiptRequest());
      throw new Error('caller rollback');
    })).rejects.toThrow();
    expect(await db.get(`SELECT COUNT(*) c FROM fiscal_events`)).toMatchObject({ c: 0 });
    expect(await db.get(`SELECT fiscal_event_sequence s FROM terminal_state`)).toMatchObject({ s: 0 });
  });

  it('device-side idempotency: re-emitting a source-backed event returns the existing row', async () => {
    const req = saleReceiptRequest({ source_event_class: 'OfflineReceipt', source_event_id: 'r-1' });
    const a = await db.transaction((tx) => engine.append(tx, req));
    const b = await db.transaction((tx) => engine.append(tx, req));
    expect(b.id).toBe(a.id);
    expect(await db.get(`SELECT COUNT(*) c FROM fiscal_events`)).toMatchObject({ c: 1 });
  });

  it('throws FiscalEventTypeNotImplemented for a reserved type', async () => {
    await expect(db.transaction((tx) => engine.append(tx, { ...saleReceiptRequest(), event_type: 'SALE_VOID' })))
      .rejects.toThrow('FiscalEventTypeNotImplemented');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/FiscalEventEngine.test.ts`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement `FiscalEventEngine.append()`**

Per §6.1, executed inside the caller's transaction `tx`:
1. resolve payload DTO + `event_version` via `FiscalEventPayloadRegistry`; throw `FiscalEventTypeNotImplemented` if no Phase-1 handler.
2. device-side idempotency: if `(source_event_class, source_event_id)` set and a local row exists, return it.
3. read the single chain head from `terminal_state` (`fiscal_event_last_hash` or `fiscal_event_genesis_seed` for the first event; `fiscal_event_sequence`).
4. build `canonical_bytes` via `FiscalEventCanonicalEncoder`; `current_hash = HashChainIntegrityProvider.computeHash(canonical_bytes)`.
5. `INSERT` the `fiscal_events` row (`sync_status='pending'`, `signature_status='not_required'`).
6. advance the chain head: `fiscal_event_last_hash := current_hash`, `fiscal_event_sequence := sequence_number`.
The caller commits; `append()` never commits. A debounced sync flush is scheduled post-commit **by the caller** (Task 16 wiring).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/FiscalEventEngine.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/lib/fiscal/FiscalEventEngine.ts apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts
git commit -m "feat(pos): FiscalEventEngine.append — the single device chain authoring path"
```

---

## Task 16: `StrictCanonicalParser` (server)

Derives the structured `payload` JSONB from verified `canonical_bytes`. Rejects duplicate keys, out-of-grammar numbers, invalid Unicode, event-type schema violations (§7.6).

**Files:**
- Create: `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php`
- Create: `apps/api/app/Modules/Fiscal/Application/DTOs/ParseResult.php`
- Test: `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_parses_valid_canonical_bytes_into_payload(): void
{
    $parser = new StrictCanonicalParser(new FiscalEventPayloadRegistry());
    $result = $parser->parse('{"event_type":"SALE_RECEIPT",...valid...}', FiscalEventType::SALE_RECEIPT);
    $this->assertTrue($result->ok);
    $this->assertIsArray($result->payload);
}

public function test_rejects_duplicate_keys(): void
{
    $parser = new StrictCanonicalParser(new FiscalEventPayloadRegistry());
    $result = $parser->parse('{"a":1,"a":2}', FiscalEventType::SALE_RECEIPT);
    $this->assertFalse($result->ok);
}

public function test_rejects_out_of_grammar_numbers_and_invalid_unicode(): void
{
    $parser = new StrictCanonicalParser(new FiscalEventPayloadRegistry());
    $this->assertFalse($parser->parse('{"total":1.5e3}', FiscalEventType::SALE_RECEIPT)->ok);
    $this->assertFalse($parser->parse("{\"x\":\"\xC3\x28\"}", FiscalEventType::SALE_RECEIPT)->ok);
}

public function test_rejects_event_type_schema_violation(): void
{
    $parser = new StrictCanonicalParser(new FiscalEventPayloadRegistry());
    $this->assertFalse($parser->parse('{"event_type":"SALE_RECEIPT"}', FiscalEventType::SALE_RECEIPT)->ok); // missing required fields
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/StrictCanonicalParserTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the parser**

`parse(string $canonicalBytes, FiscalEventType $type): ParseResult`. Reject duplicate keys (scan with a strict tokenizer or `json_decode` with a duplicate-key-detecting pass — PHP's `json_decode` silently overwrites, so detect duplicates explicitly). Reject out-of-grammar numbers (exponents, non-integer floats outside money strings). Reject invalid UTF-8 (`mb_check_encoding`). Validate against the event-type's payload DTO schema via `FiscalEventPayloadRegistry::dtoClassFor($type)::fromArray()` (which throws on missing/typed fields). `ParseResult` is `readonly { bool $ok, ?array $payload, ?string $failureReason }`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/StrictCanonicalParserTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php apps/api/app/Modules/Fiscal/Application/DTOs/ParseResult.php apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php
git commit -m "feat(fiscal): StrictCanonicalParser — derive payload JSONB from verified canonical_bytes"
```

---

## Task 17: `ModuleActivationResolver` interface + `DefaultModuleActivationResolver`

The per-`(tenant, company)` activation seam. **Canonical PascalCase token `'Treasury'`** — `CompanyConfig::hasModule()` strict-compares.

**Files:**
- Create: `apps/api/app/Shared/Contracts/Fiscal/ModuleActivationResolver.php`
- Create: `apps/api/app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php`
- Test: `apps/api/tests/Feature/Fiscal/ModuleActivationResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_treasury_active_for_standard_seeded_tenant(): void
{
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    [$tenant, $company] = $this->seedStandardTenantCompany(); // Vertical::defaultModules() includes 'Treasury'
    $resolver = app(\App\Shared\Contracts\Fiscal\ModuleActivationResolver::class);
    $this->assertTrue($resolver->isActive('Treasury', $tenant->id, $company->id));
}

public function test_token_is_pascalcase_strict_compared(): void
{
    [$tenant, $company] = $this->seedStandardTenantCompany();
    $resolver = app(\App\Shared\Contracts\Fiscal\ModuleActivationResolver::class);
    // lowercase token must NOT resolve true — CompanyConfig::hasModule does in_array(..., true)
    $this->assertFalse($resolver->isActive('treasury', $tenant->id, $company->id));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/ModuleActivationResolverTest.php`
Expected: FAIL — interface/impl not found.

- [ ] **Step 3: Implement the interface + default resolver**

`ModuleActivationResolver` interface: `isActive(string $module, string $tenantId, string $companyId): bool`. `DefaultModuleActivationResolver` delegates to the existing module-activation surface — `CompanyConfigService` / `CompanyConfig::hasModule()` over `allEnabledModules` (the same surface `RequireModule` reads). It passes the token through to `hasModule()` **without transformation**. Add the §18-3 caveat as a class docblock: the underlying surface is tenant-level-cached and every current vertical default includes `Treasury`, so a production Treasury-inactive deployment is a later config-model change — the seam is real and testable here via a test double.

**Provider wiring (this task):** edit `FiscalServiceProvider::register()` to bind `ModuleActivationResolver::class → DefaultModuleActivationResolver::class`. The Step 1 tests resolve `app(ModuleActivationResolver::class)` — without this binding they cannot go green. `git add` the provider file in this task's commit.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/ModuleActivationResolverTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Shared/Contracts/Fiscal/ModuleActivationResolver.php apps/api/app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php apps/api/tests/Feature/Fiscal/ModuleActivationResolverTest.php
git commit -m "feat(fiscal): ModuleActivationResolver seam with canonical 'Treasury' token"
```

---

## Task 18: `FiscalEventProjector` interface + `FiscalEventProjectionRegistry`

**Files:**
- Create: `apps/api/app/Shared/Contracts/Fiscal/FiscalEventProjector.php`
- Create: `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php`
- Test: `apps/api/tests/Feature/Fiscal/FiscalEventProjectionRegistryTest.php`

- [ ] **Step 1: Write the failing test (with test-double projectors)**

```php
public function test_pos_core_always_included_treasury_gated(): void
{
    // a POS-core fake: requiresModule()=null; a Treasury fake: requiresModule()='Treasury'
    $resolverActive = $this->resolverReporting(['Treasury' => true]);
    $registry = new FiscalEventProjectionRegistry([new FakePosCore(), new FakeTreasury()], $resolverActive);
    $active = $registry->activeProjectorsFor($this->saleReceiptEvent());
    $this->assertEqualsCanonicalizing(['pos_core_receipt', 'treasury_receipt_bridge'],
        array_map(fn ($p) => $p->name(), $active));
}

public function test_treasury_bridge_excluded_when_resolver_reports_inactive(): void
{
    $resolverInactive = $this->resolverReporting(['Treasury' => false]);
    $registry = new FiscalEventProjectionRegistry([new FakePosCore(), new FakeTreasury()], $resolverInactive);
    $active = $registry->activeProjectorsFor($this->saleReceiptEvent());
    $this->assertSame(['pos_core_receipt'], array_map(fn ($p) => $p->name(), $active));
}

public function test_only_projectors_handling_the_event_type_are_returned(): void
{
    $registry = new FiscalEventProjectionRegistry([new FakePosCore()], $this->resolverReporting([]));
    $this->assertSame([], $registry->activeProjectorsFor($this->chainRestartEvent())); // FakePosCore handles SALE_RECEIPT only
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventProjectionRegistryTest.php`
Expected: FAIL — interface/registry not found.

- [ ] **Step 3: Implement the interface + registry**

`FiscalEventProjector` interface: `name(): string`, `handlesEventType(FiscalEventType $type): bool`, `requiresModule(): ?string` (null = always active; the canonical PascalCase token otherwise), `apply(FiscalEvent $event): void` (idempotent, keyed on `(fiscal_event_id, projector_name)`). `FiscalEventProjectionRegistry` ctor takes `iterable<FiscalEventProjector>` + `ModuleActivationResolver`. `activeProjectorsFor(FiscalEvent $event)`: for each registered projector where `handlesEventType()` — include if `requiresModule()` is null, else include only if `resolver->isActive(requiresModule(), tenant_id, company_id)`. The registry depends **only** on the two interfaces — never on Treasury/accounting/sales code.

**Provider wiring (this task):** edit `FiscalServiceProvider::register()` to bind `FiscalEventProjectionRegistry` as a singleton constructed over `$this->app->tagged(FiscalEventProjector::class)` + the resolver. The Step 1 tests construct the registry directly with fakes (so they pass without the binding), but `OutboxIngestor` (Task 19) is container-resolved and depends on the registry — the binding must exist before Task 19. The tagged set is **empty** until Tasks 21/22 tag the real projectors; an empty registry is valid here. `git add` the provider file in this task's commit.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventProjectionRegistryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Shared/Contracts/Fiscal/FiscalEventProjector.php apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php apps/api/tests/Feature/Fiscal/FiscalEventProjectionRegistryTest.php
git commit -m "feat(fiscal): FiscalEventProjector interface + projection registry seam"
```

---

## Task 19: `OutboxIngestor.ingest()` — validate-then-insert, conflict + quarantine handling

The server-side verify-only mirror core (§7.2, §8). Standalone operation — not nested in a business transaction.

**Files:**
- Create: `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php`
- Create: `apps/api/app/Modules/Fiscal/Application/DTOs/{FiscalEventEnvelope,IngestionResult}.php`
- Test: `apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php`

> **Test-projector note (resolves the v3-review P1 — task-by-task ordering).** The **production** registry's tagged `FiscalEventProjector` set is **empty** until Tasks 21/22 tag the real projectors — do **not** move those tags earlier. So Task 19's "one pending row per active projector" assertion uses a **test-local fake**: `OutboxIngestorTest` defines a `FakeSaleReceiptProjector implements FiscalEventProjector` (`name()='fake_sale_receipt'`, `handlesEventType()` true for `SALE_RECEIPT`, `requiresModule()=null`, `apply()` no-op), and in `setUp()` tags it into the container (`$this->app->tag([FakeSaleReceiptProjector::class], FiscalEventProjector::class)`) **before** resolving `OutboxIngestor`, so the container-built `FiscalEventProjectionRegistry` sees exactly one active projector. The fake exists only to prove `OutboxIngestor` inserts one `fiscal_event_projections` row per active projector; production wiring stays empty until Tasks 21/22. A companion test (below) covers the genuinely-empty-registry case.

- [ ] **Step 1: Write the failing test**

```php
// setUp(): $this->app->tag([FakeSaleReceiptProjector::class], FiscalEventProjector::class);
//          (FakeSaleReceiptProjector is defined in this test file — see the test-projector note above)

public function test_verified_event_is_stored_with_payload_and_one_row_per_active_projector(): void
{
    // exactly one projector is tagged in setUp (the test-local fake) → exactly one pending row
    $result = $this->ingest($this->validEnvelope(['sequence_number' => 1]));
    $this->assertTrue($result->stored);
    $row = \DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
    $this->assertSame('verified', $row->integrity_status);
    $this->assertSame('parsed', $row->payload_parse_status);
    $this->assertNotNull($row->server_received_at);
    // one pending projection row per active projector, in the SAME transaction
    $this->assertSame(1, \DB::table('fiscal_event_projections')
        ->where('fiscal_event_id', $result->fiscalEventId)->where('projection_status', 'pending')->count());
}

public function test_verified_event_stores_with_zero_projection_rows_when_no_projector_is_active(): void
{
    // the production state until Tasks 21/22: empty tagged set → event still stores, zero projection rows
    $this->untagAllProjectors(); // helper: rebind FiscalEventProjectionRegistry with an empty projector set
    $result = $this->ingest($this->validEnvelope(['sequence_number' => 1]));
    $this->assertTrue($result->stored);
    $this->assertSame(0, \DB::table('fiscal_event_projections')
        ->where('fiscal_event_id', $result->fiscalEventId)->count());
}

public function test_canonical_hash_mismatch_is_quarantined_in_table_projection_proceeds(): void
{
    $env = $this->validEnvelope(['sequence_number' => 1]);
    $env->currentHash = str_repeat('f', 64); // hash != sha256(canonical_bytes)
    $result = $this->ingest($env);
    $row = \DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
    $this->assertSame('quarantined', $row->integrity_status);
    $this->assertSame('canonical_hash_mismatch', $row->integrity_exception_class);
    $this->assertNotNull($row->integrity_exception_reason);
}

public function test_canonical_parse_failure_quarantines_payload_null_projection_suppressed(): void
{
    $env = $this->validEnvelope(['sequence_number' => 1]);
    $env->canonicalBytes = '{"a":1,"a":2}'; $env->currentHash = hash('sha256', $env->canonicalBytes);
    $result = $this->ingest($env);
    $row = \DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
    $this->assertSame('canonical_parse_failure', $row->integrity_exception_class);
    $this->assertNull($row->payload);
    $this->assertSame(0, \DB::table('fiscal_event_projections')->where('fiscal_event_id', $result->fiscalEventId)->count());
}

public function test_idempotent_redelivery_of_same_event_returns_existing_no_redispatch(): void
{
    $env = $this->validEnvelope(['sequence_number' => 1]);
    $first = $this->ingest($env);
    $second = $this->ingest($env);
    $this->assertSame($first->fiscalEventId, $second->fiscalEventId);
    $this->assertFalse($second->stored); // duplicate success — projection NOT re-dispatched
    $this->assertSame(1, \DB::table('fiscal_events')->count());
}

public function test_sequence_conflict_routes_to_quarantine_table_not_idempotent_success(): void
{
    $this->ingest($this->validEnvelope(['sequence_number' => 1]));
    $conflicting = $this->validEnvelope(['sequence_number' => 1]); // DIFFERENT id/hash, same slot
    $result = $this->ingest($conflicting);
    $this->assertTrue($result->sequenceConflict);
    $this->assertFalse($result->stored);
    $q = \DB::table('fiscal_event_quarantine')->where('envelope_event_id', $conflicting->id)->first();
    $this->assertNotNull($q);
    $this->assertSame('sequence_conflict', $q->integrity_exception_class);
    $this->assertNotNull($q->canonical_bytes);
    $this->assertNotNull($q->raw_envelope);
    $this->assertSame(1, \DB::table('fiscal_events')->count()); // conflicting event never entered the ledger
}

public function test_sequence_gap_when_hash_linkage_is_broken(): void
{
    $this->ingest($this->validEnvelope(['sequence_number' => 1]));
    $gapped = $this->validEnvelope(['sequence_number' => 5]); // previous_hash does not link to seq 1's current_hash
    $result = $this->ingest($gapped);
    $row = \DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
    $this->assertSame('sequence_gap', $row->integrity_exception_class);
}

/**
 * The numeric-gap case hash linkage alone would MISS: seq 5 whose previous_hash correctly
 * equals seq 1's current_hash. linkage_ok must check numeric continuity too — not only the hash.
 */
public function test_sequence_gap_when_numeric_gap_despite_valid_hash_linkage(): void
{
    $first = $this->ingest($this->validEnvelope(['sequence_number' => 1]));
    $firstHash = \DB::table('fiscal_events')->where('id', $first->fiscalEventId)->value('current_hash');
    $gapped = $this->validEnvelope(['sequence_number' => 5, 'previous_hash' => $firstHash]); // hash links, numbers don't
    $result = $this->ingest($gapped);
    $row = \DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
    $this->assertSame('sequence_gap', $row->integrity_exception_class); // still a gap — seq 2-4 missing
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/OutboxIngestorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `OutboxIngestor.ingest()`**

Per §7.2:
- **Step 1 — validate against the envelope, before any insert:** `hash_ok = SHA-256(canonical_bytes) == current_hash` (else `canonical_hash_mismatch`); **`linkage_ok` checks both numeric continuity *and* hash linkage** (else `sequence_gap`): let `prior` = the highest-`sequence_number` `fiscal_events` row for `(tenant, terminal)`. For a **non-first** event, `linkage_ok` iff `sequence_number == prior.sequence_number + 1` **AND** `previous_hash == prior.current_hash` — a numeric gap with otherwise-valid hash linkage is **still** `sequence_gap`. For a **first** event (no prior row), `linkage_ok` iff `sequence_number == 1` **AND** `previous_hash == terminal fiscal_event_genesis_seed`. `clock_ok` per §10 (else `time_anomaly`); `parse_result = StrictCanonicalParser::parse()` (else `canonical_parse_failure`). Derive `integrity_status` + `integrity_exception_class` + a **mandatory** structured `integrity_exception_reason`, and `payload`/`payload_parse_status`.
- **Step 2 — atomic insert in transaction T1:** `INSERT INTO fiscal_events (..., server_received_at = now(), integrity_status, payload, ...) VALUES (...) ON CONFLICT (tenant_id, terminal_id, sequence_number) DO NOTHING RETURNING id`.
- **Step 3 — inserted:** within the same T1, insert one `fiscal_event_projections` row (`status='pending'`) per active projector for this event type from `FiscalEventProjectionRegistry::activeProjectorsFor()` — **unless suppressed** (`canonical_parse_failure` → no rows). Commit T1. After commit, enqueue one `ApplyFiscalEventProjectionJob` per pending row (Task 23). Return the stored row.
- **Step 4 — conflict (no row returned):** `SELECT` the existing row at the slot. If `existing.id == envelope.id AND existing.current_hash == envelope.current_hash AND existing.canonical_bytes == envelope.canonical_bytes AND source_event_* IS NOT DISTINCT FROM` → genuine idempotent re-delivery, return existing (do **not** re-dispatch projection). Else → a **different** event at an occupied slot: `INSERT` into `fiscal_event_quarantine` (verbatim typed envelope + canonical_bytes + raw_envelope + the query-critical metadata columns + `integrity_exception_class='sequence_conflict'` + mandatory reason + `conflicting_event_id` = the row holding the slot), raise an admin alert, return a `sequence_conflict` result (**not** idempotent success).
`FiscalEventEnvelope` is the typed transport DTO; `IngestionResult` is `readonly { bool $stored, ?string $fiscalEventId, bool $sequenceConflict, ?IntegrityExceptionClass $exceptionClass }`. Admin alerts: dispatch the existing notification path used by the Compliance module's fraud alerts.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/OutboxIngestorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php apps/api/app/Modules/Fiscal/Application/DTOs/ apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php
git commit -m "feat(fiscal): OutboxIngestor — validate-then-insert with conflict + quarantine handling"
```

---

## Task 20: The single fiscal-event ingestion endpoint

`POST /api/v1/pos/sync/fiscal-events` under the existing `api/v1` POS prefix. Route middleware **must** match the existing POS route surface exactly: `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` — verified against `apps/api/app/Modules/POS/routes.php:30` and `routes_orders.php:15`. The endpoint writes fiscal **chain truth**; a weaker tenant boundary than the rest of POS could accept envelopes under the wrong tenant context and corrupt the chain. Both middleware classes are in `App\Modules\Identity\Presentation\Middleware\`.

**Files:**
- Create: `apps/api/app/Modules/Fiscal/Presentation/Controllers/FiscalEventIngestionController.php`
- Create: `apps/api/app/Modules/Fiscal/Presentation/Requests/IngestFiscalEventsRequest.php`
- Create: `apps/api/app/Modules/Fiscal/routes.php`
- Test: `apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_endpoint_ingests_a_fiscal_event_envelope(): void
{
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->actingAsTerminalUser();
    $response = $this->postJson('/api/v1/pos/sync/fiscal-events', [
        'envelopes' => [$this->validEnvelopeArray(['sequence_number' => 1])],
    ]);
    $response->assertOk();
    $this->assertSame(1, \DB::table('fiscal_events')->count());
}

public function test_endpoint_requires_authentication(): void
{
    $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => []])->assertStatus(401);
}

public function test_endpoint_rejects_a_mismatched_tenant_claim(): void
{
    // EnforceTokenTenantClaim must be on this route — a token whose tenant claim does not match
    // the envelope's tenant cannot ingest fiscal chain truth.
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->actingAsTerminalUserForTenant($tenantA);
    $envForTenantB = $this->validEnvelopeArray(['sequence_number' => 1, 'tenant_id' => $tenantB->id]);
    $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$envForTenantB]])->assertStatus(403);
    $this->assertSame(0, \DB::table('fiscal_events')->count());
}

public function test_endpoint_returns_per_envelope_results_including_sequence_conflict(): void
{
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->actingAsTerminalUser();
    $env = $this->validEnvelopeArray(['sequence_number' => 1]);
    $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$env]]);
    $conflict = $this->validEnvelopeArray(['sequence_number' => 1]); // different id
    $response = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$conflict]]);
    $response->assertOk()->assertJsonPath('results.0.sequence_conflict', true);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php`
Expected: FAIL — route 404.

- [ ] **Step 3: Implement the route + request + controller**

`routes.php`: `Route::middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->prefix('api/v1/pos/sync')->group(fn () => Route::post('fiscal-events', [FiscalEventIngestionController::class, 'store']));` — the same middleware tuple as `apps/api/app/Modules/POS/routes.php`, with both `SetPermissionsTeam` and `EnforceTokenTenantClaim` imported from `App\Modules\Identity\Presentation\Middleware\`. `IngestFiscalEventsRequest` validates `envelopes` as a required array, each carrying `{ envelope_id, type: 'FISCAL_EVENT', payload_version, payload, idempotency_key }` plus the chain fields the ingestor needs. Controller `store()`: for each envelope, build a `FiscalEventEnvelope` DTO and call `OutboxIngestor::ingest()`; collect per-envelope `IngestionResult`s; return `{ results: [...] }` with `stored` / `sequence_conflict` / `fiscal_event_id` per envelope.

**Provider wiring (this task):** edit `FiscalServiceProvider::boot()` to `$this->loadRoutesFrom(__DIR__.'/../routes.php')` (matching `POSServiceProvider::boot()`). Without it the route is undiscoverable and the Step 1 endpoint tests 404 forever. `git add` the provider file in this task's commit.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Fiscal/Presentation/ apps/api/app/Modules/Fiscal/routes.php apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php
git commit -m "feat(fiscal): single fiscal-event ingestion endpoint POST /api/v1/pos/sync/fiscal-events"
```

---

## Task 21: `PosCoreReceiptProjection` (POS module — always runs)

For a `SALE_RECEIPT` fiscal event, creates the POS-core business effects. **Single owner of `ReceiptPayment` row creation regardless of input path.** Its only cross-module dependency is inbound mirrored reference data (`payment_method_id` FK) — permitted.

**Files:**
- Create: `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`
- Test: `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_applies_pos_core_effects_exactly_once(): void
{
    $event = $this->storeSaleReceiptFiscalEvent(); // a verified fiscal_events row, payload parsed
    $projector = app(PosCoreReceiptProjection::class);
    $projector->apply($event);

    $this->assertSame(1, \DB::table('pos_receipts')->where('canonical_bytes', '!=', null)->count());
    $this->assertGreaterThan(0, \DB::table('pos_receipt_lines')->count());
    $this->assertGreaterThan(0, \DB::table('pos_receipt_payments')->count()); // ReceiptPayment rows — POS-core
    // mirror columns populated from the fiscal_events row
    $receipt = \DB::table('pos_receipts')->first();
    $this->assertSame($event->current_hash, $receipt->fiscal_hash);
}

public function test_apply_links_pos_receipt_to_the_fiscal_event(): void
{
    $event = $this->storeSaleReceiptFiscalEvent();
    app(PosCoreReceiptProjection::class)->apply($event);
    // the pos_receipts.fiscal_event_id linkage column (Task 11) is the idempotency anchor
    $this->assertSame($event->id, \DB::table('pos_receipts')->first()->fiscal_event_id);
}

public function test_apply_is_idempotent_via_the_fiscal_event_id_guard(): void
{
    $event = $this->storeSaleReceiptFiscalEvent();
    $projector = app(PosCoreReceiptProjection::class);
    $projector->apply($event);
    $paymentRowsAfterFirst = \DB::table('pos_receipt_payments')->count();
    $projector->apply($event); // second run — guard finds the existing pos_receipts.fiscal_event_id, skips
    $this->assertSame(1, \DB::table('pos_receipts')->count());
    $this->assertSame($paymentRowsAfterFirst, \DB::table('pos_receipt_payments')->count()); // no duplicate payment rows
    $this->assertSame(1, \DB::table('pos_receipt_lines')->count() > 0 ? 1 : 0);
}

public function test_runs_to_completion_with_treasury_inactive(): void
{
    // PosCoreReceiptProjection depends only on mirrored reference data, not the Treasury operational module
    $event = $this->storeSaleReceiptFiscalEvent();
    app(PosCoreReceiptProjection::class)->apply($event);
    $this->assertSame(1, \DB::table('pos_receipts')->count());
    $this->assertSame(0, \DB::table('payments')->count()); // no Treasury Payment rows — that's the bridge's job
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the projector**

Implements `FiscalEventProjector`: `name()='pos_core_receipt'`, `handlesEventType()` true for `SALE_RECEIPT`, `requiresModule()=null`. `apply(FiscalEvent $event)` — **idempotent**: the guard is `if (PosReceipt::where('fiscal_event_id', $event->id)->exists()) return;` — this relies on the `pos_receipts.fiscal_event_id UNIQUE` column added in Task 11, which is the durable linkage anchor. (The `fiscal_event_projections` row tracks job-level status; the `pos_receipts.fiscal_event_id` guard makes `apply()` itself safe to re-run for manual replay.) Relocate the business-effect logic from `ReceiptSyncService` (`:683-710` voucher redemption, `:713-735` stock movement) **and** the `ReceiptPayment::create` logic from `ReceiptPaymentService.php:297` into this projector. Creates: the `pos_receipts` projection row (with `fiscal_event_id = $event->id`, `canonical_bytes`, and the `fiscal_hash`/`previous_hash`/`hash_sequence` mirror columns set from `$event`) + lines + VAT breakdown, `ReceiptPayment` rows (referencing the mirrored `payment_method_id` — permitted inbound dependency), voucher redemption, stock movement. **Zero** dependency on Treasury `Payment` rows / GL / allocation.

**Provider wiring (this task):** edit `POSServiceProvider::register()` to tag `PosCoreReceiptProjection` as a `FiscalEventProjector` (`$this->app->tag([PosCoreReceiptProjection::class], FiscalEventProjector::class)`) — the POS module owns its own projector; the Fiscal module's registry (Task 18) picks it up via `app->tagged(FiscalEventProjector::class)`. `git add` `POSServiceProvider` in this task's commit.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php apps/api/app/Modules/POS/Providers/POSServiceProvider.php apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php
git commit -m "feat(pos): PosCoreReceiptProjection — POS-core SALE_RECEIPT business effects (always runs)"
```

---

## Task 22: `TreasuryReceiptBridge` + the §13 Treasury `Payment` writer inventory

Runs only when the Treasury module is active. Creates **only** the Treasury-operational effects. Also applies the complete §13 writer-update inventory in the same change.

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php`
- Modify: `ReceiptPaymentService` (split — Treasury `Payment` + GL portion moves into the bridge), `Treasury/Presentation/Controllers/PaymentController.php`, `Treasury/Domain/Services/MultiPaymentService.php`, `Treasury/Domain/Services/PaymentRefundService.php`, `Treasury/Domain/Services/VendorRefundService.php`
- Test: `apps/api/tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php`, `apps/api/tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php`

- [ ] **Step 1: Write the failing tests**

```php
// TreasuryReceiptBridgeTest
public function test_bridge_creates_treasury_payment_and_gl_exactly_once(): void
{
    $event = $this->storeSaleReceiptFiscalEvent();
    $bridge = app(TreasuryReceiptBridge::class);
    $bridge->apply($event);
    $payment = \DB::table('payments')->first();
    $this->assertSame('pos', $payment->origin);
    $this->assertSame($event->id, $payment->fiscal_event_id);
    $this->assertGreaterThan(0, \DB::table('general_ledger_entries')->count());
    $bridge->apply($event); // idempotent
    $this->assertSame(1, \DB::table('payments')->count());
}

public function test_requires_module_is_canonical_treasury_token(): void
{
    $this->assertSame('Treasury', app(TreasuryReceiptBridge::class)->requiresModule());
}

// PaymentOriginWriterInventoryTest — one assertion per §13 row
public function test_payment_controller_store_stamps_web_admin(): void { /* ... */ }
public function test_multi_payment_create_split_stamps_web_admin(): void { /* ... */ }
public function test_refund_inherits_original_payment_origin(): void { /* ... */ }
public function test_vendor_refund_prepayment_stamps_web_admin(): void { /* ... */ }
// ... cover every writer in the §13 table
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php`
Expected: FAIL — bridge class not found / writers don't stamp `origin`.

- [ ] **Step 3: Implement the bridge + writer updates**

`TreasuryReceiptBridge` implements `FiscalEventProjector`: `name()='treasury_receipt_bridge'`, `handlesEventType()` true for `SALE_RECEIPT`, `requiresModule()='Treasury'`. `apply(FiscalEvent $event)` — **idempotent**, keyed on `(fiscal_event.id, 'treasury_receipt_bridge')`. Relocate **only** the Treasury `Payment` + GL portion of `ReceiptPaymentService` (`:252` Treasury `Payment::create`, `:269` `GeneralLedgerService::createPOSPaymentEntry()`) into the bridge — **not** the `ReceiptPayment::create` portion (`:297`, which went to `PosCoreReceiptProjection` in Task 21). The bridge stamps each Treasury `Payment` row `origin = 'pos'` (use `PaymentOrigin::Pos`) **and** `fiscal_event_id = $event->id`. §13 writer inventory — stamp `origin` on **every** writer in the §13 table: `PaymentController::store/storeMultiple` → `web_admin`; `MultiPaymentService::createSplitPayment/recordDeposit/recordPaymentOnAccount` → `web_admin`; `PaymentRefundService::refundPayment/partialRefund` + receipt-proration refund rows → inherit the original payment's `origin`; `VendorRefundService::refundPrepayment` → `web_admin`. Non-fiscal web/admin payments leave `fiscal_event_id` NULL. `App\Modules\Billing\Domain\Payment` is **not** in scope.

**Provider wiring (this task):** edit `TreasuryServiceProvider::register()` to tag `TreasuryReceiptBridge` as a `FiscalEventProjector` (`$this->app->tag([TreasuryReceiptBridge::class], FiscalEventProjector::class)`) — the Treasury module owns its own bridge. After this task the registry's tagged set has both projectors; Task 26's end-to-end test (which asserts the registry contains `pos_core_receipt` + `treasury_receipt_bridge`) then passes. `git add` `TreasuryServiceProvider` in this task's commit.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Treasury/ apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php apps/api/tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php apps/api/tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php
git commit -m "feat(treasury): TreasuryReceiptBridge + complete Payment.origin writer inventory (§13)"
```

---

## Task 23: `ApplyFiscalEventProjectionJob` — failure / retry / dead-letter contract

The projection job — Horizon owns transient state (retry/backoff/locking); the `fiscal_event_projections` table holds durable operator-visible state (§7.5).

**Files:**
- Create: `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php`
- Modify: `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php` (enqueue after T1 commit — already stubbed in Task 19; wire it here)
- Test: `apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_successful_projection_marks_applied(): void
{
    [$event, $projectionRow] = $this->pendingProjection('pos_core_receipt');
    (new ApplyFiscalEventProjectionJob($projectionRow->id))->handle();
    $row = \DB::table('fiscal_event_projections')->where('id', $projectionRow->id)->first();
    $this->assertSame('applied', $row->projection_status);
    $this->assertNotNull($row->applied_at);
}

public function test_job_start_sets_running_then_failure_advances_attempts(): void
{
    [$event, $projectionRow] = $this->pendingProjection('treasury_receipt_bridge');
    $this->forceProjectorToThrow('treasury_receipt_bridge');
    try { (new ApplyFiscalEventProjectionJob($projectionRow->id))->handle(); } catch (\Throwable) {}
    $row = \DB::table('fiscal_event_projections')->where('id', $projectionRow->id)->first();
    $this->assertSame(1, $row->attempts);
    $this->assertNotNull($row->last_error);
    $this->assertNotNull($row->last_attempted_at);
}

public function test_exhausted_retries_dead_letter_via_failed_handler(): void
{
    [$event, $projectionRow] = $this->pendingProjection('treasury_receipt_bridge');
    (new ApplyFiscalEventProjectionJob($projectionRow->id))->failed(new \RuntimeException('boom'));
    $row = \DB::table('fiscal_event_projections')->where('id', $projectionRow->id)->first();
    $this->assertSame('dead_lettered', $row->projection_status);
    $this->assertNotNull($row->dead_lettered_at);
}

public function test_projection_failure_never_mutates_the_fiscal_events_row(): void
{
    [$event, $projectionRow] = $this->pendingProjection('treasury_receipt_bridge');
    $before = \DB::table('fiscal_events')->where('id', $event->id)->first();
    $this->forceProjectorToThrow('treasury_receipt_bridge');
    try { (new ApplyFiscalEventProjectionJob($projectionRow->id))->handle(); } catch (\Throwable) {}
    $after = \DB::table('fiscal_events')->where('id', $event->id)->first();
    $this->assertEquals($before, $after);
}

public function test_pos_core_success_with_treasury_dead_letter_leaves_pos_core_intact(): void
{
    $event = $this->storeSaleReceiptFiscalEvent(); // creates pending rows for both projectors
    $this->runProjection($event, 'pos_core_receipt'); // succeeds
    $this->failProjectionToDeadLetter($event, 'treasury_receipt_bridge');
    $this->assertSame(1, \DB::table('pos_receipts')->count()); // POS-core effects intact
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php`
Expected: FAIL — job class not found.

- [ ] **Step 3: Implement the job + wire enqueue**

`ApplyFiscalEventProjectionJob` is a standard queued job with `public int $tries` and `public function backoff(): array` (exponential). Ctor takes the `fiscal_event_projections` row id. `handle()`: load the projection row + the `FiscalEvent`; resolve the named projector from the registry; set `projection_status='running'` at start; run `projector.apply($event)` **in its own transaction**, idempotently; on success → `applied` + `applied_at`; on throw → update `attempts`/`last_error`/`last_attempted_at` and re-throw so Horizon retries. `failed(Throwable $e)`: set `dead_lettered` + `dead_lettered_at`, raise an operator alert, leave the row in the operator-visible dead-letter view. Wire `OutboxIngestor` (§7.2 Step 3): after T1 commits, `ApplyFiscalEventProjectionJob::dispatch($rowId)` per pending row. **Invariant:** a projection failure never mutates/deletes the `fiscal_events` row; a projection is never run inside the ingest transaction.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php
git commit -m "feat(fiscal): ApplyFiscalEventProjectionJob — Horizon-owned retry + dead-letter contract"
```

---

## Task 24: Parse-failure resume contract + `fiscal:enqueue-resolved-event-projections`

The atomic resolution path (§7.5, §15.2) — resolves the write-once-`payload` hazard.

**Files:**
- Create: `apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php`
- Create: `apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php`
- Test: `apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_resolution_writes_payload_flips_status_and_creates_projection_rows_atomically(): void
{
    $event = $this->storeParseFailedFiscalEvent(); // payload NULL, payload_parse_status='failed', quarantined
    app(ParseFailureResolutionService::class)->resolve($event->id, $this->correctedPayload(), $this->resolverUser());
    $row = \DB::table('fiscal_events')->where('id', $event->id)->first();
    $this->assertSame('parsed', $row->payload_parse_status);
    $this->assertNotNull($row->payload);
    $this->assertGreaterThan(0, \DB::table('fiscal_event_projections')
        ->where('fiscal_event_id', $event->id)->where('projection_status', 'pending')->count());
}

public function test_crash_between_commit_and_enqueue_is_recoverable_without_rewriting_payload(): void
{
    $event = $this->storeParseFailedFiscalEvent();
    // simulate: resolution transaction committed (payload written, rows created) but enqueue never ran
    $this->resolveButSkipEnqueue($event->id);
    $this->artisan('fiscal:enqueue-resolved-event-projections', ['--fiscal-event-id' => $event->id])->assertExitCode(0);
    // the pending rows are now enqueued; payload was NOT re-written (write-once trigger would have raised)
    Queue::assertPushed(ApplyFiscalEventProjectionJob::class);
}

public function test_command_is_idempotent_and_safe_to_rerun(): void
{
    $event = $this->storeParseFailedFiscalEvent();
    app(ParseFailureResolutionService::class)->resolve($event->id, $this->correctedPayload(), $this->resolverUser());
    $this->artisan('fiscal:enqueue-resolved-event-projections', ['--fiscal-event-id' => $event->id])->assertExitCode(0);
    $this->artisan('fiscal:enqueue-resolved-event-projections', ['--fiscal-event-id' => $event->id])->assertExitCode(0);
    // no duplicate projection rows; running/applied/dead_lettered rows untouched
    $this->assertSame(
        \DB::table('fiscal_event_projections')->where('fiscal_event_id', $event->id)->count(),
        \DB::table('fiscal_event_projections')->where('fiscal_event_id', $event->id)->distinct('projector_name')->count(),
    );
}

public function test_command_is_permission_gated(): void
{
    $this->artisan('fiscal:enqueue-resolved-event-projections', ['--fiscal-event-id' => 'x'])
        ->assertExitCode(1); // without fiscal.events.resolve_quarantine
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/ParseFailureResumeTest.php`
Expected: FAIL — service/command not found.

- [ ] **Step 3: Implement the resolution service + command**

`ParseFailureResolutionService::resolve()`: in **one transaction** — write `payload`, flip `payload_parse_status → parsed` (and `integrity_status quarantined → verified` with `integrity_resolved_at`/`integrity_resolved_by`), and insert one `pending` `fiscal_event_projections` row per currently-active projector (via the registry + resolver). **After** the transaction commits, call `fiscal:enqueue-resolved-event-projections`. `EnqueueResolvedEventProjectionsCommand` (`{--fiscal-event-id=} {--tenant=}`): for a `fiscal_events` row with `payload_parse_status='parsed'`, (a) create any **missing** `pending` projection rows for currently-active projectors, (b) enqueue every `pending` row with no live queue job. **Idempotent** — never duplicates a row (the `UNIQUE` constraint backs this), never resets/re-enqueues `running`/`applied`/`dead_lettered` rows. Permission-gated by `fiscal.events.resolve_quarantine` (add the permission via the `add-permissions` pattern for the Fiscal module).

**Provider wiring (this task):** edit `FiscalServiceProvider::boot()` to register `EnqueueResolvedEventProjectionsCommand` in the `$this->commands([...])` array (alongside `fiscal:preflight-gate` from Task 1). Without it `$this->artisan('fiscal:enqueue-resolved-event-projections')` in the Step 1 tests is an unknown command. `git add` the provider file in this task's commit.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/ParseFailureResumeTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php
git commit -m "feat(fiscal): atomic parse-failure resolution + fiscal:enqueue-resolved-event-projections"
```

---

## Task 25: Chain-recovery events + clock / time model

`CHAIN_BREAK_DETECTED` / `CHAIN_RESTART` (§9) are ordinary `fiscal_events` rows. The clock model (§10): `event_time_device` untrusted, `sequence_number` authoritative, `business_date` assigned by the terminal-configured fiscal timezone + session boundary.

**Files:**
- Create: `apps/pos/src/lib/fiscal/ChainRecoveryService.ts`
- Create: `apps/api/app/Modules/Fiscal/Application/Services/ClockAnomalyDetector.php`
- Modify: `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php` (use `ClockAnomalyDetector` for the §7.2 `clock_ok` check)
- Test: `apps/pos/src/lib/fiscal/__tests__/ChainRecoveryService.test.ts`, `apps/api/tests/Unit/Fiscal/ClockAnomalyDetectorTest.php`

- [ ] **Step 1: Write the failing tests**

```ts
// ChainRecoveryService.test.ts
it('on a local chain break emits CHAIN_BREAK_DETECTED then CHAIN_RESTART as ordinary fiscal_events', async () => {
  const svc = new ChainRecoveryService(engine, db);
  await svc.recordBreakAndRestart({ reason: 'gap', lastGoodSequence: 4, lastGoodHash: 'a'.repeat(64), offendingRef: 'r-9' });
  const rows = await db.all(`SELECT event_type FROM fiscal_events ORDER BY sequence_number`);
  expect(rows.map((r) => r.event_type)).toEqual(['CHAIN_BREAK_DETECTED', 'CHAIN_RESTART']);
});
it('the broken segment is never deleted', async () => {
  /* assert prior rows still present after recovery */
});
```

```php
// ClockAnomalyDetectorTest.php
public function test_clock_rollback_is_flagged_time_anomaly(): void
{
    $d = new ClockAnomalyDetector();
    $this->assertFalse($d->isWithinTolerance(deviceTime: '2020-01-01T00:00:00Z', lastServerTimeSeen: '2026-05-14T10:00:00Z'));
}
public function test_business_date_uses_terminal_fiscal_timezone_not_server_received_at(): void
{
    $d = new ClockAnomalyDetector();
    // a clock anomaly never moves an event between closure periods
    $this->assertSame('2026-05-14', $d->businessDateFor($eventDeviceTime, $terminalFiscalConfig));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/ChainRecoveryService.test.ts` and `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/ClockAnomalyDetectorTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement both**

`ChainRecoveryService` (device): `recordBreakAndRestart()` appends a `CHAIN_BREAK_DETECTED` event (reason, last-good sequence + hash, offending record reference) then a `CHAIN_RESTART` event (new genesis reference, last-good anchor, operator authorization evidence, provenance link to the prior chain) — both via `FiscalEventEngine.append()`. The terminal continues in a recorded `degraded` mode; the broken segment is never deleted. `ClockAnomalyDetector` (server): `isWithinTolerance()` detects clock rollback / excessive drift relative to `last_server_time_seen` — out-of-tolerance → `time_anomaly` (accepted, not blocked). `businessDateFor()` assigns `business_date` from the terminal-configured fiscal timezone + session boundary — never `server_received_at`, never raw device time; a clock anomaly never moves an event between closure periods without an explicit correction event. Wire `OutboxIngestor`'s `clock_ok` check to `ClockAnomalyDetector`.

- [ ] **Step 4: Run tests to verify they pass**

Run both suites → PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/lib/fiscal/ChainRecoveryService.ts apps/api/app/Modules/Fiscal/Application/Services/ClockAnomalyDetector.php apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php apps/pos/src/lib/fiscal/__tests__/ChainRecoveryService.test.ts apps/api/tests/Unit/Fiscal/ClockAnomalyDetectorTest.php
git commit -m "feat(fiscal): chain-recovery events + clock/time anomaly model"
```

---

## Task 26: `TERMINAL_REGISTRY_SNAPSHOT` (implemented) + `COMPANY_DAY_CLOSURE_MANIFEST` (reserved)

`TERMINAL_REGISTRY_SNAPSHOT` has no closure dependency — implemented in Phase 1. `COMPANY_DAY_CLOSURE_MANIFEST` depends on day-closures — DTO schema + reserved registration only.

> **Provider note:** by the time this task runs, `FiscalServiceProvider` carries everything wired by Tasks **1/6/17/18/20/24** (the `fiscal:preflight-gate` + `fiscal:enqueue-resolved-event-projections` commands, the `FiscalIntegrityProvider` / `ModuleActivationResolver` / `FiscalEventProjectionRegistry` bindings, the route loading), and Tasks **21/22** have tagged the two projectors in their owning modules' providers. **Task 31 has not run yet** — `fiscal:verify-event-chain` is wired later, in Task 31's own task, per the incremental-wiring convention. This task touches the provider **only** to register the new `TerminalRegistrySnapshotService` if it needs a binding; it does **not** register routes or commands belonging to other tasks. The Step 1 test below (`test_fiscal_service_provider_binds_the_seam_interfaces`) is a **verification** that the cumulative wiring available *by Task 26* is correct — it asserts the seam bindings and the two projector tags, **not** `fiscal:verify-event-chain` (Task 31).

**Files:**
- Create: `apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php`
- Modify: `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php` — only if `TerminalRegistrySnapshotService` needs an explicit binding (it is constructor-injectable, so likely no edit).
- Test: `apps/api/tests/Feature/Fiscal/TerminalRegistrySnapshotTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_terminal_registry_snapshot_can_be_emitted(): void
{
    $svc = app(TerminalRegistrySnapshotService::class);
    $event = $svc->emitInitialSnapshot($tenantId, $companyId, $terminalId, $operatorId);
    $this->assertSame('TERMINAL_REGISTRY_SNAPSHOT', $event->event_type->value);
    $this->assertArrayHasKey('terminals', $event->payload);
    $this->assertArrayHasKey('snapshot_hash', $event->payload);
}

public function test_company_day_closure_manifest_throws_not_implemented_on_append(): void
{
    $this->expectException(\App\Modules\Fiscal\Domain\Exceptions\FiscalEventTypeNotImplemented::class);
    app(FiscalEventPayloadRegistry::class)->dtoClassFor(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST);
}

public function test_fiscal_service_provider_binds_the_seam_interfaces(): void
{
    $this->assertInstanceOf(
        \App\Modules\Fiscal\Application\Services\DefaultModuleActivationResolver::class,
        app(\App\Shared\Contracts\Fiscal\ModuleActivationResolver::class),
    );
    // both projectors are registered in the registry
    $registry = app(FiscalEventProjectionRegistry::class);
    $names = array_map(fn ($p) => $p->name(), iterator_to_array($registry->all()));
    $this->assertContains('pos_core_receipt', $names);
    $this->assertContains('treasury_receipt_bridge', $names);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/TerminalRegistrySnapshotTest.php`
Expected: FAIL — service/provider not found.

- [ ] **Step 3: Implement the snapshot service**

`TerminalRegistrySnapshotService::emitInitialSnapshot()` builds a `TerminalRegistrySnapshotPayload` (authoritative terminal list for the company, snapshot hash, prior-snapshot link) and emits it as a `TERMINAL_REGISTRY_SNAPSHOT` fiscal event — emittable at terminal provisioning and on demand. The `FiscalServiceProvider` is already fully wired by the earlier tasks (see the Provider note above) — confirm `app(ModuleActivationResolver::class)`, `app(FiscalIntegrityProvider::class)`, and `app(FiscalEventProjectionRegistry::class)` all resolve and the registry's tagged set contains both projectors; if `TerminalRegistrySnapshotService` needs an explicit binding, add it, otherwise no provider edit. `COMPANY_DAY_CLOSURE_MANIFEST` stays reserved — DTO schema exists, `append()`/`dtoClassFor()` throws `FiscalEventTypeNotImplemented`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/TerminalRegistrySnapshotTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php apps/api/tests/Feature/Fiscal/TerminalRegistrySnapshotTest.php
git commit -m "feat(fiscal): TERMINAL_REGISTRY_SNAPSHOT service + expand FiscalServiceProvider wiring"
```

---

## Task 27: `receiptService.ts` → assembler + `offlineCheckoutService.ts` + `receiptApi.ts` rework

`receiptService.ts` becomes a business-document assembler calling `FiscalEventEngine.append()` inside one SQLite transaction. `executeCheckout()` becomes connectivity-independent — one path, always local authoring; the `onlineCheckout()` server-authoring branch is **discarded**.

**Files:**
- Modify: `apps/pos/src/lib/offline/receiptService.ts`, `apps/pos/src/lib/offline/offlineCheckoutService.ts`, `apps/pos/src/api/receiptApi.ts`
- Test: `apps/pos/src/lib/offline/__tests__/receiptService.test.ts` (rework), `apps/pos/src/lib/offline/__tests__/offlineCheckoutService.test.ts` (rework)

- [ ] **Step 1: Write the failing tests**

```ts
// receiptService.test.ts
it('authors a SALE_RECEIPT via FiscalEventEngine.append inside one SQLite transaction with the projection write', async () => {
  const receipt = await receiptService.completeSale(saleInput());
  const events = await db.all(`SELECT * FROM fiscal_events WHERE event_type='SALE_RECEIPT'`);
  expect(events).toHaveLength(1);
  const offline = await db.all(`SELECT * FROM offline_receipts WHERE id=?`, [events[0].reference_document_id]);
  expect(offline).toHaveLength(1);
  expect(offline[0].canonical_bytes).toBe(events[0].canonical_bytes); // mirror
  // one chain, one chain head — receiptService no longer computes its own hash
  expect(receiptService).not.toHaveProperty('computeReceiptHash');
});

// offlineCheckoutService.test.ts
it('executeCheckout when ONLINE does not call either server-authoring method — authors locally', async () => {
  setConnectivity('online');
  const createSpy = vi.spyOn(receiptApi, 'createReceipt');
  const paySpy = vi.spyOn(receiptApi, 'processReceiptPayments');
  await executeCheckout(checkoutInput());
  expect(createSpy).not.toHaveBeenCalled();
  expect(paySpy).not.toHaveBeenCalled();          // both server-authoring callers — neither fires
  expect(await db.get(`SELECT COUNT(*) c FROM fiscal_events`)).toMatchObject({ c: 1 });
});
it('executeCheckout authors identically when OFFLINE', async () => {
  setConnectivity('offline');
  await executeCheckout(checkoutInput());
  expect(await db.get(`SELECT COUNT(*) c FROM fiscal_events`)).toMatchObject({ c: 1 });
});
// Source-level guard — NOT a namespace-property check (onlineCheckout is a non-exported local
// fn today, so `.onlineCheckout === undefined` passes before any change — tautological).
// This reads the file and fails today because the server-authoring imports are still present.
it('offlineCheckoutService.ts no longer imports the server-authoring receipt methods', () => {
  const src = readFileSync(resolve(__dirname, '../offlineCheckoutService.ts'), 'utf8');
  expect(src).not.toMatch(/createReceipt|processReceiptPayments/);
  expect(src).not.toMatch(/\bonlineCheckout\b/); // the branch and its helper are gone
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/pos && pnpm vitest run src/lib/offline/__tests__/receiptService.test.ts src/lib/offline/__tests__/offlineCheckoutService.test.ts`
Expected: FAIL — old behavior still present.

- [ ] **Step 3: Implement the rework**

`receiptService.ts`: keep the business-assembly logic (lines, totals, vouchers, payment lines); replace its independent hash/seal/chain code with — open one SQLite transaction, build the `SALE_RECEIPT` payload, call `FiscalEventEngine.append({ type: SALE_RECEIPT, reference_document_id: <offline_receipts row id>, payload })`, write the `offline_receipts` projection row (with `canonical_bytes` mirroring the `fiscal_events` row), update voucher balances — **all in that one transaction** — commit. Schedule a debounced sync flush post-commit. `offlineCheckoutService.ts`: delete `onlineCheckout()`; `executeCheckout()` has one path — always author locally via the `receiptService.ts` assembler; connectivity only decides whether an immediate sync flush follows. `receiptApi.ts`: **discard** `createReceipt()` (`POST /pos/receipts`) and `processReceiptPayments()` (`POST /pos/receipts/{id}/payments`) — the new-sale server-authoring callers; **keep** `fetchReceipt()` and the other read methods.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/pos && pnpm vitest run src/lib/offline/__tests__/receiptService.test.ts src/lib/offline/__tests__/offlineCheckoutService.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/lib/offline/receiptService.ts apps/pos/src/lib/offline/offlineCheckoutService.ts apps/pos/src/api/receiptApi.ts apps/pos/src/lib/offline/__tests__/
git commit -m "feat(pos): receiptService becomes assembler; executeCheckout is connectivity-independent"
```

---

## Task 28: `syncService.ts` fiscal-event push + `/pos/receipts/sync` retirement (§14.1)

**Precondition:** Task 1 signed off.

**Files:**
- Modify: `apps/pos/src/lib/sync/syncService.ts`, `apps/pos/src/lib/fetchWithTimeout.ts`
- Modify/Delete: backend `apps/api/app/Modules/POS/routes.php` (the `/pos/receipts/sync` route), `SyncController`, `SyncReceiptPayload`, `SyncReceiptsRequest`, `SyncReceiptResult`, the receipt-sync entry surface of `ReceiptSyncService`
- Migrate: the §14.1 test suites
- Test: `apps/pos/src/lib/sync/__tests__/syncService.test.ts` (rework), backend feature-suite migrations

- [ ] **Step 1: Write the failing test**

```ts
// syncService.test.ts
it('pushes fiscal events to POST /api/v1/pos/sync/fiscal-events (not /pos/receipts/sync)', async () => {
  const fetchSpy = mockFetch();
  await syncService.flushFiscalEvents();
  expect(fetchSpy).toHaveBeenCalledWith(expect.stringContaining('/api/v1/pos/sync/fiscal-events'), expect.anything());
  expect(fetchSpy).not.toHaveBeenCalledWith(expect.stringContaining('/pos/receipts/sync'), expect.anything());
});
it('pushOfflineReceipts is removed', () => {
  expect((syncService as Record<string, unknown>).pushOfflineReceipts).toBeUndefined();
});
```

Backend: a feature test asserting `POST /pos/receipts/sync` returns 404 (or 410) and that the migrated suites (`SyncReceiptsTest`, `SyncReceiptsRequestTest`, `ReceiptSyncServiceV3Test`, `ReceiptSyncServiceTrainingModeTest`, `OfflineV3CutoverSyncTest`, `ReceiptSyncServiceInstrumentGuardTest`, `ReceiptSyncServiceVoucherRedemptionTest`) now exercise the fiscal-event ingestion path.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/pos && pnpm vitest run src/lib/sync/__tests__/syncService.test.ts`
Expected: FAIL — old path still used.

- [ ] **Step 3: Implement the retirement**

**POS client:** replace `pushOfflineReceipts` / `receiptToPayload` (`syncService.ts:295,333-336,1767`) with a fiscal-event envelope push to `POST /api/v1/pos/sync/fiscal-events`. Migrate `syncService.test.ts:293,800` and the integration tests `apps/pos/src/__tests__/integration/offlineFirstFlow.test.ts:110,279,340` to fiscal-event ingestion tests. Update `fetchWithTimeout.ts:30` + `fetchWithTimeout.test.ts:51,61` (the timeout helper/fixtures encoding the old path).
**Backend:** remove/hard-disable the `/pos/receipts/sync` route (`routes.php:85`) + `SyncController::syncReceipts` (`:49-68`). Retire/repoint the receipt-only old-sync classes: `SyncReceiptPayload`, `SyncReceiptsRequest`, `SyncReceiptResult`, the receipt-sync entry surface of `ReceiptSyncService`. Retire any `SyncStatus` values that exist **only** for receipt-sync semantics (e.g. the `SyncReceiptResult` `ChainBroken` factory path) — keep `SyncStatus` values still used by other sync resources. Migrate the §14.1 feature suites to fiscal-event ingestion tests. For `PosStabilizationTenantIsolationTest.php` (a broader stabilization suite), migrate **only** its receipt-sync assertions (`:1366,1406`) — do not delete the suite.
**Verification:** a repo-wide grep for `/pos/receipts/sync`, `pushOfflineReceipts`, `syncReceipts`, `SyncReceiptPayload`, `SyncReceiptsRequest`, `SyncReceiptResult` returns only the new fiscal-event path or intentional historical references.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/pos && pnpm vitest run src/lib/sync/` and `cd apps/api && ./vendor/bin/phpunit --filter "SyncReceipts|ReceiptSyncService|OfflineV3Cutover|PosStabilizationTenantIsolation"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/lib/sync/ apps/pos/src/lib/fetchWithTimeout.ts apps/pos/src/__tests__/integration/ apps/api/app/Modules/POS/routes.php apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php apps/api/app/Modules/POS/Application/ apps/api/tests/
git commit -m "refactor(pos): retire /pos/receipts/sync — device posts to the single fiscal-event endpoint"
```

---

## Task 29: New-sale server-authoring disposition (web POS + Tauri-online) — §14.2

**Precondition:** Task 1 signed off; the §2 web-POS disposition decision recorded.

**Files:**
- Modify (web): `apps/web/src/features/pos/pages/POSTransactions.tsx`, `apps/web/src/features/pos/api/receiptApi.ts`, `apps/web/src/features/pos/components/CloseOrderButton.tsx`, `OrderPanel.tsx`, `apps/web/src/features/pos/hooks/useOrders.ts`, `apps/web/src/features/pos/api/orderApi.ts`
- Modify (backend): `apps/api/app/Modules/POS/routes.php` (`:98,102`), `apps/api/app/Modules/POS/routes_orders.php` (`:28`), `ReceiptController::store/storePayments`, the order-close controller chain
- Test: `apps/api/tests/Feature/Fiscal/NewSaleServerAuthoringDispositionTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_post_pos_receipts_is_rejected_for_new_sale_authoring(): void
{
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->actingAsTerminalUser();
    $this->postJson('/api/v1/pos/receipts', $this->newSalePayload())->assertStatus(410); // retired/rejected
}

public function test_post_pos_orders_close_no_longer_authors_a_sale_receipt(): void
{
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->actingAsTerminalUser();
    $order = $this->seedOpenOrder();
    $this->postJson("/api/v1/pos/orders/{$order->id}/close")->assertStatus(410);
    $this->assertSame(0, \DB::table('pos_receipts')->count()); // no SALE_RECEIPT authored server-side
}

public function test_void_and_processReturn_are_NOT_disabled_in_phase_1(): void
{
    // knowingly-retained carve-out — these routes still respond
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->actingAsTerminalUser();
    $receipt = $this->seedReceipt();
    $this->postJson("/api/v1/pos/receipts/{$receipt->id}/void", $this->voidPayload())->assertSuccessful();
}

public function test_read_only_receipt_routes_still_function(): void
{
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->actingAsTerminalUser();
    $receipt = $this->seedReceipt();
    $this->getJson("/api/v1/pos/receipts/{$receipt->id}")->assertOk();          // search/lookup
    $this->getJson("/api/v1/pos/receipts/{$receipt->id}/pdf")->assertOk();      // PDF/download
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/NewSaleServerAuthoringDispositionTest.php`
Expected: FAIL — routes still author.

- [ ] **Step 3: Implement the disposition**

**Web frontend (`apps/web`):** disable/hide the sale-completion flow in `POSTransactions.tsx` (the web-terminal resolution `:111-124`, `createReceipt` call sites `:313-318,366-368`, `processReceiptPayments`/payment call sites `:318-329,396`) and the `receiptApi.ts` write methods (`createReceipt` `:73-77`, the payment post `:121-128`). Disable/hide the order-close flow — `CloseOrderButton.tsx`, `OrderPanel.tsx` (`closeOrder.mutate`), `useOrders.ts` (`useCloseOrder`), `orderApi.ts:201` (`closeOrder` → `POST /pos/orders/{id}/close`).
**Backend (`apps/api`):** retire/reject the new-sale-authoring routes — `POST /pos/receipts`, `POST /pos/receipts/{id}/payments` (`routes.php:98,102`) and the `ReceiptController::store()` → `createReceipt()` / `ReceiptController::storePayments()` → `ReceiptPaymentService` paths — for **all** callers (web *and* Tauri-online). Retire/reject the order-close → receipt path — `POST /pos/orders/{id}/close` (`routes_orders.php:28`) → `OrderController::close` → `OrderManagementService::closeOrder` → `OrderToReceiptService::convertToReceipt` → `ReceiptCreationService::createReceipt()` — the **order-close step that authors a `SALE_RECEIPT`** is disabled; the rest of order CRUD/lines/kitchen routes in `routes_orders.php` are untouched. Confirm `routes.php:45` scope when implementing — disable only if it is part of the new-sale write surface.
**Knowingly retained — do NOT disable:** `void` (`POST /pos/receipts/{id}/void` → `ReceiptController::void` → `ReceiptVoidService`) and `processReturn` (`POST /pos/receipts/{id}/return` → `ReceiptController::processReturn`) — their event types (`SALE_VOID`, `REFUND_RECEIPT`, `PARTIAL_REFUND`) are Phase 2+ reserved; both routes are shared with the online Tauri POS (`VoidReturnModal.tsx`) — disabling them would break online void/return for the offline client.
**Preserved (read-only):** receipt search, receipt PDF/download, the web shop-management (POS) section that lists receipts/transactions.
Record the disposition outcome in the §2 preflight sign-off artifact.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/NewSaleServerAuthoringDispositionTest.php` and `cd apps/web && pnpm typecheck`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/pos/ apps/api/app/Modules/POS/routes.php apps/api/app/Modules/POS/routes_orders.php apps/api/app/Modules/POS/Presentation/Controllers/ apps/api/tests/Feature/Fiscal/NewSaleServerAuthoringDispositionTest.php apps/api/docs/sessions/2026-05-14-fiscal-preflight-signoff.md
git commit -m "refactor(pos): disposition web-POS + Tauri-online + order-close new-sale server authoring (§14.2)"
```

---

## Task 30: §14.3 two-chokepoint CI grep gate + `ReceiptHashService` / `Nf525DataProvider` rework

The §14.3 completeness rule is a **CI gate** — not a one-time check. `ReceiptHashService` / `Nf525DataProvider` rework from recompute-from-models → re-hash stored `canonical_bytes`.

**Files:**
- Create: `apps/api/scripts/check-saleReceipt-chokepoints.sh`
- Create: `apps/api/scripts/saleReceipt-chokepoint-manifest.json` — the checked-in disposition manifest (the load-bearing artifact; the gate compares grep hits against it)
- Modify: `apps/api/scripts/preflight.sh` (or `scripts/preflight.sh`) + the CI workflow — wire the grep gate
- Modify: `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php`, `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php`
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php` (verify path stays; new-sale seal path discarded), `ExchangeService.php` (re-grep for a live entrypoint — disposition a/b/c or remove/inert per §14.3)
- Test: `apps/api/tests/Feature/Fiscal/ChokepointCompletenessTest.php`, `apps/api/tests/Feature/Fiscal/ReceiptChainRebuildTest.php`

**The matching strategy (must be explicit — a naive grep is brittle).** Callers invoke the chokepoints through injected services as `->createReceipt(` / `->finalize(`, not class-qualified. The gate is **not** a pure regex pass/fail — it is a *reconciliation against a manifest*:
1. `rg -n '\->createReceipt\(' apps/api/app apps/api/routes` and `rg -n '\->finalize\(' apps/api/app apps/api/routes` collect **every** call-site hit (file:line).
2. Each hit must appear in `saleReceipt-chokepoint-manifest.json` as an entry: `{ file, line_anchor, calling_class, calling_method, receiver_type, chokepoint | "unrelated", disposition }`. `receiver_type` resolves the ambiguity — a `->finalize(` on an `InventoryCountingService` receiver is `"unrelated"` and explicitly allowlisted; a `->finalize(` on a `ReceiptFinalizationService` receiver is a real chokepoint caller and must carry a disposition `(a)`/`(b)`/`(c)`.
3. The gate **fails** if: a grep hit has no manifest entry (an undispositioned caller — the v5/v6 defect class), OR — after Phase 1 — any manifest entry with a real chokepoint and a disposition other than `(c)` is still `live`.

- [ ] **Step 1: Write the failing tests**

```php
// ChokepointCompletenessTest.php — the test mirrors the shell gate so the contract is enforced two ways.
public function test_every_createReceipt_callsite_is_reconciled_in_the_manifest(): void
{
    // rg '->createReceipt(' across apps/api/app + apps/api/routes; each file:line must map to a manifest entry
    foreach ($this->callSites('->createReceipt(') as $site) {
        $entry = $this->manifestEntryFor($site);
        $this->assertNotNull($entry, "Unreconciled createReceipt call site: {$site}");
        if ($entry['chokepoint'] !== 'unrelated') {
            $this->assertContains($entry['disposition'], ['a', 'b', 'c'], "Chokepoint caller {$site} has no disposition");
        }
    }
}
public function test_every_finalize_callsite_is_reconciled_with_receiver_type(): void
{
    foreach ($this->callSites('->finalize(') as $site) {
        $entry = $this->manifestEntryFor($site);
        $this->assertNotNull($entry, "Unreconciled finalize call site: {$site}");
        // a finalize() on a non-ReceiptFinalizationService receiver must be explicitly marked 'unrelated'
        // (e.g. InventoryCountingService) — not silently passed
        $this->assertArrayHasKey('receiver_type', $entry);
    }
}
public function test_after_phase1_only_void_return_carveout_chokepoint_callers_remain_live(): void
{
    foreach ($this->manifestEntries() as $entry) {
        if ($entry['chokepoint'] !== 'unrelated' && ($entry['live'] ?? false)) {
            $this->assertSame('c', $entry['disposition'], "Non-carve-out chokepoint caller still live: {$entry['file']}");
            $this->assertStringContainsString('ReceiptReturnService', $entry['calling_class']);
        }
    }
}
```

```php
// ReceiptChainRebuildTest.php
public function test_verifyTerminalChain_rehashes_stored_canonical_bytes_no_recompute_from_models(): void
{
    $event = $this->storeSaleReceiptFiscalEvent();
    $this->assertTrue(app(ReceiptHashService::class)->verifyTerminalChain($event->terminal_id));
}
public function test_nf525_data_provider_reads_verified_canonical_bytes_and_includes_quarantine_state(): void
{
    $this->storeSaleReceiptFiscalEvent();
    $this->storeQuarantinedFiscalEvent();
    $export = app(Nf525DataProvider::class)->buildExport($tenantId, $companyId);
    $this->assertArrayHasKey('quarantine_section', $export);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/ChokepointCompletenessTest.php tests/Feature/Fiscal/ReceiptChainRebuildTest.php`
Expected: FAIL — gate/rework not present.

- [ ] **Step 3: Implement the gate + rework**

`saleReceipt-chokepoint-manifest.json`: seed it from the §14.3 table — one entry per call site of `->createReceipt(` and `->finalize(` under `apps/api/app` + `apps/api/routes`, each with `file`, `line_anchor` (a stable surrounding-code anchor, not a bare line number — line numbers drift), `calling_class`, `calling_method`, `receiver_type`, `chokepoint` (`createReceipt` | `finalize` | `unrelated`), `disposition` (`a`/`b`/`c` for real chokepoints, omitted for `unrelated`), and `live` (bool). The §14.3-enumerated callers are the starting set; the implementation **re-greps** and reconciles (line numbers will have drifted). `check-saleReceipt-chokepoints.sh`: run the two `rg` passes, resolve each hit's `receiver_type` (the script greps the enclosing class's constructor/property type for the receiver variable — enough type-awareness for this codebase), reconcile against the manifest, and **exit non-zero** if any hit is unreconciled or — with `--phase1-complete` — if any real-chokepoint entry with `disposition != "c"` is still `live`. Explicit allowlist: `finalize()` on an `InventoryCountingService` receiver is `"unrelated"`. Wire it into `scripts/preflight.sh` and the CI workflow. **Rework:** `ReceiptHashService::verifyTerminalChain()` / `verifyHash()` — from recompute-from-models → re-hash the stored `canonical_bytes`; verification walks the `fiscal_events` chain (read-only — stays). `ReceiptFinalizationService::finalize()` — for new-sale `SALE_RECEIPT` it is **not invoked server-side** post-rebuild (the device seals); the void/return callers (`ReceiptReturnService`) are the knowingly-retained `(c)` carve-out. `Nf525DataProvider` — reads verified `canonical_bytes` + quarantine state from `fiscal_events` **and** `fiscal_event_quarantine` (reconciliation spans both surfaces); JET export gains the quarantine section. `ExchangeService` — re-grep for a live route/caller: if one exists, disposition it (a/b/c) in the manifest; if confirmed dead, remove it or leave it inert with a manifest entry marked `live: false`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/ChokepointCompletenessTest.php tests/Feature/Fiscal/ReceiptChainRebuildTest.php && bash scripts/check-saleReceipt-chokepoints.sh`
Expected: PASS; the grep gate exits 0 with all callers dispositioned.

- [ ] **Step 5: Commit**

```bash
git add apps/api/scripts/check-saleReceipt-chokepoints.sh apps/api/scripts/saleReceipt-chokepoint-manifest.json apps/api/scripts/preflight.sh .github/workflows/ apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php apps/api/app/Modules/POS/Application/Services/ExchangeService.php apps/api/tests/Feature/Fiscal/
git commit -m "feat(fiscal): §14.3 chokepoint CI gate + manifest + ReceiptHashService/Nf525DataProvider rebuild"
```

---

## Task 31: `fiscal:verify-event-chain` command

**Files:**
- Create: `apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php`
- Create: CI fixtures — a valid seeded chain, a tampered fixture, a `sequence_conflict` fixture
- Test: `apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_passes_on_a_valid_seeded_chain(): void
{
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    [$tenant, $terminal] = $this->seedValidChain(5);
    $this->artisan('fiscal:verify-event-chain', ['--tenant' => $tenant, '--terminal' => $terminal])
        ->assertExitCode(0);
}

public function test_fails_with_break_point_on_a_tampered_fixture(): void
{
    [$tenant, $terminal] = $this->seedTamperedChain(atSequence: 3);
    $this->artisan('fiscal:verify-event-chain', ['--tenant' => $tenant, '--terminal' => $terminal])
        ->expectsOutputToContain('sequence_number 3')
        ->assertExitCode(1);
}

public function test_fails_as_incident_on_a_seeded_sequence_conflict(): void
{
    [$tenant, $terminal] = $this->seedChainWithQuarantineConflict();
    $this->artisan('fiscal:verify-event-chain', ['--tenant' => $tenant, '--terminal' => $terminal])
        ->expectsOutputToContain('sequence_conflict')
        ->assertExitCode(1);
}

public function test_command_is_permission_gated(): void
{
    $this->artisan('fiscal:verify-event-chain')->assertExitCode(1); // without fiscal.events.verify_chain
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/VerifyEventChainCommandTest.php`
Expected: FAIL — command not registered.

- [ ] **Step 3: Implement the command**

`fiscal:verify-event-chain {--tenant=} {--terminal=} {--from-sequence=}`: walk `fiscal_events` for the terminal in `sequence_number` order; re-hash the stored `canonical_bytes` (via `HashChainIntegrityProvider`); assert `current_hash` matches; assert `previous_hash` links to the prior event's `current_hash` (first event → terminal `fiscal_event_genesis_seed`). Also report any `fiscal_event_quarantine` rows for the terminal as chain incidents using `claimed_sequence_number`, `envelope_event_id`, `current_hash`, `conflicting_event_id`. Exit `0` = chain verified, no incidents; non-zero = a break or a quarantine incident, with the `sequence_number` and expected-vs-actual hash on stderr. Permission-gated by `fiscal.events.verify_chain`. Add it as a CI gate alongside the fixtures.

**Provider wiring (this task):** edit `FiscalServiceProvider::boot()` to register `VerifyEventChainCommand` in the `$this->commands([...])` array (alongside `fiscal:preflight-gate` from Task 1 and `fiscal:enqueue-resolved-event-projections` from Task 24). Without it the Step 1 tests hit an unknown command. `git add` the provider file in this task's commit.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/VerifyEventChainCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php apps/api/tests/Fixtures/Fiscal/
git commit -m "feat(fiscal): fiscal:verify-event-chain command + CI fixtures"
```

---

## Task 32: Off-device durability controls (§12)

A Phase 1 gate before any Phase 2 customer-facing deployment. Device authority is not survivable without off-device conservation.

**Files:**
- Create: `apps/pos/src/lib/fiscal/OffDeviceDurabilityService.ts`
- Create: `apps/pos/src/components/fiscal/UnsyncedRiskIndicator.tsx`
- Create: `apps/api/app/Modules/Fiscal/Domain/Models/DeviceLossIncident.php` + a migration `2026_05_14_100007_create_device_loss_incidents_table.php`
- Test: `apps/pos/src/lib/fiscal/__tests__/OffDeviceDurabilityService.test.ts`, `apps/api/tests/Feature/Fiscal/DeviceLossIncidentTest.php`

- [ ] **Step 1: Write the failing tests**

```ts
// OffDeviceDurabilityService.test.ts
it('exposes at least one off-device durability path with key custody outside the terminal disk', () => {
  const svc = new OffDeviceDurabilityService(config);
  expect(svc.availablePaths().length).toBeGreaterThan(0);
  expect(svc.keyCustody()).not.toBe('on-device'); // not the plaintext .izipos_key
});
it('reports an unsynced-risk level and triggers a forced archive at the threshold', async () => {
  const svc = new OffDeviceDurabilityService(config);
  await seedUnsyncedEvents(50);
  expect(svc.unsyncedRisk()).toBe('elevated');
  expect(await svc.shouldForceArchive()).toBe(true);
});
it('escalates at the maximum-unsynced threshold', async () => {
  await seedUnsyncedEvents(500);
  expect(new OffDeviceDurabilityService(config).unsyncedRisk()).toBe('escalated');
});
```

```php
// DeviceLossIncidentTest.php
public function test_device_loss_incident_can_be_registered(): void
{
    $incident = \App\Modules\Fiscal\Domain\Models\DeviceLossIncident::create($this->incidentAttributes());
    $this->assertDatabaseHas('device_loss_incidents', ['id' => $incident->id]);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/OffDeviceDurabilityService.test.ts` and `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/DeviceLossIncidentTest.php`
Expected: FAIL — classes/table not found.

- [ ] **Step 3: Implement the durability controls**

`OffDeviceDurabilityService`: at least one off-device durability path (encrypted removable archive / LAN peer / NAS / cloud-sync) with key custody **outside** the terminal disk (not the plaintext `.izipos_key`); the on-device AES-GCM copy is crash-recovery only, not a conservation control. `unsyncedRisk()` returns a level from the count/age of unsynced `fiscal_events` rows; `shouldForceArchive()` triggers at a forced-archive threshold; an escalation level at a maximum-unsynced threshold. `UnsyncedRiskIndicator.tsx` is the operator-visible indicator (translation keys via `t()`, design tokens). `DeviceLossIncident` model + `device_loss_incidents` table is the device-loss incident register. Document that §12 is a **Phase 1 gate before any Phase 2 customer-facing deployment**.

- [ ] **Step 4: Run tests to verify they pass**

Run both suites → PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/lib/fiscal/OffDeviceDurabilityService.ts apps/pos/src/components/fiscal/UnsyncedRiskIndicator.tsx apps/api/app/Modules/Fiscal/Domain/Models/DeviceLossIncident.php apps/api/database/migrations/2026_05_14_100007_create_device_loss_incidents_table.php apps/pos/src/lib/fiscal/__tests__/OffDeviceDurabilityService.test.ts apps/api/tests/Feature/Fiscal/DeviceLossIncidentTest.php
git commit -m "feat(fiscal): off-device durability controls + device-loss incident register (§12)"
```

---

## Task 33: Full-flow verification + roadmap status update

- [ ] **Step 1: Run the full preflight suite**

Run: `./scripts/preflight.sh` (PHPStan level 8, Pint, PHPUnit, TypeScript check, ESLint) + `cd apps/pos && pnpm test && pnpm typecheck`.
Expected: all green. The §14.3 chokepoint grep gate (`check-saleReceipt-chokepoints.sh`) exits 0.

- [ ] **Step 2: End-to-end critical-path verification**

On a test terminal (or the device test harness): complete a sale → assert a `SALE_RECEIPT` `fiscal_events` row is authored locally inside one SQLite transaction → flush sync → assert `OutboxIngestor` stores it verified → assert `PosCoreReceiptProjection` creates `pos_receipts` + lines + VAT + `ReceiptPayment` + voucher redemption + stock movement, and `TreasuryReceiptBridge` creates the Treasury `Payment` (`origin='pos'`, `fiscal_event_id` set) + GL. Run `fiscal:verify-event-chain` on the terminal → exit 0.

- [ ] **Step 3: Update roadmap v2 phase status**

Modify `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md` — mark Phase 1 status (in progress → complete once merged). Note any §18 open items confirmed still open (`payment_methods` ownership, `ModuleActivationResolver` production scope, web-POS device-authority parity, Z-report chain coordination, `pos_receipts` constraint confirmation).

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md
git commit -m "docs: mark roadmap v2 Phase 1 status; confirm §18 open items"
```

- [ ] **Step 5: Finish the branch**

Use the superpowers:finishing-a-development-branch skill to decide merge / PR / cleanup.

---

## Self-Review

**1. Spec coverage** — every §:
- §2 preflight gate → Task 1. §3 `fiscal_events` table + triggers → Tasks 7, 8 (server), Task 13 (device). §4 canonical serialization → Tasks 4, 5. §5.0 central integration / bounded-modules seam → Tasks 17, 18, 27. §5.1 `HashChainIntegrityProvider` → Task 6. §5.2 `SignatureProviderInterface` → Task 6. §6 device engine → Tasks 13, 15. §7.1–§7.2 ingestion endpoint + `OutboxIngestor` → Tasks 19, 20. §7.3 projection seam → Tasks 17, 18. §7.4 projectors → Tasks 21, 22. §7.5 failure/retry/resume → Tasks 23, 24. §7.6 strict parser → Task 16. §8 integrity exceptions + quarantine → Tasks 10, 19. §9 chain-recovery events → Task 25. §10 clock/time model → Task 25. §11 company integrity record types → Task 26. §12 off-device durability → Task 32. §13 `Payment.origin`/`fiscal_event_id` + writer inventory → Tasks 12, 22. §14 receipt-chain rebuild dispositions → Tasks 21, 22, 27, 30. §14.1 `/pos/receipts/sync` retirement → Task 28. §14.2 new-sale disposition → Task 29. §14.3 two-chokepoint rule → Task 30. §15.1 `fiscal:verify-event-chain` → Task 31. §15.2 `fiscal:enqueue-resolved-event-projections` → Task 24. §16 migration plan → Tasks 7–13, 32 (ordered, preflight first). §17 testing strategy → every task is TDD; §17.5 CI grep → Task 30. Appendix A → Task 2.
- **Carry-forward from the v7 Codex review (out-of-scope note):** the §17.5 CI grep / tests must not leave a training-mode server-authoring exception for rebuilt `SALE_RECEIPT` (`ReceiptCreationService.php:605-621`, `ReceiptSyncService.php:618-623`). **Addressed in Task 30:** the chokepoint grep gate enumerates **every** production caller of both chokepoints with **no training-mode exception** — the disposition manifest lists only the **(c)** `ReceiptReturnService` void/return carve-out as a permitted post-Phase-1 caller; a training-mode branch that reaches `createReceipt()`/`finalize()` for `SALE_RECEIPT` fails the gate. Task 28 also retires `ReceiptSyncServiceTrainingModeTest.php` with the rest of the `/pos/receipts/sync` surface, removing the training-mode receipt-sync authoring path entirely.

**2. Placeholder scan** — no "TBD"/"add error handling"/"similar to Task N". Each step shows real test code and concrete implementation guidance with exact file paths and line references from the spec. Where a step says "locate via grep" (device-migration runner, Treasury `Payment` model path) it is because the spec did not pin the path and the implementer must confirm against live code — the spec itself flags line-number drift in §14.3.

**3. Type/name consistency** — frozen names declared in "Conventions" and used identically across tasks: `OutboxIngestor`, `FiscalEventProjectionRegistry`, `ModuleActivationResolver`/`DefaultModuleActivationResolver`, `FiscalEventProjector`, `PosCoreReceiptProjection` (`pos_core_receipt`), `TreasuryReceiptBridge` (`treasury_receipt_bridge`), `FiscalEventEngine.append()`, `FiscalEventCanonicalEncoder`, `HashChainIntegrityProvider` (PHP+TS), `FiscalEventPayloadRegistry`, `ApplyFiscalEventProjectionJob`, `ParseFailureResolutionService`, the two commands, the `'Treasury'` canonical token, the `fiscal.events.verify_chain` / `fiscal.events.resolve_quarantine` permissions, `POST /api/v1/pos/sync/fiscal-events`. Projector names match between Task 18's registry test, Task 21, Task 22, Task 23, and Task 30's disposition manifest.
