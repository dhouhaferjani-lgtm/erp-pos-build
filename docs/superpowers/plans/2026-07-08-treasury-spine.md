# Treasury Money-Movement Spine — Implementation Plan (Phase 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Rev 2 (2026-07-08):** reconciled against the Codex plan review — `reviews/2026-07-08-treasury-spine-plan-codex-review.md` (2 BLOCKER / 6 HIGH / 5 MED / 2 LOW, all accepted). Key structural changes: a **global lock-order invariant** (below), a `PostingMode` seam on GL helpers (Task 6), explicit `allowWhileFrozen` on `MovementIntent` (Tasks 11/20/21), request-level idempotency keys (Task 16b/18), and Task 19 rewritten from real code.

## Global lock order (BLOCKER-1 — every flow MUST follow this)

To prevent cross-flow deadlock between the GL per-company advisory lock (Task 7) and the `payment_repositories` row lock (Task 11), **every** converged flow acquires locks in this order, top to bottom:

1. **GL company advisory lock** — `pg_advisory_xact_lock(hashtextextended(company_id::text, 0))`, taken inside `postEntryNow` / `sealAndPersistEntry` (Task 7).
2. **`payment_repositories` row lock(s)** — `lockForUpdate`, taken inside `TreasuryMovementService::record()` / `transfer()` (Task 11/12); transfers lock **both** repos sorted by id.

Concretely: **post the GL entry (advisory-locked) BEFORE calling `record()` (repo-locked).** No flow may lock a repository row and then post GL. This is why Task 16 reorders `PaymentController` (which today locks the repo before GL) and why `transfer()` takes the company advisory lock before its sorted repo locks even when it posts no JE.

## Executor notes

- **Test helpers** referenced in a task's example (`seedCompanyWithCurrency`, `postedJournalEntryId`, `makeDraftBalancedEntry`, `seedRepository`, etc.) are **not pre-existing** — define them in the task that first uses them, or substitute the repo's existing factory/seeder patterns (`PaymentRepositorySeeder`, `DemoPharmacySeeder`, model factories). Verify a factory exists before calling `::factory()`.
- **Commit messages:** conventional commits (`feat(...)`, `fix(...)`, `test(...)`) — matches repo git history.
- **Migrations** apply per-tenant: after adding any, run `php artisan tenants:migrate` (not `migrate`) on the local stack.

**Goal:** Make every money movement in a payment repository flow through one append-only ledger + single write port, each carrying a source-document reference and a GL journal entry written atomically — so cash position is trustworthy, drillable, and audit-defensible.

**Architecture:** New `repository_movements` append-only ledger (immutability enforced by a PG trigger, modelled on `fiscal_events`) + a cached `balance` column on `payment_repositories` updated under `lockForUpdate` inside the same DB transaction that posts the GL entry **synchronously** (new `postEntryNow()` path — the existing `DB::afterCommit` posting breaks atomicity). All money-moving flows converge onto `TreasuryMovementService` in one cutover wave; the old balance-only ports are deleted and a DB trigger blocks direct `balance` writes.

**Tech Stack:** Laravel 12 / PHP 8.2 strict types, PostgreSQL 16 (database-per-tenant), PHPUnit, React 19 / TanStack Query 5 / Vitest, Playwright.

**Spec:** `docs/superpowers/specs/2026-07-07-treasury-spine-design.md` (Rev 2) · **Adversarial review reconciled:** `docs/superpowers/specs/reviews/2026-07-08-treasury-spine-codex-review.md`.

## Global Constraints

- **Constructor injection only** — `private readonly`, never `app()` (rule 13).
- **No float on money/quantity** — `CurrencyScale::bcformatStrict`/`bcadd`/`bcsub` at `$scaleResolver->getScale($currency)`; pass **explicit currency** in queued/projection/console contexts (never no-arg `getScale()`) (rule 19).
- **Strict typing** — no `mixed` (PHP), no `any` (TS); JSONB → DTO (rule 3).
- **Enums for every status/type/direction column** (rule 9).
- **Module routes** use `['api','auth:sanctum',SetPermissionsTeam::class]` + `can:<perm>` (rule 12).
- **TS types flow from backend** — run `php artisan typescript:transform` after DTO changes; never hand-edit generated types (rule 7).
- **`apiGet`/`apiPost` already unwrap `response.data.data`** — return directly; paginated `{data,meta}` uses `api.get` + return `response.data` (rule 14).
- **Frontend text via `t()`**; money via `formatCurrency`, inputs via `<MoneyInput>` (rules 11, 19).
- **TanStack tenant query keys** via `tenantScopedKey([...])` (rule 14).
- **Monetary scale:** currency `decimal(N,3)`; movement `amount decimal(15,3)`.
- **Projection/queue tests** must `app(CompanyContext::class)->clear()` before `apply()` (rule 20).
- **Never run the full PHPUnit suite** (crashes the laptop) — run suites **by path**. After any hung vitest run, kill worker pools.
- **Worktree:** all work in `feat/treasury-spine` off `dev` (created via using-git-worktrees at execution start). Branch discipline per rule 21.
- **Commit** after every green step. **Do not push** — owner promotes.

## File Structure

**New (net-new artifacts):**
- `apps/api/database/migrations/tenant/*_add_spine_columns_to_payment_repositories.php` — currency, frozen_at, frozen_reason, next_movement_ordinal.
- `apps/api/database/migrations/tenant/*_create_repository_movements_table.php`
- `apps/api/database/migrations/tenant/*_create_repository_movements_immutability.php`
- `apps/api/database/migrations/tenant/*_add_journal_code_to_journal_entries.php`
- `apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php` — model.
- `apps/api/app/Modules/Treasury/Domain/Enums/{MovementDirection,MovementSourceType,MovementReasonCode}.php`
- `apps/api/app/Modules/Treasury/Domain/Events/RepositoryMovementRecorded.php`
- `apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php`
- `apps/api/app/Modules/Treasury/Application/DTOs/{MovementIntent,MovementResult,TransferIntent,TransferResult}.php`
- `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php` — the port.
- `apps/api/app/Modules/Accounting/Domain/Enums/JournalCode.php`
- `apps/api/app/Modules/Treasury/Presentation/Console/ReconcileTreasuryCommand.php`
- `apps/api/app/Modules/Treasury/Presentation/Controllers/{CashPositionController,RepositoryMovementController,RepositoryAdjustmentController}.php`
- FE: `apps/web/src/features/treasury/RepositoryMovementsTab.tsx`, `useCashPosition.ts`, `useRepositoryMovements.ts`.

**Modified (existing):**
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` — `postEntryNow()`, chain/number race fix, period guard, `journal_code` on create.
- `apps/api/app/Modules/Accounting/Domain/JournalEntry.php` — locked chain-sequence allocation.
- `apps/api/app/Modules/Treasury/Domain/PaymentRepository.php` — casts, drop `balance` from `$fillable`.
- `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php` — port, unpaid→AP, settlement.
- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php` — port.
- `apps/api/app/Modules/Income/Application/Services/IncomeService.php`, `VendorRefundService.php`, `PaymentRefundService.php`, `MultiPaymentService.php` — port.
- `apps/api/app/Modules/Treasury/Application/Projections/{TreasuryReceiptBridge,TreasuryAccountPaymentBridge,TreasuryDepositBridge}.php` — port + canonical-index idempotency.
- `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php` — register `RepositoryMovementRecorded`.
- **Deleted:** `RepositoryInflowService.php`, `RepositoryOutflowService.php`, `RepositoryInflowInterface.php`, `RepositoryOutflowInterface.php` (Task 22).

---

## WAVE A — Foundation (schema + immutability + model; no behavior change)

### Task 1: Add spine columns to `payment_repositories`

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_08_100000_add_spine_columns_to_payment_repositories.php`
- Test: `apps/api/tests/Feature/Treasury/PaymentRepositorySpineColumnsTest.php`

**Interfaces:**
- Produces: columns `currency char(3)`, `frozen_at timestamptz null`, `frozen_reason varchar null`, `next_movement_ordinal bigint default 0 not null` on `payment_repositories`; existing rows backfilled `currency` = their company's currency.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Treasury;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentRepositorySpineColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_repository_has_spine_columns_with_defaults(): void
    {
        $company = $this->seedCompanyWithCurrency('TND'); // helper from TestCase / factory
        $repo = PaymentRepository::factory()->for($company)->create();

        $this->assertSame('TND', $repo->fresh()->currency);
        $this->assertNull($repo->fresh()->frozen_at);
        $this->assertSame('0', (string) $repo->fresh()->next_movement_ordinal);
    }
}
```

> If `PaymentRepository::factory()` / `seedCompanyWithCurrency` don't exist, use the existing repository seeder pattern (`PaymentRepositorySeeder`) and read the real company-currency source (`companies.currency`). Confirm column name for company currency before writing (grep `currencyCodeForCompany`).

- [ ] **Step 2: Run test — expect FAIL** (`currency` undefined)

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/PaymentRepositorySpineColumnsTest.php`
Expected: FAIL (unknown column `currency`).

- [ ] **Step 3: Write the migration**

```php
<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_repositories', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable()->after('balance');
            $table->timestampTz('frozen_at')->nullable();
            $table->string('frozen_reason')->nullable();
            $table->unsignedBigInteger('next_movement_ordinal')->default(0);
        });

        // Backfill currency from the owning company (single-currency today).
        DB::statement(<<<'SQL'
            UPDATE payment_repositories pr
            SET currency = c.currency
            FROM companies c
            WHERE c.id = pr.company_id AND pr.currency IS NULL
        SQL);

        // MED-11: currency is a hard guard — must be non-null. Assert backfill
        // completeness, then enforce at the schema level.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $nulls = DB::table('payment_repositories')->whereNull('currency')->count();
            if ($nulls > 0) {
                throw new \RuntimeException("Cannot enforce NOT NULL: {$nulls} payment_repositories have null currency after backfill.");
            }
            DB::statement('ALTER TABLE payment_repositories ALTER COLUMN currency SET NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('payment_repositories', function (Blueprint $table): void {
            $table->dropColumn(['currency', 'frozen_at', 'frozen_reason', 'next_movement_ordinal']);
        });
    }
};
```

> Verify `companies` table + `currency` column name first (`grep -rn "currency" database/migrations/tenant | grep companies`). Adjust the backfill if the company currency lives elsewhere.

- [ ] **Step 4: Add `currency`, `frozen_at`, `next_movement_ordinal` to the model** (`PaymentRepository.php`): add to `$casts` (`next_movement_ordinal => 'integer'`, `frozen_at => 'immutable_datetime'`) and property docblocks. **Do NOT add `currency`/`next_movement_ordinal` to `$fillable`** (port-managed). **Also update `PaymentRepositoryFactory`** (if it exists) to default `currency` to the factory's company currency (MED-11) — grep `database/factories` for it; if absent, ensure the seeder sets currency.

- [ ] **Step 5: Run test — expect PASS**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/PaymentRepositorySpineColumnsTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/api/database/migrations/tenant/2026_07_08_100000_add_spine_columns_to_payment_repositories.php \
        apps/api/app/Modules/Treasury/Domain/PaymentRepository.php \
        apps/api/tests/Feature/Treasury/PaymentRepositorySpineColumnsTest.php
git commit -m "feat(treasury): add spine columns to payment_repositories (currency, frozen, ordinal)"
```

---

### Task 2: `repository_movements` table

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php`
- Test: `apps/api/tests/Feature/Treasury/RepositoryMovementsTableTest.php`

**Interfaces:**
- Produces: table `repository_movements` with the columns in spec §4, unique `idempotency_key`, indexes `(payment_repository_id, occurred_at)` and `(source_type, source_id)`, `CHECK (amount > 0)`.

- [ ] **Step 1: Write the failing test** — assert insert of a well-formed row succeeds and a second row with the same `idempotency_key` throws a unique violation; assert `amount = 0` violates the CHECK.

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Treasury;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RepositoryMovementsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_idempotency_key_is_unique_and_amount_must_be_positive(): void
    {
        $row = $this->baseMovementRow(); // helper builds a valid assoc array (see Step 3 columns)
        DB::table('repository_movements')->insert($row);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('repository_movements')->insert(array_merge($row, ['id' => (string) Str::uuid()]));
    }

    public function test_amount_check_rejects_zero(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('repository_movements')->insert(
            array_merge($this->baseMovementRow(), ['id' => (string) Str::uuid(), 'idempotency_key' => 'x:'.Str::uuid(), 'amount' => '0.000'])
        );
    }
}
```

> Build `baseMovementRow()` inline in the test with a real repository id (seed one) and all NOT NULL columns from Step 3.

- [ ] **Step 2: Run test — expect FAIL** (table missing).

- [ ] **Step 3: Write the migration**

```php
<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repository_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('payment_repository_id');
            $table->string('direction', 3);                 // MovementDirection: in|out
            $table->decimal('amount', 15, 3);
            $table->char('currency', 3);
            $table->decimal('balance_after', 15, 3);
            $table->unsignedBigInteger('ordinal');          // gapless per-repository
            $table->string('source_type', 32);              // MovementSourceType
            $table->uuid('source_id');
            $table->uuid('journal_entry_id')->nullable();
            $table->string('idempotency_key');
            $table->uuid('transfer_group_id')->nullable();
            $table->uuid('reverses_movement_id')->nullable();
            $table->string('reason_code', 32)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->uuid('created_by')->nullable();
            $table->boolean('recorded_while_frozen')->default(false);
            $table->text('notes')->nullable();

            $table->unique('idempotency_key');
            $table->unique(['payment_repository_id', 'ordinal']); // gapless dense sequence guard
            $table->index(['payment_repository_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
            $table->index('transfer_group_id');

            // MED-12: referential integrity. journal_entry_id nullable (opening_balance /
            // same-account transfer legs); reverses_movement_id self-FK for corrections.
            $table->foreign('payment_repository_id')->references('id')->on('payment_repositories');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries');
            $table->foreign('reverses_movement_id')->references('id')->on('repository_movements');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE repository_movements ADD CONSTRAINT repository_movements_amount_positive CHECK (amount > 0)");
            DB::statement("ALTER TABLE repository_movements ADD CONSTRAINT repository_movements_direction_valid CHECK (direction IN ('in','out'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_movements');
    }
};
```

- [ ] **Step 4: Run test — expect PASS.**

- [ ] **Step 5: Commit**

```bash
git add apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php \
        apps/api/tests/Feature/Treasury/RepositoryMovementsTableTest.php
git commit -m "feat(treasury): create repository_movements append-only ledger table"
```

---

### Task 3: `repository_movements` immutability trigger (append-only)

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_08_100200_create_repository_movements_immutability.php`
- Test: `apps/api/tests/Feature/Treasury/RepositoryMovementsImmutabilityTest.php`

**Interfaces:**
- Produces: PG trigger rejecting UPDATE, DELETE, TRUNCATE on `repository_movements`. Unlike `fiscal_events`, there is **no allowed-mutation whitelist** — movements are wholly immutable (the `recorded_while_frozen` flag is set at INSERT, never mutated).

- [ ] **Step 1: Write the failing test**

```php
public function test_movement_row_cannot_be_updated_or_deleted(): void
{
    $id = $this->insertValidMovement(); // reuse Task 2 helper

    try {
        DB::table('repository_movements')->where('id', $id)->update(['notes' => 'tampered']);
        $this->fail('UPDATE should have been rejected');
    } catch (\Illuminate\Database\QueryException $e) {
        $this->assertStringContainsString('append-only', $e->getMessage());
    }

    $this->expectException(\Illuminate\Database\QueryException::class);
    DB::table('repository_movements')->where('id', $id)->delete();
}
```

- [ ] **Step 2: Run test — expect FAIL** (update succeeds today).

- [ ] **Step 3: Write the migration** (modelled on `2026_05_14_100002_create_fiscal_events_immutability.php`, but pure-reject — no whitelist):

```php
<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION repository_movements_immutability_trigger() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'repository_movements row % cannot be deleted — append-only ledger (spec §4). Corrections are compensating movements.', OLD.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'repository_movements row % cannot be updated — append-only ledger (spec §4). Corrections are compensating movements.', OLD.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF TG_OP = 'TRUNCATE' THEN
                    RAISE EXCEPTION 'repository_movements cannot be truncated — append-only ledger (spec §4).'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER repository_movements_immutability_update BEFORE UPDATE ON repository_movements
                FOR EACH ROW EXECUTE FUNCTION repository_movements_immutability_trigger();
            CREATE TRIGGER repository_movements_immutability_delete BEFORE DELETE ON repository_movements
                FOR EACH ROW EXECUTE FUNCTION repository_movements_immutability_trigger();
            CREATE TRIGGER repository_movements_immutability_truncate BEFORE TRUNCATE ON repository_movements
                FOR EACH STATEMENT EXECUTE FUNCTION repository_movements_immutability_trigger();
        SQL);

        $appRole = config('database.connections.pgsql.username');
        if (is_string($appRole) && $appRole !== '' && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $appRole) === 1) {
            DB::statement('REVOKE TRUNCATE ON repository_movements FROM "'.$appRole.'"');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS repository_movements_immutability_truncate ON repository_movements;
            DROP TRIGGER IF EXISTS repository_movements_immutability_delete ON repository_movements;
            DROP TRIGGER IF EXISTS repository_movements_immutability_update ON repository_movements;
            DROP FUNCTION IF EXISTS repository_movements_immutability_trigger();
        SQL);
    }
};
```

- [ ] **Step 4: Run test — expect PASS.**

- [ ] **Step 5: Commit** `feat(treasury): append-only immutability trigger on repository_movements`.

---

### Task 4: `RepositoryMovement` model + enums

**Files:**
- Create: `apps/api/app/Modules/Treasury/Domain/Enums/MovementDirection.php`, `MovementSourceType.php`, `MovementReasonCode.php`, `apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php`
- Test: `apps/api/tests/Unit/Treasury/RepositoryMovementModelTest.php`

**Interfaces:**
- Produces: `enum MovementDirection: string { case In = 'in'; case Out = 'out'; }`; `MovementSourceType` cases `Payment, Expense, Income, Refund, FiscalEvent, Transfer, Adjustment, OpeningBalance, Instrument`; `MovementReasonCode` cases `CountVariance, Correction, TheftLoss, Other`; model `RepositoryMovement` with casts and `belongsTo(PaymentRepository, JournalEntry)`.

- [ ] **Step 1: Write the failing test** — assert `MovementDirection::In->value === 'in'`; assert `RepositoryMovement` casts `direction` to the enum and `amount`/`balance_after` to `decimal:3`; assert the model is guarded (no `save()` on an existing row — insertion only via the port, but the model exists for reads).

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Write the enums and model.** Model:

```php
<?php
declare(strict_types=1);
namespace App\Modules\Treasury\Domain;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Treasury\Domain\Enums\{MovementDirection, MovementReasonCode, MovementSourceType};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $payment_repository_id
 * @property MovementDirection $direction
 * @property numeric-string $amount
 * @property numeric-string $balance_after
 * @property int $ordinal
 * @property MovementSourceType $source_type
 * @property string $source_id
 * @property ?string $journal_entry_id
 * @property string $idempotency_key
 */
final class RepositoryMovement extends Model
{
    use HasUuids;

    public $timestamps = false; // append-only: created_at set by DB default, no updated_at

    protected $guarded = []; // writes go ONLY through TreasuryMovementService::record (which uses insert())

    protected $casts = [
        'direction' => MovementDirection::class,
        'source_type' => MovementSourceType::class,
        'reason_code' => MovementReasonCode::class,
        'amount' => 'decimal:3',
        'balance_after' => 'decimal:3',
        'ordinal' => 'integer',
        'recorded_while_frozen' => 'boolean',
        'occurred_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    public function repository(): BelongsTo
    {
        return $this->belongsTo(PaymentRepository::class, 'payment_repository_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
```

- [ ] **Step 4: Run — expect PASS.**

- [ ] **Step 5: Commit** `feat(treasury): RepositoryMovement model + movement enums`.

---

### Task 5: `RepositoryMovementRecorded` event + audit subscription

**Files:**
- Create: `apps/api/app/Modules/Treasury/Domain/Events/RepositoryMovementRecorded.php`
- Modify: `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php` (add to `subscribe()` map + handler)
- Test: `apps/api/tests/Feature/Treasury/RepositoryMovementAuditTest.php`

**Interfaces:**
- Produces: `RepositoryMovementRecorded` (extends `DomainEvent`) with `movementId, repositoryId, tenantId, companyId, direction, amount, balanceAfter, currency, sourceType, sourceId, journalEntryId, ordinal, recordedWhileFrozen, occurredAt`; a `DomainEventSubscriber::handleRepositoryMovementRecorded()` writing one `audit_events` row via `AuditService`.

- [ ] **Step 1: Write the failing test** — dispatch `RepositoryMovementRecorded`, assert one `audit_events` row with `event_type = 'treasury.repository.movement_recorded'`.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Create the event** (mirror `RepositoryBalanceChanged` shape, richer payload; `getEventName(): 'treasury.repository.movement_recorded'`, `getAuditPayload()` returns the movement fields). **Add the mapping line** to `DomainEventSubscriber::subscribe()`:

```php
// Treasury spine (audit trail for money movements)
RepositoryMovementRecorded::class => 'handleRepositoryMovementRecorded',
```

and the handler method following the existing `handlePaymentRecorded` pattern (call `AuditService::record(...)`). Import the event at the top.

- [ ] **Step 4: Run — expect PASS.**
- [ ] **Step 5: Commit** `feat(treasury): RepositoryMovementRecorded event wired into audit_events`.

---

## WAVE B — GL hardening (lands before the port; the port depends on it)

### Task 6: `postEntryNow()` — synchronous in-transaction GL posting (BLOCKER 1)

**Problem (verified):** `postEntryAndDispatchPostedEventAfterCommit` (`GeneralLedgerService.php:83-94`) defers the **entire** `postEntry` (status→Posted, hash seal, event) to `DB::afterCommit` when inside a transaction. Callers (`createFromExpense`, `createSupplierPaymentJournalEntry`, `createPaymentReceivedJournalEntry`) therefore commit a **Draft** JE inside their transaction and post it post-commit — so a movement can commit while GL posting later fails. The spine needs the DB posting **inside** the caller transaction.

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
- Test: `apps/api/tests/Feature/Accounting/PostEntryNowAtomicityTest.php`

**Interfaces:**
- Produces: `public function postEntryNow(JournalEntry $entry, ?User $user, ?string $currencyCode = null): void` — seals + persists (status→Posted, chain_sequence, fiscal_hash) **synchronously in the current transaction**, and dispatches `JournalEntryPosted` via `DB::afterCommit` (event deferred, DB state not). Reuses the existing balance assertion and hashing.

- [ ] **Step 1: Write the failing test** — within a `DB::transaction`, create a Draft entry, call `postEntryNow`, then **throw** to roll back; assert the entry does NOT exist (posting rolled back with the movement). Second case: call `postEntryNow` and let it commit; assert `status = Posted` and `chain_sequence` set **before** commit (query inside the transaction via a nested check). Contrast: the existing afterCommit path leaves it Draft mid-transaction.

```php
public function test_post_entry_now_persists_posting_inside_caller_transaction_and_rolls_back(): void
{
    app(CompanyContext::class)->clear();
    $entry = $this->makeDraftBalancedEntry(); // helper: 2-line balanced draft
    try {
        DB::transaction(function () use ($entry): void {
            app(GeneralLedgerService::class)->postEntryNow($entry, null, 'TND');
            $this->assertSame(JournalEntryStatus::Posted, $entry->fresh()->status); // posted IN txn
            throw new \RuntimeException('force rollback');
        });
    } catch (\RuntimeException) {}
    $this->assertNull(JournalEntry::find($entry->id)); // rolled back — atomic
}
```

- [ ] **Step 2: Run — expect FAIL** (`postEntryNow` undefined).
- [ ] **Step 3: Implement.** Refactor `postEntryWithOptionalActor` to separate DB sealing from event dispatch, then add `postEntryNow`:

```php
public function postEntryNow(JournalEntry $entry, ?User $user, ?string $currencyCode = null): void
{
    // Seals + persists status/chain/hash synchronously in the current transaction.
    $posted = $this->sealAndPersistEntry($entry, $user, $currencyCode); // extracted from postEntryWithOptionalActor (everything through $entry->update([...]))

    // Event dispatch is a side effect — defer to after commit so listeners never
    // observe an uncommitted post. DB state change is already durable-in-txn above.
    DB::afterCommit(function () use ($posted): void {
        event($posted); // JournalEntryPosted built inside sealAndPersistEntry, returned
    });
}
```

Extract `sealAndPersistEntry(JournalEntry, ?User, ?string): JournalEntryPosted` from `postEntryWithOptionalActor` (lines 1957-2014): keep the Draft check, balance assertion, `getLastChainHash`/`getNextChainSequence`/`calculateHash`, `$entry->update([...])`; **return** the constructed `JournalEntryPosted` instead of `event()`-ing it inline. Leave the existing `postEntryWithOptionalActor` behavior intact for non-spine callers by having it call `sealAndPersistEntry` then `event()` immediately (preserving current semantics — verified: `postEntry` fires the event inline today).

- [ ] **Step 3b (HIGH-3): add the `PostingMode` seam to the spine GL helpers.** The spine callers (`createFromExpense`, `createFromIncome`, `createSupplierPaymentJournalEntry`, `createPaymentReceivedJournalEntry`) today create a Draft then call `postEntryAndDispatchPostedEventAfterCommit` (`:2332`, `:2429`, `:604`, `:675`). Add:

```php
enum PostingMode { case AfterCommit; case SynchronousInTransaction; }
```

Give each of those four helpers a `PostingMode $mode = PostingMode::AfterCommit` parameter (default preserves current behavior for every non-spine caller — no regression). When `SynchronousInTransaction`, the helper calls `postEntryNow($entry, $user, $currency)` instead of the afterCommit wrapper, and **returns the posted `JournalEntry`** so the caller can pass its id to `record()`. Tasks 14/16/17/19 pass `PostingMode::SynchronousInTransaction`.

> **Chain-sequence race:** `postEntryNow` runs inside the caller transaction; the `getLastChainHash`+`getNextChainSequence` reads are still unlocked. Task 7 adds the lock. Do NOT duplicate that here.

- [ ] **Step 4: Run — expect PASS.** Also run the existing GL suite by path to prove no regression: `./vendor/bin/phpunit tests/Feature/Accounting`.
- [ ] **Step 5: Commit** `feat(accounting): postEntryNow() synchronous in-transaction GL posting (spine BLOCKER-1)`.

### Task 7: Lock GL chain-sequence + entry-number allocation (race fix)

**Problem (verified):** `JournalEntry::getNextChainSequence` (`:136`) and `getLastChainHash` (`:149`) are unlocked `max()` reads; `generateEntryNumber` (`GeneralLedgerService.php:2781`) is an unlocked `max()+1`. Concurrent posts to one company can allocate duplicate `chain_sequence`/`entry_number` — a correctness and FEC-sequentiality defect.

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Domain/JournalEntry.php`, `GeneralLedgerService.php`
- Test: `apps/api/tests/Feature/Accounting/GlChainSequenceConcurrencyTest.php`

**Interfaces:**
- Produces: chain-sequence + hash read and the entry-number allocation serialized per company via a **PG advisory lock** keyed on `company_id` (`pg_advisory_xact_lock(hashtextextended(company_id::text, 0))`) taken at the start of sealing, released at commit.

- [ ] **Step 1: Write the failing test** — two concurrent transactions posting to the same company must produce distinct `chain_sequence` (simulate with two connections; if the harness can't do true concurrency, assert the advisory lock is acquired by asserting `pg_advisory_xact_lock` appears in the query log for a post, and that sequential posts increment without gaps). Prefer a real concurrency test using two DB connections.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement.** In `sealAndPersistEntry` (Task 6), before the chain reads:

```php
if (DB::connection()->getDriverName() === 'pgsql') {
    DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$entry->company_id]);
}
```

Apply the same advisory lock in `generateEntryNumber` (wrap its read) so entry-number and chain-sequence share the per-company serialization. Both are transaction-scoped locks, released on commit; posting already runs in a transaction.

> **Global lock order (BLOCKER-1):** this advisory lock is **step 1** of every converged flow. Because it lives inside `postEntryNow`/sealing, and callers post GL before calling `record()` (which takes the repo lock), the order is always advisory → repo. `transfer()` (Task 12) must take this same company advisory lock BEFORE its sorted repo locks even when it posts no JE — add an explicit `pg_advisory_xact_lock(hashtextextended(company_id::text,0))` at the top of `transfer()`.

- [ ] **Step 4: Run — expect PASS** + `./vendor/bin/phpunit tests/Feature/Accounting`.
- [ ] **Step 5: Commit** `fix(accounting): serialize GL chain-sequence + entry-number allocation per company (advisory lock)`.

### Task 8: Wire the closed-period guard into posting (BLOCKER 2)

**Problem (verified):** `FiscalPeriodResolverService::isDateInOpenPeriod` (`:246-256`) exists but nothing calls it in the posting path. Spec requires period validation **once, in-transaction**, alongside `postEntryNow`. **Guard semantics (avoids bricking):** reject only when a `FiscalPeriod` **exists and is Closed** for the entry date; **absence** of any period for the date is allowed (config not yet done) — never block posting for a company with no periods seeded.

**Files:**
- Modify: `GeneralLedgerService.php` (`sealAndPersistEntry`), inject `FiscalPeriodResolverService`
- Test: `apps/api/tests/Feature/Accounting/PostingClosedPeriodGuardTest.php`

**Interfaces:**
- Consumes: `FiscalPeriodResolverService` (constructor-injected).
- Produces: `sealAndPersistEntry` throws `ClosedFiscalPeriodException` (new, in Accounting Domain) when the entry date falls in an existing **Closed** period.

- [ ] **Step 1: Write the failing test** — post into a date inside a Closed period → expect `ClosedFiscalPeriodException`; post into an Open period → succeeds; post for a company with **no** periods → succeeds (not bricked).
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement.** Add a resolver method `isDateInClosedPeriod(companyId, date): bool` (true only when a period exists AND is Closed) — do NOT reuse `isDateInOpenPeriod` (its false-on-absence would brick unconfigured companies). In `sealAndPersistEntry`, after the advisory lock:

```php
if ($this->fiscalPeriodResolver->isDateInClosedPeriod($entry->company_id, $entry->entry_date)) {
    throw new ClosedFiscalPeriodException($entry->company_id, (string) $entry->entry_date);
}
```

- [ ] **Step 4: Run — expect PASS** + `./vendor/bin/phpunit tests/Feature/Accounting`.
- [ ] **Step 5: Commit** `feat(accounting): reject posting into a closed fiscal period (spine BLOCKER-2)`.

### Task 9: `journal_code` column + enum (FEC-readiness)

**Files:**
- Create: migration `*_add_journal_code_to_journal_entries.php`, `apps/api/app/Modules/Accounting/Domain/Enums/JournalCode.php`
- Modify: `GeneralLedgerService.php` (populate `journal_code` on every `JournalEntry::create`)
- Test: `apps/api/tests/Feature/Accounting/JournalCodeTest.php`

**Interfaces:**
- Produces: `journal_code varchar(4) null` on `journal_entries`; `enum JournalCode: string { Sales='VT'; Purchase='AC'; Bank='BQ'; Cash='CA'; Misc='OD'; }` with `public static function fromSourceType(string $sourceType): self` (maps `invoice/credit_note→VT`, `supplier_invoice→AC`, `payment/customer_payment/supplier_payment→BQ`, `pos_payment/pos_receipt→CA`, default `OD`).

- [ ] **Step 1: Write the failing test** — post an invoice-sourced entry, assert `journal_code = 'VT'`; a payment-sourced entry → `'BQ'`; `JournalCode::fromSourceType('unknown') === JournalCode::Misc`.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** migration + enum; in `GeneralLedgerService`, at each `JournalEntry::create([...])`, add `'journal_code' => JournalCode::fromSourceType($sourceType)->value`. There are ~10 create sites — add to each using the literal `source_type` already passed there.
- [ ] **Step 4: Run — expect PASS** + `./vendor/bin/phpunit tests/Feature/Accounting`.
- [ ] **Step 5: Commit** `feat(accounting): journal_code column + JournalCode enum (FEC-readiness)`.

---

## WAVE C — The write port (net-new; no writer migrated yet)

### Task 10: Movement DTOs

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/DTOs/{MovementIntent,MovementResult,TransferIntent,TransferResult}.php`
- Test: `apps/api/tests/Unit/Treasury/MovementIntentTest.php`

**Interfaces:**
- Produces:
  - `MovementIntent` (readonly): `repositoryId, tenantId, companyId, MovementDirection $direction, string $amount /*numeric-string*/, string $currency, MovementSourceType $sourceType, string $sourceId, string $idempotencyLeg, ?string $journalEntryId, ?CarbonImmutable $occurredAt, ?MovementReasonCode $reasonCode, ?string $reversesMovementId, ?string $createdBy, ?string $notes, bool $allowWhileFrozen = false`. Computes `idempotencyKey(): string` = `"{$sourceType->value}:{$sourceId}:{$idempotencyLeg}"`. **`allowWhileFrozen` (HIGH-4/5) is explicit, NOT inferred from `sourceType`** — set `true` ONLY by offline-device-replay projection bridges (Task 20 receipt legs, Task 21 returns); every interactive caller and every server-authored fiscal event (e.g. `DEPOSIT_RECEIPT`, which `isServerOnly()`) leaves it `false`.
  - `MovementResult` (readonly): `string $movementId, string $balanceAfter, int $ordinal, bool $wasIdempotentHit`.
  - `TransferIntent` (readonly): `fromRepositoryId, toRepositoryId, tenantId, companyId, string $amount, string $currency, string $transferGroupId, ?string $journalEntryId, ?CarbonImmutable $occurredAt, ?string $createdBy, ?string $notes`.
  - `TransferResult` (readonly): `MovementResult $outLeg, MovementResult $inLeg`.

- [ ] **Step 1: Write the failing test** — `MovementIntent::idempotencyKey()` for a POS tender returns `"fiscal_event:{uuid}:payment:0"`.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Write the four DTOs** (constructor-promoted readonly properties, strict types).
- [ ] **Step 4: Run — expect PASS.**
- [ ] **Step 5: Commit** `feat(treasury): movement + transfer intent/result DTOs`.

### Task 11: `TreasuryMovementService::record()` — the port

**Files:**
- Create: `apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php`, `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php`
- Modify: `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php` (bind interface → service)
- Test: `apps/api/tests/Feature/Treasury/TreasuryMovementServiceRecordTest.php`

**Interfaces:**
- Consumes: `MovementIntent`, `CurrencyScaleResolverInterface`, `PaymentRepository`.
- Produces: `record(MovementIntent $intent): MovementResult`. **Must be called inside a caller-managed `DB::transaction`** (so the GL post in the same intent — passed as `journalEntryId` already posted via `postEntryNow` upstream, OR posted by the caller — is atomic with the movement). Contract: the caller posts the GL entry via `postEntryNow` in the same transaction and passes its id; `record()` writes the movement row.

**Exact ordering (spec §5.4, review F11):**

- [ ] **Step 1: Write the failing tests:**
  - (a) records a movement, sets `balance_after`, increments `ordinal` from 0→1, updates cached `balance`.
  - (b) currency mismatch (intent currency ≠ repository currency) throws.
  - (c) idempotency: calling `record()` twice with the same intent (same key) in separate transactions yields one row; the second returns `wasIdempotentHit = true` with the same `movementId`, no ordinal gap, balance unchanged.
  - (d) idempotency mismatch: same key, different amount → throws loudly.
  - (e) interactive write (`allowWhileFrozen=false`) into a frozen repository throws `RepositoryFrozenException`; a replay write (`allowWhileFrozen=true`) into a frozen repository succeeds with `recorded_while_frozen=true`.
  - (f) MED-9: calling `record()` with no active transaction (`DB::transactionLevel()===0`) throws `LogicException`.

```php
public function test_records_movement_and_increments_ordinal_and_balance(): void
{
    app(CompanyContext::class)->clear();
    $repo = $this->seedRepository(currency: 'TND', balance: '100.000'); // next_movement_ordinal=0
    $je = $this->postedJournalEntryId();

    $result = DB::transaction(fn () => app(TreasuryMovementServiceInterface::class)->record(new MovementIntent(
        repositoryId: $repo->id, tenantId: $repo->tenant_id, companyId: $repo->company_id,
        direction: MovementDirection::In, amount: '25.000', currency: 'TND',
        sourceType: MovementSourceType::Payment, sourceId: (string) Str::uuid(), idempotencyLeg: 'main',
        journalEntryId: $je, occurredAt: null, reasonCode: null, reversesMovementId: null,
        createdBy: null, notes: null,
    )));

    $this->assertSame('125.000', $repo->fresh()->balance);
    $this->assertSame(1, $result->ordinal);
    $this->assertSame('125.000', $result->balanceAfter);
}
```

- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement `record()`:**

```php
public function record(MovementIntent $intent): MovementResult
{
    // 0. MED-9: the caller MUST own an outer transaction (so the GL post + this
    // movement + the SET LOCAL GUC are atomic). Enforce it — a top-level call
    // would silently lose the row lock at statement end.
    if (DB::transactionLevel() === 0) {
        throw new \LogicException('TreasuryMovementService::record() must be called inside a DB::transaction (with the GL post).');
    }

    $scale = $this->scaleResolver->getScale($intent->currency);

    // 1. Lock the repository row. NOTE global lock order (BLOCKER-1): the caller
    // has ALREADY taken the GL company advisory lock via postEntryNow. Repo lock
    // comes second, always.
    /** @var PaymentRepository $repo */
    $repo = PaymentRepository::query()
        ->where('tenant_id', $intent->tenantId)->where('company_id', $intent->companyId)
        ->whereKey($intent->repositoryId)->lockForUpdate()->firstOrFail();

    // 2. Currency guard (review F12): intent must match repository AND company currency.
    if ($repo->currency !== $intent->currency) {
        throw new CurrencyMismatchException($intent->repositoryId, $repo->currency, $intent->currency);
    }

    // 3. Freeze policy (review F5 + HIGH-4/5): rejection is driven by the EXPLICIT
    // allowWhileFrozen flag, never inferred from sourceType (returns are Refund;
    // server DEPOSIT_RECEIPT is FiscalEvent — both would be misclassified).
    $recordedWhileFrozen = false;
    if ($repo->frozen_at !== null) {
        if ($intent->allowWhileFrozen) {
            $recordedWhileFrozen = true;      // offline device replay: record + alert, never throw
        } else {
            throw new RepositoryFrozenException($intent->repositoryId, (string) $repo->frozen_reason);
        }
    }

    // MED-10: set the port GUC in the OUTER transaction, BEFORE the savepoint, so a
    // duplicate-key rollback-to-savepoint cannot unset it and trip the Task-22 trigger.
    DB::statement("SET LOCAL app.treasury_movement_port = 'on'");

    // 4. SAVEPOINT for idempotency recovery (unique-violation poisons the txn otherwise).
    DB::beginTransaction(); // nested → PG SAVEPOINT
    try {
        $nextOrdinal = $repo->next_movement_ordinal + 1;
        $previous = $repo->balance ?? '0';
        $balanceAfter = $intent->direction === MovementDirection::In
            ? bcadd($previous, $intent->amount, $scale)
            : bcsub($previous, $intent->amount, $scale);

        $movementId = (string) Str::uuid();
        DB::table('repository_movements')->insert([
            'id' => $movementId,
            'tenant_id' => $intent->tenantId, 'company_id' => $intent->companyId,
            'payment_repository_id' => $intent->repositoryId,
            'direction' => $intent->direction->value, 'amount' => $intent->amount,
            'currency' => $intent->currency, 'balance_after' => $balanceAfter, 'ordinal' => $nextOrdinal,
            'source_type' => $intent->sourceType->value, 'source_id' => $intent->sourceId,
            'journal_entry_id' => $intent->journalEntryId, 'idempotency_key' => $intent->idempotencyKey(),
            'transfer_group_id' => null, 'reverses_movement_id' => $intent->reversesMovementId,
            'reason_code' => $intent->reasonCode?->value, 'occurred_at' => ($intent->occurredAt ?? now()),
            'created_by' => $intent->createdBy, 'recorded_while_frozen' => $recordedWhileFrozen,
            'notes' => $intent->notes,
        ]);

        $repo->next_movement_ordinal = $nextOrdinal;
        $repo->balance = $balanceAfter;
        $repo->save();
        DB::commit(); // release savepoint
    } catch (QueryException $e) {
        DB::rollBack(); // to savepoint — undoes ordinal + balance, no gap
        if (! $this->isUniqueViolation($e)) { throw $e; }
        return $this->handleIdempotentHit($intent); // SELECT existing, validate semantics or throw
    }

    DB::afterCommit(fn () => event($this->buildRecordedEvent($movementId, $intent, $balanceAfter, $nextOrdinal, $recordedWhileFrozen)));
    return new MovementResult($movementId, $balanceAfter, $nextOrdinal, false);
}
```

Implement `handleIdempotentHit` (SELECT by `idempotency_key`; compare `repository_id`, `direction`, `amount`, `currency`, `source_type`, `source_id`; on full match return `MovementResult(existing, wasIdempotentHit: true)`; on mismatch throw `IdempotencyConflictException`). Create exceptions `CurrencyMismatchException`, `RepositoryFrozenException`, `IdempotencyConflictException` in Treasury Domain. Bind the interface in `TreasuryServiceProvider`.

> The savepoint uses `DB::beginTransaction()/commit()/rollBack()` which map to PG SAVEPOINTs when already inside the caller transaction (verified: Laravel nests SAVEPOINTs at `transactionLevel > 0`; `afterCommit` fires only at the OUTER commit). The Step-0 assertion guarantees the caller transaction exists.

- [ ] **Step 4: Run — expect PASS** (`./vendor/bin/phpunit tests/Feature/Treasury/TreasuryMovementServiceRecordTest.php`).
- [ ] **Step 5: Commit** `feat(treasury): TreasuryMovementService::record() — single-movement write port`.

### Task 12: `TreasuryMovementService::transfer()` — paired legs, sorted two-lock

**Files:**
- Modify: `TreasuryMovementService.php`
- Test: `apps/api/tests/Feature/Treasury/TreasuryMovementServiceTransferTest.php`

**Interfaces:**
- Produces: `transfer(TransferIntent $intent): TransferResult` — single dedicated transaction, locks **both** repositories in `sort([fromId,toId])` order before any insert, writes an `out` leg (from) + `in` leg (to) sharing `transfer_group_id`, updates both balances; posts one JE only when the two repositories map to different `gl_account_id`.

- [ ] **Step 1: Write the failing tests:** (a) transfer 30 from A(100)→B(0) leaves A=70, B=30, two movements share `transfer_group_id`, ordinals increment on each repo; (b) two opposing concurrent transfers A↔B don't deadlock (sorted lock order) — assert both complete; (c) failure after the first leg rolls back both (no half-transfer).
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** using two internal insert calls (NOT two public `record()` calls) within one `DB::transaction`, locking sorted. **Take the GL company advisory lock (`pg_advisory_xact_lock(hashtextextended(company_id::text,0))`) FIRST** (global lock order, BLOCKER-1), then the two repo locks sorted by id, then `SET LOCAL app.treasury_movement_port='on'`, then the inserts. Reuse the ordinal/balance logic. Currency must match across both repositories + company.
- [ ] **Step 4: Run — expect PASS.**
- [ ] **Step 5: Commit** `feat(treasury): TreasuryMovementService::transfer() — atomic paired-leg transfer`.

---

## WAVE D — Convergence (all writers onto the port; cutover lock LAST)

> **Migration-safety invariant (review F8):** each task in this wave **REPLACES** a flow's inline balance write with a port call — never ADDS a port call on top of an inline write (that would double-count). The reconcile command (Wave F) is not scheduled until after this wave, so a partially-migrated mid-wave state is never audited as drift. Task 22 (cutover lock) is the LAST task and only then does the DB trigger forbid direct `balance` writes — so every writer must be migrated before it lands.

### Task 13: Freeze enforcement helpers + `RepositoryFrozenException` surfacing

**Files:**
- Modify: `TreasuryMovementService.php` (already throws in Task 11); add `freeze(repositoryId, reason)` / `unfreeze(repositoryId)` methods used by the reconcile command (Wave F) and an admin endpoint.
- Test: `apps/api/tests/Feature/Treasury/RepositoryFreezeTest.php`

**Interfaces:**
- Produces: `freeze(string $repositoryId, string $reason): void` (sets `frozen_at`/`frozen_reason` — this is a direct column write on `payment_repositories`, allowed because it is not `balance`), `unfreeze(string $repositoryId): void`.

- [ ] **Step 1: Write the failing test** — freeze a repo, assert an interactive `record()` throws `RepositoryFrozenException`, a projection-origin `record()` succeeds with `recorded_while_frozen = true`; unfreeze restores interactive writes.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement `freeze`/`unfreeze`.**
- [ ] **Step 4: Run — expect PASS.**
- [ ] **Step 5: Commit** `feat(treasury): repository freeze/unfreeze + projection-safe freeze policy`.

### Task 14: Migrate expense post onto the port; unpaid → AP liability

**Problem (verified):** `ExpenseService::post` (`:160-205`) calls the old `outflow->applyOutflow` for paid expenses; `createFromExpense` (`GeneralLedgerService.php:2260-2332`) **credits Cash unconditionally even when unpaid** (bug). Fix: paid → port movement; unpaid → `Cr AP liability` (no cash credit, no movement).

**Files:**
- Modify: `ExpenseService.php`, `GeneralLedgerService.php` (`createFromExpense`)
- Test: `apps/api/tests/Feature/Expense/ExpensePostSpineTest.php`

**Interfaces:**
- Consumes: `TreasuryMovementServiceInterface`, `postEntryNow`.
- Produces: paid expense post writes a `movement(expense, out)` + posts GL (Dr expense / Cr Cash) via `postEntryNow`, atomic; unpaid expense posts GL (Dr expense / Cr AP-liability via `SystemAccountPurpose::AccountsPayable` or the expense-payable purpose) with **no movement**.

- [ ] **Step 1: Write the failing tests:** (a) paid expense: repo balance decrements, one `repository_movements` row (`source_type=expense`), GL Cr = Cash account; (b) unpaid expense: **no movement**, repo balance unchanged, GL Cr = AP-liability account (NOT cash); (c) GL-post failure rolls back the movement (atomicity).
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement.** In `createFromExpense`, branch on `metadata.is_paid`: paid → Cr cash (existing); unpaid → Cr the AP-liability account. Call it with `PostingMode::SynchronousInTransaction` (Task 6) so the JE posts in-transaction and returns posted. In `ExpenseService::post`, replace the `outflow->applyOutflow` block with, inside the existing `DB::transaction`: post GL via the synchronous helper, then (paid only) `movementService->record(new MovementIntent(...sourceType: Expense, direction: Out, journalEntryId: $entry->id, idempotencyLeg: 'main', allowWhileFrozen: false ...))`. Inject `TreasuryMovementServiceInterface` into `ExpenseService` (`GeneralLedgerService` already injected).
- [ ] **Step 4: Run — expect PASS** (`./vendor/bin/phpunit tests/Feature/Expense`).
- [ ] **Step 5: Commit** `feat(expense): post via movement port; unpaid expense books AP liability not cash`.

### Task 15: Expense settlement — `POST /expenses/{id}/pay`

**Files:**
- Modify: `apps/api/app/Modules/Expense/routes.php`, `ExpenseController.php`, `ExpenseService.php`
- Create: `apps/api/app/Modules/Expense/Presentation/Requests/PayExpenseRequest.php`
- Test: `apps/api/tests/Feature/Expense/ExpenseSettlementTest.php`

**Interfaces:**
- Produces: `POST /expenses/{id}/pay` (`can:expenses.pay`) — body `{payment_repository_id, payment_method_id?, payment_date}`; requires a **Posted, unpaid** expense; writes `movement(expense, out)` + GL (`Dr AP-liability / Cr Cash`) atomically via `postEntryNow`, marks `expense_metadata.is_paid = true`, `paid_at`. Idempotency leg `settlement`.

- [ ] **Step 1: Write the failing test** — settle a posted unpaid expense: repo balance decrements, one movement (`idempotency_key` ends `:settlement`), GL Dr = AP-liability / Cr = Cash, metadata `is_paid=true`; settling an already-paid expense → 422; settling twice (retry) → idempotent single movement.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** the request, `ExpenseService::settle(Document, PayExpenseRequestData, User)`, controller action, route (with `can:expenses.pay`), and seed `expenses.pay` permission in `RolesAndPermissionsSeeder` granted to expense-capable roles.
- [ ] **Step 4: Run — expect PASS.**
- [ ] **Step 5: Commit** `feat(expense): settlement endpoint closes the unpaid-expense AP loop`.

### Task 16: Migrate `PaymentController` (store + storeMultiple) onto the port

**Problem (verified):** inline `$repo->balance = bcadd/bcsub` at `PaymentController.php:602-605` and `:960-961`, plus a hand-fired `RepositoryBalanceChanged`. Replace with a port `record()` inside the existing transaction; GL already created — ensure it posts via `postEntryNow` for atomicity.

**Files:**
- Modify: `PaymentController.php`
- Test: `apps/api/tests/Feature/Treasury/PaymentControllerSpineTest.php`

**Interfaces:**
- Consumes: `TreasuryMovementServiceInterface`.
- Produces: customer payment → `movement(payment, in)`; supplier payment → `movement(payment, out)`; both linked to the posted JE, in one transaction; the inline balance write + hand-fired `RepositoryBalanceChanged` are removed.

- [ ] **Step 1: Write the failing test** — a customer payment creates a `payment` row AND a `repository_movements` row with matching `journal_entry_id`, balance moves once (not twice); supplier payment decrements; the old `RepositoryBalanceChanged` is no longer the balance path (movement event is).
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement — EXPLICIT REORDER (BLOCKER-2).** The current order is **balance-write-then-GL**: `store` locks the repo and mutates `balance` at `:586-607`, fires `RepositoryBalanceChanged` afterCommit at `:608-619`, and only creates GL at `:623-675` (via the afterCommit wrapper). A literal "replace the inline balance block with `record()`" is impossible — there is no `journalEntryId` yet at `:586`. Rewrite the transaction body to:
  1. create the `Payment` (+ allocations) as today;
  2. create the **draft** JE and post it with `PostingMode::SynchronousInTransaction` (Task 6) — this takes the company advisory lock and returns the posted `JournalEntry`;
  3. call `movementService->record(new MovementIntent(... direction: In for customer / Out for supplier, sourceType: Payment, sourceId: <stable idempotency source, Task 16b>, journalEntryId: $entry->id, idempotencyLeg: 'main', allowWhileFrozen: false ...))`;
  4. set `$payment->journal_entry_id = $entry->id`.

  Delete the inline `$repository->balance = ...` block (`:600-607`), the manual `RepositoryBalanceChanged` dispatch (`:608-619`), the controller's own repo `lockForUpdate` (the port locks it), **and the manual supplier partner-refresh block at `:650-657` (LOW-13)** — posting is now in-transaction so `JournalEntryPosted` fires afterCommit and its listener (`EventServiceProvider.php:63-65`) does the refresh; the manual one would double-refresh. Do the same reorder for `storeMultiple` (`:948-961`).
- [ ] **Step 4: Run — expect PASS** (`./vendor/bin/phpunit tests/Feature/Treasury`).
- [ ] **Step 5: Commit** `feat(treasury): PaymentController store/storeMultiple write via movement port (reordered draft→post→record)`.

### Task 16b: Request-level idempotency for payment + multipayment creation (HIGH-7)

**Problem (verified):** `PaymentController::store()` mints a fresh `Payment` UUID (`:434-450`) with no client idempotency key; a lost-response retry creates a second payment (and would create a second movement, since the movement key derives from the new payment id). The movement port's idempotency can't save a flow whose *source id itself* is non-deterministic across retries.

**Files:** Modify `PaymentController.php`, `MultiPaymentController.php`, a migration adding `idempotency_key` to `payments`; Test `apps/api/tests/Feature/Treasury/PaymentIdempotencyTest.php`.

**Interfaces:**
- Produces: payment/multipayment creation accepts an `Idempotency-Key` header (or body field), persisted as `payments.idempotency_key` (unique per company); a retry with the same key returns the existing payment (and therefore the existing movement) instead of creating a new one. Movement `sourceId` = the payment id, now stable because the payment itself is deduped.

- [ ] **Step 1: Write the failing test** — POST the same payment twice with the same `Idempotency-Key` → one `payments` row, one `repository_movements` row, balance moved once.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** the `payments.idempotency_key` column (unique `(company_id, idempotency_key)`), read the header in the controllers, short-circuit to the existing payment on a hit (before opening the write transaction). This makes the Task 16/19 movement keys stable.
- [ ] **Step 4: Run — expect PASS.**
- [ ] **Step 5: Commit** `feat(treasury): request-level idempotency on payment + multipayment creation`.

### Task 17: Migrate `IncomeService` onto the port

**Files:** Modify `IncomeService.php`; Test `apps/api/tests/Feature/Income/IncomeSpineTest.php`.
- [ ] **Step 1:** Failing test — income post creates `movement(income, in)` linked to the posted JE; balance moves once.
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Replace `inflow->applyInflow` (`IncomeService.php:141`) with `movementService->record(...)`; GL via `postEntryNow`.
- [ ] **Step 4:** Run — PASS (`./vendor/bin/phpunit tests/Feature/Income`).
- [ ] **Step 5:** Commit `feat(income): record via movement port`.

### Task 18: Migrate refunds (`VendorRefundService`, `PaymentRefundService`) onto the port + lock original payment

**Problem (verified):** `VendorRefundService:149-152` writes balance inline; `PaymentRefundService` (`:82-232`) creates negative `Payment` rows with **no GL, no balance, no original-payment lock** — two concurrent partial refunds can over-refund (review F10).

**Files:** Modify `VendorRefundService.php`, `PaymentRefundService.php`; Test `apps/api/tests/Feature/Treasury/RefundSpineTest.php`.

**Interfaces:**
- Produces: refunds `lockForUpdate` the original payment, compute already-refunded total under the lock, write `movement(refund)` + GL reversal via `postEntryNow`; refunds carry a **stable `refund_request_id`** → idempotency key `refund:{original_payment_id}:{refund_request_id}`.

> **HIGH-6 (verified):** admin refund endpoints (`PaymentRefundController::partialRefund` `:91-98`, `refundPayment` `:61-65`) accept only `amount`/`reason` — no request id. `refund_request_id` exists ONLY on the POS/prorated path today. Without a stable request id the planned idempotency key cannot be built and retries double-refund.

- [ ] **Step 1:** Failing tests — (a) vendor refund creates `movement(refund, out)` + GL; (b) two concurrent partial refunds summing over the original are rejected (over-refund guard under lock); (c) a partial-refund retry with the same `refund_request_id` is idempotent (one refund payment, one movement).
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Add a required `refund_request_id` (client-supplied UUID) to the admin full/partial refund requests; persist it on the refund `payments` row; add a unique index `(company_id, original_payment_id, refund_request_id)` covering the non-prorated admin path (extend the existing POS-prorated index or add a sibling). Add `lockForUpdate` on the original payment in `PaymentRefundService::partialRefund`/`fullRefund`; compute refunded-to-date under the lock; replace inline balance in `VendorRefundService` (`:149-152`) with `record()`; add GL reversal via `postEntryNow`; movement idempotency key `refund:{original_payment_id}:{refund_request_id}`.
- [ ] **Step 4:** Run — PASS.
- [ ] **Step 5:** Commit `fix(treasury): refunds lock original payment, post GL + movement via port`.

### Task 19: `MultiPaymentService` — add movement + GL to the cash-moving flows

> **HIGH-8 (verified — Task rewritten from real code):** `MultiPaymentService.php:58-305` does **NOT** write repository balances. It creates `Payment` + `PaymentAllocation` rows and updates `document.balance_due`. So there is no "inline balance write to replace" — the real gap is that its cash-bearing flows (`createSplitPayment` `:58-91`, deposit application `:110-141`) create `Payment` rows that **never move repository cash and post no GL**. `recordPaymentOnAccount` (`:194-246`) has **no `repository_id`** — it is a partner credit-balance entry, NOT a cash movement, so it gets **no repository movement** (only its existing partner-balance effect; a GL entry to the customer-advances account may be added but that is not a `repository_movements` row).

**Files:** Modify `MultiPaymentService.php`; Test `apps/api/tests/Feature/Treasury/MultiPaymentSpineTest.php`.

**Interfaces:**
- Produces: `createSplitPayment` and deposit-application flows — for each payment line that carries a `repository_id`, write `movement(payment, in/out)` + post GL via `postEntryNow`, keyed by the (now-stable, Task 16b) payment id + line index. `recordPaymentOnAccount` writes NO repository movement (documented as partner-credit).

- [ ] **Step 1:** Failing tests — (a) a split payment with two repository-bearing lines creates two movements + posted GL, each linked; (b) `recordPaymentOnAccount` creates **no** `repository_movements` row (partner credit only); (c) a repository-bearing line with a null `repository_id` is rejected or skipped per the resolved rule (decide + assert).
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** For each cash-moving line, after creating the `Payment`, create+post its GL (`postEntryNow`) and call `record()` inside the existing transaction; require `repository_id` where a line represents cash; leave `recordPaymentOnAccount` movement-free.
- [ ] **Step 4:** Run — PASS.
- [ ] **Step 5:** Commit `feat(treasury): MultiPaymentService cash lines post GL + movements via port`.

### Task 20: Migrate POS bridges onto the port (canonical-index idempotency, gl_account_id)

**Problem (verified):** `TreasuryReceiptBridge` creates Payment + GL but **no balance** and its idempotency probe is "any payment exists for event" (partial-replay hole, review F3/F4); `TreasuryDepositBridge` requires `account_id` while others use `gl_account_id` (review F14).

**Files:** Modify `TreasuryReceiptBridge.php`, `TreasuryAccountPaymentBridge.php`, `TreasuryDepositBridge.php`; possibly a migration to persist the canonical payment index on `pos_receipt_payments`; Test `apps/api/tests/Feature/Treasury/PosBridgeSpineTest.php`.

**Interfaces:**
- Produces: each POS tender line → `movement(fiscal_event, in/out)` keyed `fiscal_event:{event_id}:payment:{canonical_index}`; deposit/account bridges → one movement keyed `:payment:0`; cash-GL resolved via `gl_account_id`; **complete-set replay** — on replay, every expected canonical leg is validated to exist (missing legs are written, present legs are idempotent hits).

- [ ] **Step 1:** Failing tests — (a) a 2-tender sale (cash+card) creates two movements with distinct canonical-index keys, both linked to the receipt's GL; (b) replay after a partial write (only leg 0 exists) writes the missing leg 1 (complete-set); (c) full replay is fully idempotent; (d) deposit bridge resolves `gl_account_id` and creates a movement.
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Persist the canonical `PaymentDTO` index (add column to `pos_receipt_payments` if not derivable; **first trace** `PosCoreReceiptProjection.php:733-801` per §15 Q2). Replace the any-exists idempotency probe with per-leg keys; call `record()` per tender line inside the bridge's transaction (bridges already post GL — route through `postEntryNow`). **Device-replay legs set `allowWhileFrozen: true`** (offline POS must never poison the fiscal-projection queue on a frozen repo — HIGH-4). **Server-authored `DEPOSIT_RECEIPT` (which `isServerOnly()`) sets `allowWhileFrozen: false`** — it is not offline device replay (HIGH-5). Canonicalize deposit/account bridges on `gl_account_id` (drop `account_id`-only check in `TreasuryDepositBridge::resolveRepository:196-215`).
- [ ] **Step 4:** Run — PASS (`./vendor/bin/phpunit tests/Feature/Treasury`). Projection tests `app(CompanyContext::class)->clear()` first (rule 20).
- [ ] **Step 5:** Commit `feat(treasury): POS bridges write movements via port with canonical-index idempotency`.

### Task 21: New POS return/refund projection bridge

**Files:** Create `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReturnBridge.php` + register in the projection dispatcher; Test `apps/api/tests/Feature/Treasury/PosReturnBridgeTest.php`.

**Interfaces:**
- Produces: a return/refund fiscal event → `movement(refund, out)` + GL reversal (Dr sales-return / Cr cash), keyed `fiscal_event:{event_id}:refund:{index}`, with **`allowWhileFrozen: true`** (offline device replay — HIGH-4; the movement's `sourceType` is `Refund` but freeze-safety comes from the explicit flag, not the source type).

- [ ] **Step 1:** Failing test — a POS return event decrements the drawer repository via a movement AND posts a GL reversal (today it does neither).
- [ ] **Step 2:** Run — FAIL. (Confirm the return event type + `handlesEventType` registration point first — grep the existing bridges' `handlesEventType`.)
- [ ] **Step 3:** Implement the bridge following `TreasuryReceiptBridge`'s structure; register its event type in the projection dispatcher and add its queue to `horizon.php` if a new one is introduced (rule 20 — HorizonQueueCoverageTest).
- [ ] **Step 4:** Run — PASS.
- [ ] **Step 5:** Commit `feat(treasury): POS return/refund projection bridge posts GL + movement`.

### Task 22: Cutover lock — delete old ports, forbid direct balance writes

**Files:**
- Delete: `RepositoryInflowService.php`, `RepositoryOutflowService.php`, `RepositoryInflowInterface.php`, `RepositoryOutflowInterface.php`
- Modify: `TreasuryServiceProvider.php` (remove the old bindings), `PaymentRepository.php` (`balance` out of `$fillable`)
- Create: migration `*_forbid_direct_payment_repository_balance_writes.php` (DB trigger), architecture test
- Test: `apps/api/tests/Feature/Treasury/DirectBalanceWriteForbiddenTest.php`, `apps/api/tests/Architecture/TreasuryBalanceWritePortTest.php`

**Interfaces:**
- Produces: a PG trigger on `payment_repositories` rejecting any UPDATE that changes `balance` unless a session GUC `app.treasury_movement_port = 'on'` is set (the port sets it via `SET LOCAL` inside its transaction); the old ports no longer exist.

- [ ] **Step 1: Write the failing tests** — (a) `PaymentRepository::find($id)->update(['balance' => '999'])` outside the port throws; (b) the port's `record()` still succeeds (it sets the GUC); (c) architecture test: no source file except `TreasuryMovementService` references `->balance =` on a `PaymentRepository` (grep-based, following the repo's existing architecture-test pattern — find one under `tests/Architecture`).
- [ ] **Step 2: Run — expect FAIL** (direct update currently succeeds).
- [ ] **Step 3: Implement.** Add `SET LOCAL app.treasury_movement_port = 'on'` at the start of the port's write transaction (both `record` and `transfer`). Migration trigger:

```sql
CREATE OR REPLACE FUNCTION forbid_direct_balance_write() RETURNS trigger AS $$
BEGIN
    IF NEW.balance IS DISTINCT FROM OLD.balance
       AND current_setting('app.treasury_movement_port', true) IS DISTINCT FROM 'on' THEN
        RAISE EXCEPTION 'payment_repositories.balance may only be changed via TreasuryMovementService (spec §5).'
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER forbid_direct_balance_write_trg BEFORE UPDATE ON payment_repositories
    FOR EACH ROW EXECUTE FUNCTION forbid_direct_balance_write();
```

The port already issues `SET LOCAL app.treasury_movement_port = 'on'` inside `record()`/`transfer()`, **before its idempotency savepoint** (Task 11, MED-10) — so this task adds only the trigger, not the GUC. Verify (MED-10 test) that a duplicate-key rollback-to-savepoint does NOT unset the GUC (it was set before the savepoint) and a subsequent balance write in the same outer transaction still passes the trigger. Note the freeze path: `freeze()`/`unfreeze()` (Task 13) update `frozen_at`/`frozen_reason` only — `NEW.balance IS DISTINCT FROM OLD.balance` is false, so the trigger correctly skips them.

Delete the four old-port files; remove their bindings from `TreasuryServiceProvider`; drop `balance` from `PaymentRepository::$fillable`. Run `grep -rn "applyInflow\|applyOutflow\|RepositoryInflowInterface\|RepositoryOutflowInterface" app/` and confirm ZERO remaining references (all migrated in Tasks 14-21).

- [ ] **Step 4: Run — expect PASS** (`./vendor/bin/phpunit tests/Feature/Treasury tests/Architecture`).
- [ ] **Step 5: Commit** `feat(treasury): cutover — delete old ports, DB-forbid direct balance writes`.

---

## WAVE E — Adjustments

### Task 23: Gated adjustment documents

**Files:** Create `RepositoryAdjustmentController.php`, `AdjustRepositoryRequest.php`, route; seed `treasury.adjust`; Test `apps/api/tests/Feature/Treasury/RepositoryAdjustmentTest.php`.

**Interfaces:**
- Produces: `POST /payment-repositories/{id}/adjustments` (`can:treasury.adjust`) — body `{direction, amount, reason_code, reason_text}`; writes `movement(adjustment)` + GL (cash ↔ variance account via `SystemAccountPurpose`) via `postEntryNow`; `reason_code` required; logged to `audit_events` (already automatic via `RepositoryMovementRecorded`).

- [ ] **Step 1:** Failing test — an adjustment (direction=out, reason=count_variance) decrements balance, creates a `movement(adjustment)` with `reason_code`, posts a balanced GL entry; missing `reason_text` → 422; without `treasury.adjust` permission → 403.
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement request/controller/route; resolve the variance account (`SystemAccountPurpose` — confirm the TN 658-family purpose name); seed the permission.
- [ ] **Step 4:** Run — PASS.
- [ ] **Step 5:** Commit `feat(treasury): gated repository adjustment documents`.

---

## WAVE F — Reconciliation

### Task 24: `treasury:reconcile` command + freeze-on-drift

**Files:** Create `ReconcileTreasuryCommand.php` (extends `TenantScopedCommand`), register in `Kernel`/scheduler; Test `apps/api/tests/Feature/Treasury/ReconcileTreasuryTest.php`.

**Interfaces:**
- Produces: `php artisan treasury:reconcile {--tenant=}` — per repository checks (1) `balance == Σ signed movements` AND `balance_after`/`ordinal` continuity, (2) every non-exempt movement's `journal_entry_id` exists with matching amount, (3) every `transfer_group_id` nets to zero; **on drift → `freeze(repositoryId, reason)` + alert (log + `audit_events`)**, never repair.

- [ ] **Step 1:** Failing tests — (a) a clean tenant reconciles green, no freeze; (b) a seeded drift (insert a movement whose `balance_after` breaks continuity — must be done pre-trigger or via a raw path in a fixture) freezes the repo + logs an alert; (c) after freeze, an interactive write throws (Task 13).
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement the command following `TenantScopedCommand` + an existing sweep command (`SweepInventoryClaimCommand`) for the tenant-loop pattern; use `TreasuryMovementService::freeze` on drift. Register in the scheduler (daily) in the console kernel.
- [ ] **Step 4:** Run — PASS.
- [ ] **Step 5:** Commit `feat(treasury): treasury:reconcile with freeze-on-drift`.

---

## WAVE G — Read surface + Frontend

### Task 25: Cash-position endpoint

**Files:** Create `CashPositionController.php` + route (`can:treasury.view`); Test `apps/api/tests/Feature/Treasury/CashPositionEndpointTest.php`.
- [ ] **Step 1:** Failing test — `GET /api/v1/treasury/cash-position` returns per-repository balances grouped by `type` (cash_register/bank_account/safe) + per-group totals + grand total + `as_of`, company-scoped.
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement (server-side aggregation over `payment_repositories`, replacing the FE client-side sum).
- [ ] **Step 4:** Run — PASS.
- [ ] **Step 5:** Commit `feat(treasury): server-side cash-position endpoint`.

### Task 26: Repository movements endpoint

**Files:** Create `RepositoryMovementController.php` + route; Test `apps/api/tests/Feature/Treasury/RepositoryMovementsEndpointTest.php`.
- [ ] **Step 1:** Failing test — `GET /api/v1/payment-repositories/{id}/movements` returns paginated `{data, meta}` movements (filters: `date_from/date_to`, `source_type`, `direction`), each row exposing `source_type/source_id` and `journal_entry_id` links, newest first.
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement (paginated; return `response.data` shape per rule 14).
- [ ] **Step 4:** Run — PASS.
- [ ] **Step 5:** Commit `feat(treasury): repository movements drill-down endpoint`.

### Task 27: FE — Movements tab + cash-position wiring + types

**Files:** Create `RepositoryMovementsTab.tsx`, `useRepositoryMovements.ts`, `useCashPosition.ts`; modify `RepositoryDetailPage.tsx` (add tab), `TreasuryOverviewPage.tsx` (use the endpoint); run `typescript:transform`; Test `apps/web/src/features/treasury/__tests__/RepositoryMovementsTab.test.tsx`.
- [ ] **Step 1:** Failing Vitest — the Movements tab renders rows from a mocked `useRepositoryMovements` with `formatCurrency`, source-doc + JE links, i18n keys (no hardcoded strings), `tenantScopedKey` query key.
- [ ] **Step 2:** Run — FAIL (`cd apps/web && pnpm test src/features/treasury/__tests__/RepositoryMovementsTab.test.tsx`). **Kill vitest worker pools if it hangs.**
- [ ] **Step 3:** Implement the hooks + tab; wire `TreasuryOverviewPage` to `useCashPosition`; add i18n keys to the `treasury` namespace (confirm it's registered in `i18n.ts`); regenerate types.
- [ ] **Step 4:** Run — PASS + `pnpm typecheck` + `pnpm lint`.
- [ ] **Step 5:** Commit `feat(web): repository movements tab + server cash-position wiring`.

### Task 28: Instrument list-page contract fix (G3)

**Files:** Modify `apps/web/src/features/treasury/InstrumentListPage.tsx`; Test update.
- [ ] **Step 1:** Failing Vitest — the list renders `reference`, `partner.name`, `repository.name`, `received_date`, `maturity_date` from the **actual** API shape (`PaymentInstrumentController::formatInstrument`), not the phantom `instrument_number/type/partner_name` fields; pagination reads the real response (no `data.meta.total` if the API returns none).
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Align the TS interface + column accessors to the API response.
- [ ] **Step 4:** Run — PASS + `pnpm typecheck`.
- [ ] **Step 5:** Commit `fix(web): align InstrumentListPage fields to API contract (G3)`.

---

## WAVE H — End-to-end verification

### Task 29: Live E2E (Playwright) on the db-per-tenant stack

**Files:** Create `apps/web/e2e/treasury-spine.spec.ts` (or the repo's Playwright location); no unit test.

- [ ] **Step 1:** Bring up the local db-per-tenant stack per `reference_local_db_per_tenant_demo_launch` (API :8010, multi-queue worker incl. `fiscal-projections`, DemoPharmacy seed). Run `php artisan tenants:migrate` so the new migrations apply.
- [ ] **Step 2:** Write the E2E: login → POS cash+card sale → open the drawer repository detail → **Movements tab shows the sale movements** → cash-position card reflects the new balance → post a paid expense → drawer→bank transfer → an adjustment → run `php artisan treasury:reconcile` → assert green (no freeze).
- [ ] **Step 3:** Run the E2E headed once, capture a screenshot of the Movements tab + cash-position for the owner.
- [ ] **Step 4:** Run `./scripts/preflight.sh` (PHPStan L8, Pint, TypeScript, ESLint) — treasury + accounting + expense suites by path, NOT the full PHPUnit suite.
- [ ] **Step 5:** Commit `test(treasury): live E2E — sale→movement→cash-position→reconcile green`.

---

## Self-Review

**Spec coverage (§ → task):** §4 movements table → T2/T3/T4; §5 port + savepoint ordering + currency guard → T10/T11/T12; §6 flow convergence (every row) → T14-T21; §7 adjustments → T23; §8 GL hardening (postEntryNow, chain race, period guard, journal_code, repo schema) → T1/T6/T7/T8/T9; §9 reconcile + freeze → T13/T24; §10 read surface → T25/T26/T27/T28; §11 deltas — all embedded; §12 certification traceability — trigger (T3), sequential ordinal (T2/T11), period lock (T8), journal_code (T9); §13 testing — every task is TDD + T29 E2E; §16 review findings — BLOCKER-1 T6, BLOCKER-2 T8, F3/F4 T20, F5 T11/T13, F6 T14/T15, F7 T12, F8 T14-T22 cutover, F9 T20, F10 T18, F11 T11, F12 T1/T11, F13 T5, F14 T20. **No gaps.**

**Placeholder scan:** none — every code step has real code or a precise surgical instruction with a verified anchor; the three "trace first" notes (§15 open questions) are explicit pre-work inside their tasks (T20 canonical index, T24 drift fixture, T8 resolver), not deferred implementation.

**Type consistency:** `record(MovementIntent): MovementResult` and `transfer(TransferIntent): TransferResult` consistent T10↔T11↔T12↔consumers; `postEntryNow(JournalEntry, ?User, ?string): void` + `PostingMode` seam consistent T6↔T14/T16/T17/T19; `MovementSourceType`/`MovementDirection` enum cases consistent throughout; `idempotencyKey()` format + `allowWhileFrozen` flag consistent T10↔T11↔T20↔T21.

## Plan-review reconciliation (Codex, 2026-07-08)

Full review + finding→edit map: `reviews/2026-07-08-treasury-spine-plan-codex-review.md` (2 BLOCKER / 6 HIGH / 5 MED / 2 LOW, all accepted; three load-bearing claims verified in code). Summary of Rev-2 changes: **§Global lock order** (advisory→repo, BLOCKER-1); Task 16 reordered draft→post→record + manual-refresh deletion (BLOCKER-2, LOW-13); Task 6 `PostingMode` seam (HIGH-3); explicit `allowWhileFrozen` flag replacing source-type inference (HIGH-4/5); new Task 16b request-level idempotency (HIGH-7); Task 18 `refund_request_id` (HIGH-6); Task 19 rewritten from real code (HIGH-8); `transactionLevel()>0` assertion (MED-9); `SET LOCAL` before savepoint (MED-10); currency `NOT NULL` + factory (MED-11); movement FKs (MED-12).

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-07-08-treasury-spine.md`.** Before execution, this plan itself goes through an **adversarial review** (per the owner's instruction and the every-milestone review rule). After that reconciles, execution options:

1. **Subagent-Driven (recommended)** — fresh subagent per task, `treasury-reviewer` gate between tasks, human merges. Codex/Opus dispatch for bounded tasks; Codex Desktop for worktree writes.
2. **Inline Execution** — batch with checkpoints via executing-plans.

