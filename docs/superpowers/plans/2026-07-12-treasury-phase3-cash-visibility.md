# Treasury Phase ③ — Cash-Visibility Read Layer: Implementation Plan (Rev 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> **Binding spec:** `docs/superpowers/specs/2026-07-12-treasury-phase3-cash-visibility-design.md` (**Rev 2** — §15 reconciliation is part of the contract). On any conflict between this plan and the spec, the spec wins and the deviation goes in the progress file.
> **Adversarially reviewed 2026-07-12** (2 lanes: Fable on Wave A, Opus on B–E — `docs/superpowers/plans/reviews/2026-07-12-treasury-phase3-plan-adversarial-review.md`); all findings reconciled in place, log at the end of this file. The review's "Verified-correct plan claims" sections are ground truth — implementers should NOT re-derive them.

**Goal:** Ship the Phase-③ cash-visibility layer: inter-repository transfer (G13, first production consumer of `TreasuryMovementService::transfer()`), platform notification center + treasury alert delivery, cash-movements report page (G12), and the cash-position dashboard widget (C9).

**Architecture:** One new Treasury application service (`RepositoryTransferService`) + one new draft-JE factory on `GeneralLedgerService` drive the port's existing `transfer()` — the port stays byte-untouched. A slim `Notification` module wraps Laravel-native database notifications; the two treasury alert commands gain a third, failure-isolated delivery channel. Read-layer changes are strictly additive (report `direction` filter + per-currency totals; cash-position `flows_window`).

**Tech Stack:** Laravel 12 / PHP 8.2 (PHPUnit, phpstan L8, pint) · React 19 / Vite / TanStack Query 5 / RHF+zod / Tailwind 4 design tokens (Vitest) · PostgreSQL 16 (db-per-tenant).

## Global Constraints (apply to every task)

- **Rule 19:** money = canonical decimal strings; `CurrencyScale::bcformatStrict` + `CurrencyScaleResolverInterface` (constructor-injected, explicit currency arg); FE uses `<MoneyInput>` (shared atom `@/components/atoms/MoneyInput`, NOT the POS one) + `formatCurrency` from `@/lib/format`; never `parseFloat`/`Number()` on money.
- **Rule 13:** constructor injection with `private readonly`; never `app()` in app code (test bootstrapping may use `app()`).
- **Rule 12:** module routes: `['api','auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` (mirror `app/Modules/Treasury/Presentation/routes.php:31`).
- **Rule 14:** paginated `{data,meta}` endpoints on FE use `api.get` + `response.data` (never `apiGet` — it drops `meta`).
- **Rule 18:** design tokens only (`tokens`/`textColors`/`borderColors` from `@/lib/designTokens`); `node apps/web/tools/audit-design-system.mjs` must report 0 new.
- **Rule 11/i18n:** all user-facing text via `t()`; new keys in **en + fr + ar** for the `treasury`/`finance` namespaces (all three exist), en + fr + ar for the new `notifications` namespace.
- **Tenant query keys:** every `useQuery` key wrapped in `tenantScopedKey([...])` (`audit-tanstack-keys.mjs` gates it). Invalidations MAY use raw prefixes where the spec says so (§5.5).
- **Tests:** PHPUnit **by path** (`./vendor/bin/phpunit tests/Feature/Treasury` etc. — NEVER the full suite); Vitest **by path**; valid UUIDs for all FK columns; `RolesAndPermissionsSeeder` for permission tests.
- **Canonical error envelope only** for new endpoints: `DomainException` → global handler `{error:{code:'BUSINESS_ERROR',message}}` 422. No flat-string 422s.
- **Port inviolate:** `TreasuryMovementService` public methods byte-untouched. Fiscal perimeter untouched.
- **Interlocks:** do NOT edit `RepositoryDetailPage.tsx`, `ExpenseDetailPage.tsx` (feat/treasury-ui-gaps), anything under `banks`/`BankPicker` (feat/bank-reference-verification).
- Commit after every task (conventional commits).

## File Structure (locked)

```
apps/api/
  database/migrations/tenant/2026_07_12_100000_unique_journal_entries_source_treasury_transfer.php   [A1]
  database/migrations/tenant/2026_07_12_110000_create_notifications_table.php                        [B1]
  app/Modules/Accounting/Domain/Services/GeneralLedgerService.php               [A2 modify: +createRepositoryTransferJournalEntry]
  app/Modules/Treasury/Application/DTOs/RepositoryTransferResult.php            [A3]
  app/Modules/Treasury/Application/Services/RepositoryTransferService.php       [A3]
  app/Modules/Treasury/Presentation/Requests/TransferRepositoryRequest.php      [A4]
  app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php[A4]
  app/Modules/Treasury/Presentation/routes.php                                  [A4 modify]
  database/seeders/PermissionSeeder.php + RolesAndPermissionsSeeder.php         [A4 modify]
  app/Modules/Notification/Providers/NotificationServiceProvider.php            [B2]
  app/Modules/Notification/Presentation/routes.php                              [B2]
  app/Modules/Notification/Presentation/Controllers/NotificationController.php  [B2]
  bootstrap/providers.php                                                       [B2 modify]
  app/Modules/Treasury/Application/Notifications/TreasuryAlertNotification.php  [B3]
  app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php         [B3]
  app/Modules/Treasury/Presentation/Console/ReconcileTreasuryCommand.php        [B4 modify]
  app/Modules/Treasury/Presentation/Console/InstrumentMaturityAlertsCommand.php [B4 modify]
  app/Modules/Accounting/.../Reports/CashMovementsReportService.php + GetCashMovementsRequest.php [C1 modify]
  app/Modules/Treasury/Presentation/Controllers/CashPositionController.php      [C2 modify]
apps/web/src/
  hooks/usePermissions.ts                                                       [D1 modify]
  features/treasury/hooks/useTransferCash.ts                                    [D2]
  features/treasury/components/TransferCashModal.tsx                            [D2]
  features/treasury/RepositoryListPage.tsx                                      [D2 modify]
  features/notifications/{api/notificationsApi.ts,hooks/useNotifications.ts,components/NotificationBell.tsx,components/NotificationPanel.tsx} [D3]
  components/organisms/TopBar/TopBar.tsx                                        [D3 modify]
  locales/{en,fr,ar}/notifications.json + i18n.ts (3 touch points)              [D3]
  features/finance/pages/CashMovementsReportPage.tsx                            [D4]
  routes/index.tsx + components/organisms/Sidebar/Sidebar.tsx                   [D4 modify]
  features/treasury/hooks/useCashPosition.ts                                    [D5 modify]
  features/treasury/components/CashPositionWidget.tsx                           [D5]
  features/owner-dashboard/OwnerDashboardPage.tsx + features/dashboard/Dashboard.tsx [D5 modify]
  locales/{en,fr,ar}/treasury.json + finance.json                               [D2/D4/D5 modify]
docs/handoff/treasury-phase3-progress.md                                        [every task]
docs/sessions/treasury-phase3-e2e/                                              [E1, gitignored screenshots]
docs/handoff/treasury-phase3-deploy-checklist.md                                [E2]
```

Waves: **A** = Tasks A1–A5 (transfer money path) · **B** = B1–B4 (notification center) · **C** = C1–C2 (read-layer backend) · **D** = D1–D5 (FE) · **E** = E1–E2 (E2E + docs). Waves A–C are backend-only and independent of each other after A; D depends on A–C; E last. Gates are defined in the Codex brief (Gate 1 after A, Gate 2 after B+C, Gate 3 after D, Gate 4 after E).

---

### Task A1: Status-scoped partial unique index for treasury_transfer JEs

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_12_100000_unique_journal_entries_source_treasury_transfer.php`
- Test: covered by A5's replay tests (an index alone is not unit-tested; its behavior is pinned there)

**Interfaces:**
- Produces: DB invariant — at most one **posted** `journal_entries` row per (`source_type='treasury_transfer'`, `source_id`). Drafts are exempt (spec §5.3 / L1-1 — an unscoped index 500s every replay at the draft INSERT).

- [ ] **Step 1: Read the convention exemplar** `apps/api/database/migrations/tenant/2026_06_26_120000_unique_journal_entries_source_procurement.php` — mirror its driver guards/structure exactly, changing only the predicate.

- [ ] **Step 2: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One POSTED journal entry per treasury transfer group (spec Rev 2 §5.3).
 * Status-scoped deliberately: the replay path re-inserts a Draft with the
 * same (source_type, source_id) on every attempt and cleans it up after
 * transfer() resolves — an unscoped index would 23505 at the draft INSERT,
 * outside transfer()'s catch, breaking idempotent replay (review L1-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        // NO driver guard (plan-review F3): the procurement exemplar runs
        // unconditionally and sqlite supports partial indexes — guarding
        // would silently remove index coverage from the sqlite fast loop.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS journal_entries_treasury_transfer_source_unique
            ON journal_entries (source_type, source_id)
            WHERE source_type = 'treasury_transfer' AND status = 'posted'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS journal_entries_treasury_transfer_source_unique');
    }
};
```
(Verified: `journal_entries.status` stores lowercase `'draft'|'posted'|'reversed'` strings — the predicate value is correct. Reversal entries use distinct `*_reversal` source_types — no collision. The `AND status = 'posted'` predicate is the spec-review BLOCKER fix and non-negotiable.)

- [ ] **Step 3: Run the migration against the test DB**

Run: `cd apps/api && php artisan migrate --path=database/migrations/tenant/2026_07_12_100000_unique_journal_entries_source_treasury_transfer.php --pretend` (then a real `migrate` in the test env used by the suite).
Expected: SQL shown/applied without error.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/tenant/2026_07_12_100000_unique_journal_entries_source_treasury_transfer.php
git commit -m "feat(treasury): status-scoped unique index for treasury_transfer journal entries"
```

---

### Task A2: `GeneralLedgerService::createRepositoryTransferJournalEntry` (Draft — never posts)

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` (add method adjacent to `createRepositoryAdjustmentJournalEntry`, which starts at `:881`)
- Test: `apps/api/tests/Feature/Treasury/RepositoryTransferServiceTest.php` (created here with the first test; grows in A3/A5)

**Interfaces:**
- Consumes: existing `generateEntryNumber`, `JournalEntry`/`JournalLine` models, `JournalCode::fromSourceType` (falls through to `Misc`/OD for `'treasury_transfer'` — do NOT add a match arm, spec D-1).
- Produces: `public function createRepositoryTransferJournalEntry(string $companyId, string $tenantId, string $transferGroupId, string $fromGlAccountId, string $toGlAccountId, string $amount, \DateTimeInterface $date, string $description): JournalEntry` — returns a **Draft** entry with lines loaded. A3 passes `$entry->id` into `TransferIntent`; `transfer()` posts it.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

// mirror the use-block + setUp of tests/Feature/Treasury/TreasuryMovementServiceTransferTest.php
// (RefreshDatabase, RolesAndPermissionsSeeder, company + chart fixtures)

public function test_transfer_journal_entry_is_created_as_draft_and_never_posted(): void
{
    [$company, $cashAccount, $bankAccount] = $this->seedCompanyWithCashAndBankAccounts();

    $entry = null;
    DB::transaction(function () use ($company, $cashAccount, $bankAccount, &$entry): void {
        $entry = app(GeneralLedgerService::class)->createRepositoryTransferJournalEntry(
            companyId: $company->id,
            tenantId: $company->tenant_id,
            transferGroupId: (string) Str::uuid(),
            fromGlAccountId: $cashAccount->id,
            toGlAccountId: $bankAccount->id,
            amount: '100.000',
            date: now(),
            description: 'Transfert CASH-01 → BANK-01',
        );
    });

    $this->assertSame(JournalEntryStatus::Draft, $entry->status);          // NOT posted
    $this->assertSame('treasury_transfer', $entry->source_type);
    $this->assertCount(2, $entry->lines);
    $debit = $entry->lines->firstWhere('account_id', $bankAccount->id);    // Dr DESTINATION
    $credit = $entry->lines->firstWhere('account_id', $cashAccount->id);   // Cr SOURCE
    $this->assertSame('100.000', $debit->debit);
    $this->assertSame('100.000', $credit->credit);
}

public function test_transfer_journal_entry_requires_enclosing_transaction(): void
{
    // RefreshDatabase wraps each test in a transaction (level starts at 1) —
    // pop it so we are genuinely at level 0, else the guard never fires and
    // this test can NEVER pass (plan-review F1). Pattern copied from
    // tests/Feature/Treasury/TreasuryMovementServiceRecordTest.php:226-246.
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $this->assertSame(0, DB::transactionLevel());

    try {
        $this->expectException(\LogicException::class);
        app(GeneralLedgerService::class)->createRepositoryTransferJournalEntry(/* same args as the happy-path test */);
    } finally {
        DB::beginTransaction(); // restore the wrapper for RefreshDatabase teardown
    }
}
```

- [ ] **Step 2: Run to verify failure** — `./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferServiceTest.php` → FAIL (method undefined).

- [ ] **Step 3: Implement** (place directly after `createRepositoryAdjustmentJournalEntry`; mirror its structure MINUS the final `postEntryNow` call):

```php
/**
 * Create the Draft GL entry for an inter-repository cash transfer
 * (Phase ③ spec §5.2/§5.3). Dr destination repo account / Cr source repo
 * account. UNLIKE createRepositoryAdjustmentJournalEntry this NEVER posts:
 * TreasuryMovementService::transfer() posts it via postEntryNow inside its
 * own lock scope, and the replay path depends on the entry still being a
 * Draft when the savepoint rolls back (spec §5.2.3).
 */
public function createRepositoryTransferJournalEntry(
    string $companyId,
    string $tenantId,
    string $transferGroupId,
    string $fromGlAccountId,
    string $toGlAccountId,
    string $amount,
    \DateTimeInterface $date,
    string $description,
): JournalEntry {
    if (DB::transactionLevel() < 1) {
        throw new \LogicException('createRepositoryTransferJournalEntry: requires an enclosing database transaction; the caller must be able to roll the Draft back if transfer() fails.');
    }

    return DB::transaction(function () use (
        $companyId, $tenantId, $transferGroupId, $fromGlAccountId, $toGlAccountId, $amount, $date, $description
    ): JournalEntry {
        $entry = JournalEntry::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'entry_number' => $this->generateEntryNumber($companyId),
            'entry_date' => $date,
            'description' => $description,
            'status' => JournalEntryStatus::Draft,
            'source_type' => 'treasury_transfer',
            'journal_code' => JournalCode::fromSourceType('treasury_transfer')->value,
            'source_id' => $transferGroupId,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $toGlAccountId,
            'partner_id' => null,
            'debit' => $amount,
            'credit' => '0',
            'description' => 'Inter-repository transfer (in)',
            'line_order' => 0,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $fromGlAccountId,
            'partner_id' => null,
            'debit' => '0',
            'credit' => $amount,
            'description' => 'Inter-repository transfer (out)',
            'line_order' => 1,
        ]);

        return $entry->load('lines');
    });
}
```

- [ ] **Step 4: Run to verify pass** — same command → PASS. Also `./vendor/bin/phpstan` (0 new) + `./vendor/bin/pint --dirty`.

- [ ] **Step 5: Commit** — `git commit -m "feat(accounting): draft JE factory for inter-repository transfers"`

---

### Task A3: `RepositoryTransferService` + result DTO

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferResult.php`
- Create: `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php`
- Test: `apps/api/tests/Feature/Treasury/RepositoryTransferServiceTest.php` (extend)

**Interfaces:**
- Consumes: A2's `createRepositoryTransferJournalEntry`; port `TreasuryMovementService::transfer(TransferIntent): TransferResult` (fields per `app/Modules/Treasury/Application/DTOs/TransferIntent.php:14-39` — use named args); `RepositoryFrozenException(string $repositoryId, string $frozenReason)`; `RepositoryMovement` model; `CurrencyScale::bcformatStrict`; `CurrencyScaleResolverInterface`.
- Produces:
```php
final readonly class RepositoryTransferResult {
    public function __construct(
        public string $transferGroupId,
        public ?string $journalEntryId,   // ALWAYS from the persisted out-leg row (spec §5.1/L1-3)
        public MovementResult $out,
        public MovementResult $in,
        public bool $idempotentReplay,
    ) {}
}
// Service:
public function transfer(
    string $tenantId, string $companyId,
    string $fromRepositoryId, string $toRepositoryId,
    string $amount, ?string $notes, ?string $transferGroupId, string $userId,
): RepositoryTransferResult
```

- [ ] **Step 1: Write the failing tests** (extend the A2 test file; fixtures: two cash repos sharing the Cash GL account + one bank repo on the Bank account, mirroring `PaymentRepositorySeeder` shapes):

```php
public function test_cross_gl_transfer_posts_one_je_and_two_netting_legs(): void
{
    $result = $this->service()->transfer(
        tenantId: $this->tenant->id, companyId: $this->company->id,
        fromRepositoryId: $this->cashRepo->id, toRepositoryId: $this->bankRepo->id,
        amount: '250.000', notes: 'remise espèces', transferGroupId: null, userId: $this->user->id,
    );

    $this->assertFalse($result->idempotentReplay);
    $entry = JournalEntry::findOrFail($result->journalEntryId);
    $this->assertSame(JournalEntryStatus::Posted, $entry->status);
    // Dr bank / Cr cash, amounts exact
    $out = RepositoryMovement::findOrFail($result->out->movementId);
    $in  = RepositoryMovement::findOrFail($result->in->movementId);
    $this->assertSame($entry->id, $out->journal_entry_id);
    $this->assertSame($entry->id, $in->journal_entry_id);
    $this->assertSame($out->transfer_group_id, $in->transfer_group_id);
    $this->assertSame('0.000', bcadd(bcmul($out->amount, '-1', 3), $in->amount, 3)); // legs net to zero
}

public function test_same_gl_transfer_posts_no_je(): void
{
    $result = $this->service()->transfer(/* cashRepo → safeRepo (both on Cash account) */);
    $this->assertNull($result->journalEntryId);
    $this->assertSame(0, JournalEntry::where('source_type', 'treasury_transfer')->count());
}

public function test_frozen_source_and_destination_are_rejected_by_the_service(): void
// freeze via the port's freeze() (the ONLY writer of frozen_at), assert RepositoryFrozenException
// for source-frozen AND destination-frozen, and assert NO draft JE row exists afterward.

public function test_virtual_repository_is_rejected_both_directions(): void   // DomainException, 422 class
public function test_currency_mismatch_leaves_no_orphan_draft(): void
// bankRepo with currency EUR vs cash TND → CurrencyMismatchException propagates,
// assert JournalEntry::where('source_type','treasury_transfer')->count() === 0  (outer rollback proof — L1-2c)
public function test_cross_gl_with_missing_gl_account_is_422_before_any_write(): void
// cross-GL pair, one side gl_account_id = null → DomainException AND
// JournalEntry::where('source_type','treasury_transfer')->count() === 0 (plan-review F6)
public function test_amount_is_normalized_at_source_currency_scale(): void
// EUR(2) repos: amount '10.005' → normalized to '10.00' at the boundary.
// CAUTION (plan-review F7): RepositoryMovement casts amount decimal:3, so the
// MODEL reads back '10.000' — assert bccomp($out->amount, '10.00', 2) === 0,
// or pin raw storage via DB::table('repository_movements').
```

- [ ] **Step 2: Run to verify failure** — service class undefined.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Treasury\Application\DTOs\RepositoryTransferResult;
use App\Modules\Treasury\Application\DTOs\TransferIntent;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\CurrencyScaleResolverInterface; // VERIFIED (plan-review F4) — app/Shared/Contracts/CurrencyScaleResolverInterface.php:14
use App\Shared\Domain\CurrencyScale;                      // VERIFIED (plan-review F4) — app/Shared/Domain/CurrencyScale.php:13; bcformatStrict(string,int): string at :130
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RepositoryTransferService
{
    private const TRANSFERABLE_TYPES = [RepositoryType::CashRegister, RepositoryType::Safe, RepositoryType::BankAccount];

    public function __construct(
        private readonly GeneralLedgerService $generalLedger,
        private readonly TreasuryMovementService $movementService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function transfer(
        string $tenantId,
        string $companyId,
        string $fromRepositoryId,
        string $toRepositoryId,
        string $amount,
        ?string $notes,
        ?string $transferGroupId,
        string $userId,
    ): RepositoryTransferResult {
        $from = $this->resolveRepository($tenantId, $companyId, $fromRepositoryId);
        $to = $this->resolveRepository($tenantId, $companyId, $toRepositoryId);

        // Freeze check lives HERE — transfer() has none (spec §5.1, review L1-2).
        foreach ([$from, $to] as $repo) {
            if ($repo->frozen_at !== null) {
                throw new RepositoryFrozenException($repo->id, (string) $repo->frozen_reason);
            }
        }

        // Rule 19: normalize ONCE at the boundary, at the source repo's scale.
        $amount = CurrencyScale::bcformatStrict($amount, $this->scaleResolver->getScale($from->currency));

        $crossGl = $from->gl_account_id !== $to->gl_account_id;
        if ($crossGl && ($from->gl_account_id === null || $to->gl_account_id === null)) {
            throw new \DomainException('Both repositories must have a linked GL account for a cross-account transfer.');
        }

        $groupId = $transferGroupId ?? (string) Str::uuid();

        return DB::transaction(function () use ($from, $to, $amount, $notes, $groupId, $crossGl, $tenantId, $companyId, $userId): RepositoryTransferResult {
            $draft = null;
            if ($crossGl) {
                $draft = $this->generalLedger->createRepositoryTransferJournalEntry(
                    companyId: $companyId,
                    tenantId: $tenantId,
                    transferGroupId: $groupId,
                    fromGlAccountId: (string) $from->gl_account_id,
                    toGlAccountId: (string) $to->gl_account_id,
                    amount: $amount,
                    date: now(),
                    description: trim("Transfert {$from->code} → {$to->code}".($notes !== null ? ": {$notes}" : '')),
                );
            }

            $result = $this->movementService->transfer(new TransferIntent(
                fromRepositoryId: $from->id,
                toRepositoryId: $to->id,
                tenantId: $tenantId,
                companyId: $companyId,
                amount: $amount,
                currency: $from->currency,
                transferGroupId: $groupId,
                journalEntryId: $draft?->id,
                occurredAt: null,
                createdBy: $userId,
                notes: $notes,
            ));

            // §5.2.3d uniform compensating cleanup: the persisted out-leg row is
            // the source of truth for the JE id (replay → original entry;
            // gl-reassignment TOCTOU → null). Delete our draft if unreferenced.
            $outLeg = RepositoryMovement::query()->findOrFail($result->outLeg->movementId);
            if ($draft !== null && $outLeg->journal_entry_id !== $draft->id) {
                $draft->delete();
            }

            return new RepositoryTransferResult(
                transferGroupId: $groupId,
                journalEntryId: $outLeg->journal_entry_id,
                out: $result->outLeg,
                in: $result->inLeg,
                idempotentReplay: $result->outLeg->wasIdempotentHit && $result->inLeg->wasIdempotentHit,
            );
        });
    }

    private function resolveRepository(string $tenantId, string $companyId, string $id): PaymentRepository
    {
        $repo = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($id);

        if (! $repo->is_active) {
            throw new \DomainException("Repository {$repo->code} is inactive and cannot take part in a transfer.");
        }
        if (! in_array($repo->type, self::TRANSFERABLE_TYPES, true)) {
            throw new \DomainException("Repository {$repo->code} is a virtual bucket; cash transfers require a physical repository (register, safe, or bank account).");
        }

        return $repo;
    }
}
```
**All signatures VERIFIED by the plan review (do not re-derive):** `TransferIntent` constructor names/order match the named-args call above (`TransferIntent.php:26-38`; `occurredAt` is `?CarbonImmutable` — pass `null`, NEVER `now()` which is Carbon → TypeError); `TransferResult->outLeg/inLeg`; `MovementResult->movementId/balanceAfter/ordinal/wasIdempotentHit`; `RepositoryFrozenException(string $repositoryId, string $frozenReason)`; `getScale('EUR')` short-circuits to the static ISO map (never touches CompanyContext) → EUR scale 2. `TreasuryMovementService` needs no import (same namespace). All error messages above are backend `DomainException` text — canonical envelope, no server-side i18n (adjustment-endpoint precedent).

- [ ] **Step 4: Run to verify pass** — `./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferServiceTest.php` → PASS; phpstan 0 new; pint.

- [ ] **Step 5: Commit** — `git commit -m "feat(treasury): RepositoryTransferService — first production consumer of the transfer port"`

---

### Task A4: HTTP surface — request, controller, route, permission

**Files:**
- Create: `apps/api/app/Modules/Treasury/Presentation/Requests/TransferRepositoryRequest.php`
- Create: `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php`
- Modify: `apps/api/app/Modules/Treasury/Presentation/routes.php` (after the adjustments route at `:92-94`)
- Modify: `apps/api/database/seeders/PermissionSeeder.php` (`:88-89` area) + `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (`treasury.adjust` appears at `:224`, granted at `:474`/`:694` — add `treasury.transfer` beside it in ALL three places)
- Test: `apps/api/tests/Feature/Treasury/RepositoryTransferEndpointTest.php`

**Interfaces:**
- Consumes: A3's `RepositoryTransferService::transfer(...)` + `RepositoryTransferResult`.
- Produces: `POST /api/v1/payment-repositories/transfers` (name `payment-repositories.transfers.store`, `can:treasury.transfer`) with the spec §5.1 response contract.

- [ ] **Step 1: Write the failing tests**

```php
public function test_transfer_requires_permission(): void            // user without treasury.transfer → 403
public function test_transfer_happy_path_returns_contract_shape(): void
// assert 201 + json structure: data.{transfer_group_id, journal_entry_id, idempotent_replay,
//   out:{movement_id,balance_after,repository_id}, in:{...}} and balances actually moved
public function test_validation_rejects_same_repo_and_bad_amounts(): void
// to == from → 422 (FormRequest); amount '10.0005' → 422 regex; amount '-5' → 422; missing fields → 422
public function test_cross_company_repository_is_404(): void
public function test_client_transfer_group_id_makes_double_submit_idempotent(): void
// two identical POSTs with the same transfer_group_id →
//   1st: 201 idempotent_replay=false; 2nd: 201 idempotent_replay=true,
//   SAME journal_entry_id both times (out-leg resolution — L1-3),
//   exactly ONE posted treasury_transfer JE, NO draft JEs left  ← REQUIRED pin (L1-1)
public function test_race_shape_replay_via_preexisting_posted_je_and_legs(): void
// Arrange the post-commit race shape directly: run one successful service transfer for group G,
// then POST the endpoint with transfer_group_id=G. Asserts the §5.2.3c path: 201 replay,
// original JE id returned, draft cleaned.
// SANCTIONED SPEC SUBSTITUTION (plan-review F2, recorded in spec §15 amendment A-1):
// this sequential shape exercises the IDENTICAL 23505-inside-savepoint path as a true
// two-connection race (Fable lane verified the mechanics: with the A1 index the violation
// fires at postEntryNow's status-flip UPDATE, GeneralLedgerService.php:2912, inside the
// savepoint and caught at TreasuryMovementService.php:298-304; on sqlite the legs'
// idempotency_key unique fires instead — also caught). The port suite does NOT have a
// two-connection test — do not claim it does. Log this substitution as a deviation entry
// in docs/handoff/treasury-phase3-progress.md when implementing.
public function test_domain_failures_use_canonical_envelope(): void
// frozen repo → 422 {error:{code:'BUSINESS_ERROR'}}; virtual repo → 422; inactive → 422;
// assert response json path error.code === 'BUSINESS_ERROR' (never a flat string)
```

- [ ] **Step 2: Run to verify failure** — route undefined → 404s.

- [ ] **Step 3: Implement**

`TransferRepositoryRequest` rules (authorize() returns true — route middleware gates):
```php
public function rules(): array
{
    return [
        'from_repository_id' => ['required', 'uuid'],
        'to_repository_id' => ['required', 'uuid', 'different:from_repository_id'],
        'amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'],
        'notes' => ['nullable', 'string', 'max:1000'],
        'transfer_group_id' => ['nullable', 'uuid'],
    ];
}
```

Controller (constructor-injects `CompanyContext` + `RepositoryTransferService`; mirror `RepositoryAdjustmentController` structure):
```php
public function store(TransferRepositoryRequest $request): JsonResponse
{
    $company = $this->companyContext->requireCompany();
    $validated = $request->validated();
    /** @var User $user */
    $user = $request->user();

    $result = $this->transferService->transfer(
        tenantId: $company->tenant_id,
        companyId: $company->id,
        fromRepositoryId: (string) $validated['from_repository_id'],
        toRepositoryId: (string) $validated['to_repository_id'],
        amount: (string) $validated['amount'],
        notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
        transferGroupId: isset($validated['transfer_group_id']) ? (string) $validated['transfer_group_id'] : null,
        userId: $user->id,
    );

    return response()->json([
        'message' => __('messages.treasury.transfer_recorded'),
        'data' => [
            'transfer_group_id' => $result->transferGroupId,
            'journal_entry_id' => $result->journalEntryId,
            'idempotent_replay' => $result->idempotentReplay,
            'out' => ['movement_id' => $result->out->movementId, 'balance_after' => $result->out->balanceAfter, 'repository_id' => (string) $validated['from_repository_id']],
            'in' => ['movement_id' => $result->in->movementId, 'balance_after' => $result->in->balanceAfter, 'repository_id' => (string) $validated['to_repository_id']],
        ],
    ], 201);
}
```
**Exception mapping — VERIFIED (plan-review, no contingency code needed):** `RepositoryFrozenException`, `CurrencyMismatchException`, AND `IdempotencyConflictException` all extend `DomainException` (`RepositoryFrozenException.php:17`, `CurrencyMismatchException.php:16`, `IdempotencyConflictException.php:21`) and no earlier render closure shadows them — everything flows to the canonical `{error:{code:'BUSINESS_ERROR',message}}` 422 at `bootstrap/app.php:356-364` automatically. Do NOT add catch-and-rethrow code. FormRequest 422s use the default Laravel `{message, errors:{field:[...]}}` shape (no custom ValidationException render exists). Add the `messages.treasury.transfer_recorded` line to the backend lang files — **en + fr only** (`lang/` has no `ar/` dir — plan-review F8), beside `messages.treasury.*` at `lang/en/messages.php:65` + `lang/fr/messages.php:65`.

**sqlite note (plan-review F9):** the replay tests should pass on the sqlite fast loop (`isUniqueViolation` handles sqlite's 23000/"UNIQUE constraint failed"). If they misbehave on sqlite, use the port suite's `markTestSkipped`-on-non-pgsql pattern (`TreasuryMovementServiceTransferTest.php:414-417`) — pgsql coverage is guaranteed by the `treasury-spine-pgsql` CI job. NEVER "fix" the port or the index to appease sqlite.

Route (after the adjustments block):
```php
Route::post('payment-repositories/transfers', [RepositoryTransferController::class, 'store'])
    ->middleware('can:treasury.transfer')
    ->name('payment-repositories.transfers.store');
```

Seeders: add `'treasury.transfer'` to the PermissionSeeder base list AND to RolesAndPermissionsSeeder's permission catalog + every role bundle line that currently grants `treasury.adjust` (`:474`, `:694` — grep `treasury.adjust` to be exhaustive).

- [ ] **Step 4: Run to verify pass** — `./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferEndpointTest.php` → PASS; phpstan; pint.

- [ ] **Step 5: Commit** — `git commit -m "feat(treasury): POST /payment-repositories/transfers endpoint + treasury.transfer permission"`

---

### Task A5: Reconcile-green pin + transfer suite hardening

**Files:**
- Test (extend): `apps/api/tests/Feature/Treasury/RepositoryTransferEndpointTest.php`

**Interfaces:**
- Consumes: everything A1–A4. Produces: the Phase-② Task 8/9-style in-test reconcile pin.

- [ ] **Step 1: Write the failing/pinning test**

```php
public function test_reconcile_stays_green_after_mixed_transfers(): void
{
    // 1 cross-GL endpoint transfer (cash→bank), 1 same-GL (cash→safe),
    // 1 idempotent replay of the first (same transfer_group_id re-POSTed)
    // ... three POSTs as in A4 tests ...

    // Plan-review F5: WITHOUT --tenant the command iterates the central tenant
    // directory — if the fixture tenant isn't registered there it reconciles
    // ZERO repositories and exits 0 (vacuously green). Also clear CompanyContext
    // first (rule 20 — the preceding HTTP requests may have left it bound).
    app(CompanyContext::class)->clear();
    $exitCode = Artisan::call('treasury:reconcile', ['--tenant' => $this->tenant->id]);
    $this->assertSame(0, $exitCode);

    $this->assertSame(0, PaymentRepository::query()
        ->whereNotNull('frozen_at')->count(), 'reconcile froze a repository after spec-shaped transfers');
    $this->assertSame(0, DB::table('audit_events')
        ->where('event_type', 'treasury.reconcile.drift')->count());
}
```
(Invocation pattern copied from `tests/Feature/Treasury/ReconcileTreasuryTest.php:228-231` — `Artisan::call` with `--tenant`, `CompanyContext` cleared in setUp per `:79-81`.)

- [ ] **Step 2: Run** — should PASS immediately if A1–A4 are correct; if it fails, the failure is a real design defect: STOP and re-read spec §5 before touching reconcile code (never "fix" the reconcile command to make this pass).

- [ ] **Step 3: Commit** — `git commit -m "test(treasury): reconcile stays green across cross-GL/same-GL/replayed transfers"`

---

### Task B1: `notifications` table (tenant migration)

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_12_110000_create_notifications_table.php`

**Interfaces:**
- Produces: Laravel-standard uuid-morphs notifications table — `DatabaseChannel` and `$user->notifications()`/`unreadNotifications()` work unmodified.

- [ ] **Step 1: Write the migration**

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');     // notifiable_type + uuid notifiable_id + composite index
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']); // unread-count poll path (leads with the morph type — plan-review L5)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
```

- [ ] **Step 2: Migrate + smoke** — in a feature test (goes in B2's file): `$user->notify(...)` writes a row into the tenant DB.

- [ ] **Step 3: Commit** — `git commit -m "feat(notifications): tenant notifications table (Laravel database channel)"`

---

### Task B2: `Notification` module — provider, routes, controller

**Files:**
- Create: `apps/api/app/Modules/Notification/Providers/NotificationServiceProvider.php` (copy `Cart/Providers/CartServiceProvider.php` shape: `boot()` → `loadRoutesFrom(__DIR__.'/../Presentation/routes.php')`)
- Create: `apps/api/app/Modules/Notification/Presentation/routes.php`
- Create: `apps/api/app/Modules/Notification/Presentation/Controllers/NotificationController.php`
- Modify: `apps/api/bootstrap/providers.php` (add the provider — WITHOUT this every route 404s, review L3-5)
- Test: `apps/api/tests/Feature/Notification/NotificationEndpointsTest.php`

**Interfaces:**
- Produces: `GET /api/v1/notifications` (paginated `{data,meta}`, `?filter=unread|all`, `?page/per_page`) · `GET /api/v1/notifications/unread-count` → `{data:{count:int}}` · `POST /api/v1/notifications/{id}/read` (uuid-constrained) · `POST /api/v1/notifications/read-all`. Item shape: `{id, type, data, read_at, created_at}`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_index_returns_only_own_notifications_paginated(): void
public function test_unread_filter_and_count(): void
public function test_mark_read_is_idempotent_and_scoped(): void
// other user's notification id → 404; own → read_at set; second call → still 200
public function test_malformed_id_is_404_not_500(): void   // POST /notifications/not-a-uuid/read → 404 (L3-4)
public function test_read_all(): void
```

- [ ] **Step 2: Run to verify failure** — 404s (module not registered).

- [ ] **Step 3: Implement**

routes.php:
```php
Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])
    ->group(function (): void {
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->whereUuid('id')->name('notifications.read');
    });
```

Controller (no `can:` gates — ownership IS the authorization; every query roots at `$request->user()->notifications()`):
```php
final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', '15')));
        $query = $request->user()->notifications()->orderByDesc('created_at');
        if ($request->query('filter') === 'unread') {
            $query->whereNull('read_at');
        }
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn ($n) => [
                'id' => $n->id, 'type' => $n->type, 'data' => $n->data,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at->toIso8601String(),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
                       'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['count' => $request->user()->unreadNotifications()->count()]]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id); // whereUuid guards malformed ids at the route
        $notification->markAsRead();
        return response()->json(['message' => 'ok']);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();
        return response()->json(['message' => 'ok']);
    }
}
```

- [ ] **Step 4: Run to verify pass**; phpstan (strict types on the controller — add param/return types; no `mixed`); pint.

- [ ] **Step 5: Commit** — `git commit -m "feat(notifications): slim Notification module — inbox read API"`

---

### Task B3: `TreasuryAlertNotification` + recipient resolver

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/Notifications/TreasuryAlertNotification.php`
- Create: `apps/api/app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php`
- Test: `apps/api/tests/Feature/Treasury/TreasuryAlertRecipientsTest.php`

**Interfaces:**
- Produces:
```php
// Notification — database channel only (mail = spec D-4, off):
new TreasuryAlertNotification(alertType: 'treasury.reconcile.drift', data: [
    'company_id' => ..., 'company_name' => ..., 'severity' => 'critical'|'warning',
    'deep_link' => '/treasury/repositories/{id}' | '/finance/overview' | '/treasury/instruments?maturing=1',
    // + type-specific params (repository_code / reason / counts / window_days)
])
// databaseType() returns $alertType — FE never sees a PHP FQCN (spec §6.2).

// Resolver:
public function forCompany(string $tenantId, string $companyId, string $permission = 'treasury.manage'): Collection
```
- Consumes: `PermissionRegistrar` (constructor-injected), `Identity\Domain\User`.

- [ ] **Step 1: Write the failing tests** (the review's REQUIRED deny-direction + multi-tenant pins):

```php
public function test_recipients_are_company_scoped_deny_direction(): void
{
    // tenant T: companies A and B; managerA holds treasury.manage + active membership in A ONLY;
    // managerB likewise in B ONLY; adminBoth in both.
    $recipients = $this->resolver()->forCompany($tenant->id, $companyA->id);
    $this->assertEqualsCanonicalizing([$managerA->id, $adminBoth->id], $recipients->pluck('id')->all());
    $this->assertNotContains($managerB->id, $recipients->pluck('id')->all()); // L3-2: the DENY assertion
}

public function test_resolution_survives_multi_tenant_iteration(): void
{
    // resolve for tenant A, then tenant B (different DBs / permission uuids):
    // without forgetCachedPermissions() per call this returns [] for B (L3-3).
    // Use the repo's REAL 2-tenant harness (grep tests using forEachTenant / tenancy()->initialize).
    // Do NOT spy on PermissionRegistrar — it is a container singleton the ->permission() scope
    // itself resolves; a Mockery spy breaks the real permission query (plan-review L6).
}

public function test_membership_must_be_active(): void  // inactive membership in A → excluded
```

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Implement** (mirror `DailyExpiryCheck.php:165-174`'s QUERY SHAPE — team = TENANT id, membership filter, `->permission()` — and ADDITIONALLY flush the registrar, which the exemplar does not do; the flush is spec fix L3-3, not part of the mirror — plan-review L6. Registrar methods verified in vendor: `PermissionRegistrar.php:106/114/140`):

```php
final class TreasuryAlertRecipients
{
    public function __construct(private readonly PermissionRegistrar $permissionRegistrar) {}

    /** @return \Illuminate\Support\Collection<int, User> */
    public function forCompany(string $tenantId, string $companyId, string $permission = 'treasury.manage'): Collection
    {
        $originalTeamId = $this->permissionRegistrar->getPermissionsTeamId();

        try {
            // Team = TENANT (config/permission.php:99 team_foreign_key = tenant_id) — NEVER the company (L3-1).
            $this->permissionRegistrar->setPermissionsTeamId($tenantId);
            // forEachTenant swaps only the DB connection; the registrar memoizes the
            // FIRST tenant's permission uuids — flush per resolution (L3-3).
            $this->permissionRegistrar->forgetCachedPermissions();

            return User::query()
                ->where('tenant_id', $tenantId)
                ->whereHas('companyMemberships', function ($query) use ($companyId): void {
                    $query->whereRaw('company_id = ?', [$companyId])
                        ->whereRaw('status = ?', ['active']);
                })
                ->permission($permission)
                ->get();
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId($originalTeamId);
        }
    }
}
```

`TreasuryAlertNotification`:
```php
final class TreasuryAlertNotification extends Notification
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private readonly string $alertType,
        private readonly array $data,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database']; // mail deliberately off — spec D-4
    }

    public function databaseType(object $notifiable): string
    {
        return $this->alertType; // stable string alias, never the FQCN (spec §6.2)
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }
}
```

- [ ] **Step 4: Run to verify pass**; phpstan; pint.

- [ ] **Step 5: Commit** — `git commit -m "feat(treasury): alert notification + company-scoped recipient resolver"`

---

### Task B4: Wire the two alert commands to the notification channel

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Console/ReconcileTreasuryCommand.php` (`freezeAndAlert()` ~`:786-824`, `alertPortfolioDrift()` ~`:424-443`)
- Modify: `apps/api/app/Modules/Treasury/Presentation/Console/InstrumentMaturityAlertsCommand.php` (`:129-143` area)
- Test: extend `apps/api/tests/Feature/Treasury/ReconcileTreasuryTest.php` + the maturity command's existing test file

**Interfaces:**
- Consumes: B3's resolver + notification. audit_events writes are UNCHANGED — the notification is an additional, failure-isolated channel.

- [ ] **Step 1: Write the failing tests**

```php
public function test_drift_freeze_sends_notifications_to_company_managers(): void
// induce check-1 drift (existing fixture pattern in ReconcileTreasuryTest), run treasury:reconcile,
// assert DatabaseNotification rows exist for managerA with type 'treasury.reconcile.drift',
// data.deep_link === "/treasury/repositories/{$repo->id}", data.company_id set; managerB (other company) has none.
public function test_notification_failure_never_suppresses_the_freeze(): void
// bind a resolver stub that throws; run reconcile on drifted repo;
// assert frozen_at IS SET, audit_events row exists, and an 'treasury.reconcile.alert_failed' log/audit entry is recorded
public function test_maturity_command_sends_one_notification_per_user_per_company(): void
// seeded maturing instruments in company A: exactly 1 row per recipient per run,
// type 'treasury.instrument.maturity_alert', data.deep_link '/treasury/instruments?maturing=1'
public function test_maturity_command_sends_nothing_when_no_instruments_due(): void
// zero maturing/overdue instruments: audit event may still be recorded (existing behavior,
// unchanged) but NO notification rows are created (plan-review H2 — anti-spam guard)
```

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Implement** — in each of the three alert sites, AFTER the existing `AuditService::record()` block, add a third independently-try/caught channel (constructor-inject `TreasuryAlertRecipients` into both commands; the alert-failure catch mirrors the existing `alertFailed()` pattern at `:832-850`):

Freeze site — **`freezeAndAlert(PaymentRepository $repository, string $reason)` has ONLY those two params in scope; there is NO `$tenant`/`$company` object there** (`ReconcileTreasuryCommand.php:786`; companies are fetched in a separate loop `:189-192` — plan-review M1). Use the repository's own columns + relation:

```php
// third channel: in-app notification (failure-isolated like the other two — spec §6.3)
try {
    $recipients = $this->alertRecipients->forCompany($repository->tenant_id, $repository->company_id);
    if ($recipients->isNotEmpty()) {
        $companyName = $repository->company?->name ?? '';  // relation exists — PaymentRepository.php:156
        Notification::send($recipients, new TreasuryAlertNotification(
            alertType: 'treasury.reconcile.drift',
            data: [
                'company_id' => $repository->company_id,
                'company_name' => $companyName,
                'severity' => 'critical',
                'repository_code' => $repository->code,
                'reason' => $reason,
                'deep_link' => "/treasury/repositories/{$repository->id}",
            ],
        ));
    }
} catch (\Throwable $exception) {
    // Real helper name/signature (plan-review M2) — NOT "alertFailed":
    $this->logAlertFailure($repository, $reason, 'notification', $exception);
}
```

Portfolio drift (`alertPortfolioDrift(Company $company, …)` at `:424` — a `Company` IS in scope, but NO repository): `alertType: 'treasury.reconcile.portfolio_drift'`, `deep_link: '/finance/overview'`, severity `warning`, no repository fields. **`logAlertFailure` is `PaymentRepository`-typed and cannot be reused here** — add a small repository-less failure logger (same log/audit shape, `treasury.reconcile.alert_failed` event) or generalize `logAlertFailure`'s first param (plan-review M2).

Maturity command: `alertType: 'treasury.instrument.maturity_alert'`, `deep_link: '/treasury/instruments?maturing=1'`, include `window_days`, `received_due_count`, `deposited_overdue_count` from the existing payload variables. Failure handling mirrors that command's OWN `Log::error('treasury.instrument.maturity_alert_failed', …)` pattern (`InstrumentMaturityAlertsCommand.php:58`), not `logAlertFailure`. **CRITICAL (plan-review H2): the audit event is recorded UNCONDITIONALLY per company per run, even with both counts 0 (`:129-137`) — do NOT mirror that cadence for notifications. Gate the send on `received_due_count + deposited_overdue_count > 0`, or every manager gets a daily empty alert and learns to ignore the bell.** Add the H2 test below.

- [ ] **Step 4: Run to verify pass** — `./vendor/bin/phpunit tests/Feature/Treasury/ReconcileTreasuryTest.php` + the maturity test file; phpstan; pint.

- [ ] **Step 5: Commit** — `git commit -m "feat(treasury): deliver drift + maturity alerts to the in-app notification center"`

---

### Task C1: Cash-movements report — `direction` filter + per-currency totals

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Presentation/Requests/GetCashMovementsRequest.php`
- Modify: `apps/api/app/Modules/Accounting/Application/Services/Reports/CashMovementsReportService.php` (`generate()` at `:79-123`; `$base = DB::query()->fromSub($union, …)` at `:91`)
- Test: extend the service's existing test file (locate via `grep -rl CashMovementsReport tests/`)

**Interfaces:**
- Produces: request accepts `direction => ['nullable','in:in,out']`; `meta.totals` = `{"<CUR>": {"in": string, "out": string, "net": string}, ...}` over the ENTIRE filtered range. Existing `{data, meta}` fields unchanged.

- [ ] **Step 1: Write the failing tests**

```php
public function test_direction_filter_restricts_rows(): void
public function test_totals_are_grouped_per_currency(): void
// fixture: TND payment rows + one EUR payment row + journal-side TND rows (mixed sources AND currencies — L2-1)
// assert meta.totals.TND.{in,out,net} exact via bc strings, meta.totals.EUR present and separate,
// and net is NEVER a cross-currency sum
public function test_totals_cover_the_whole_filtered_range_not_the_page(): void  // per_page=1, totals unchanged
```

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Implement** — in `generate()`, **immediately after `$base` is built at `:91` and BEFORE the `$total = (clone $base)->count()` at `:92`** (plan-review M3 — placing the filter after the count leaves `meta.total`/`last_page` counting the UNFILTERED set), so the count, the rows, and the totals aggregate all see the direction filter. Also (plan-review L4): add a `direction()` accessor to `GetCashMovementsRequest` mirroring `fromDate()/repositoryId()` (`:30-49`), a `?string $direction` param on `generate()`, and thread it from `ReportsController::cashMovements` (`:225-246`). The `scaleResolver` is ALREADY constructor-injected in this service (`:60-62`) — no constructor change needed:

```php
if ($direction !== null) {
    $base->where('direction', $direction);
}

// One SQL aggregate over the same filtered union — PG exact numeric, per-currency (L2-1/L2-5).
$totalsRows = (clone $base)
    ->selectRaw('currency, direction, SUM(CAST(amount AS NUMERIC)) AS total')
    ->groupBy('currency', 'direction')
    ->get();

$totals = [];
foreach ($totalsRows as $row) {
    $currency = (string) $row->currency;
    $scale = $this->scaleResolver->getScale($currency);   // constructor-inject CurrencyScaleResolverInterface if not present
    $totals[$currency] ??= ['in' => CurrencyScale::bcformatStrict('0', $scale), 'out' => CurrencyScale::bcformatStrict('0', $scale)];
    $totals[$currency][(string) $row->direction] = CurrencyScale::bcformatStrict((string) $row->total, $scale);
}
foreach ($totals as $currency => &$t) {
    $scale = $this->scaleResolver->getScale($currency);
    $t['net'] = CurrencyScale::bcformatStrict(bcsub($t['in'], $t['out'], $scale + 1), $scale);
}
unset($t);
// merge into the returned meta array: 'totals' => $totals
```
(`clone $base` BEFORE any `->forPage()/limit` is applied to the row query; if the method structure applies pagination on `$base` directly, build the totals query from the union first. `direction` is threaded from controller → `generate()` as a new nullable param — update `ReportsController::cashMovements` at `:225-247` accordingly.)

- [ ] **Step 4: Run to verify pass**; phpstan; pint.

- [ ] **Step 5: Commit** — `git commit -m "feat(reports): cash-movements direction filter + per-currency range totals"`

---

### Task C2: Cash-position `flows_window`

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/CashPositionController.php` (`index()` at `:51-105`, `CASH_TYPES` at `:38-43`)
- Test: extend the cash-position endpoint's existing test file

**Interfaces:**
- Produces: `GET /treasury/cash-position?flows_window=7` → response `data` gains `"flows": {"window_days": 7, "in": "...", "out": "..."}`. Absent without the param. Window validated int 1..90 (422 otherwise).

- [ ] **Step 1: Write the failing tests**

```php
public function test_flows_absent_without_param(): void
public function test_flows_window_sums_in_and_out(): void
// seed movements inside and outside the window via the movement port fixtures already used
// by the transfer tests; assert exact bc strings; occurred_at is the window column (L2-7)
public function test_flows_exclude_foreign_currency_repositories(): void
// repo with currency != company currency contributes NOTHING to flows (L2-4)
public function test_flows_window_validated(): void  // 0, 91, 'x' → 422
```

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Implement** — inside `index()` after the groups/grand_total assembly:

```php
$flows = null;
$windowRaw = $request->query('flows_window');
if ($windowRaw !== null) {
    if (! ctype_digit((string) $windowRaw) || (int) $windowRaw < 1 || (int) $windowRaw > 90) {
        throw new \DomainException('flows_window must be an integer between 1 and 90.');
    }
    $window = (int) $windowRaw;

    $sums = DB::table('repository_movements as m')
        ->join('payment_repositories as r', 'r.id', '=', 'm.repository_id')
        ->where('r.tenant_id', $tenantId)
        ->where('r.company_id', $companyId)
        ->where('r.is_active', true)
        ->whereIn('r.type', array_map(fn ($t) => $t->value, self::CASH_TYPES))
        ->where('r.currency', $companyCurrency)              // explicit single-currency guard (L2-4)
        ->where('m.occurred_at', '>=', now()->subDays($window))
        ->selectRaw('m.direction, SUM(m.amount) AS total')
        ->groupBy('m.direction')
        ->pluck('total', 'direction');

    $scale = $this->scaleResolver->getScale($company->currency); // resolver ALREADY constructor-injected at :49 (plan-review L3)
    $flows = [
        'window_days' => $window,
        'in' => CurrencyScale::bcformatStrict((string) ($sums['in'] ?? '0'), $scale),
        'out' => CurrencyScale::bcformatStrict((string) ($sums['out'] ?? '0'), $scale),
    ];
}
// add to the response data array: ...($flows !== null ? ['flows' => $flows] : [])
```
(VERIFIED (plan-review): `CASH_TYPES` are `RepositoryType` enum instances (`:41-45`) — keep the `array_map` to `->value`; the controller derives `$company` at `:55` and uses `$company->currency` at `:68` — there is no `$companyCurrency` variable, use `$company->currency` and the existing `$tenantId`/`$companyId` derivations; `repository_movements.amount` is `decimal(15,3)` → `SUM(m.amount)` is exact, no CAST. The inline `DomainException` for `flows_window` validation maps to the canonical 422 — accepted divergence from `$request->validate()` siblings since D5 hardcodes `flows_window=7`.)

- [ ] **Step 4: Run to verify pass**; phpstan; pint.

- [ ] **Step 5: Commit** — `git commit -m "feat(treasury): cash-position windowed in/out flows"`

---

### Task D1: FE permission registration

**Files:**
- Modify: `apps/web/src/hooks/usePermissions.ts` — `PERMISSIONS` map (treasury block at `:65-67`)
- Test: `apps/web/src/hooks/usePermissions.test.ts` (or the existing test file for this hook — locate and extend)

**Interfaces:**
- Produces: `'treasury.transfer'` as a valid `Permission` (`Permission = keyof typeof PERMISSIONS`); role fallback list = copy the exact role array used by the most-privileged existing treasury entry (inspect `treasury.view/create/edit` rows and any `treasury.adjust` handling; mirror whichever bundle backend `RolesAndPermissionsSeeder` grants `treasury.adjust` to). Check `SERVER_AUTHORITATIVE_PERMISSIONS` (`:237`): if `treasury.adjust`-class permissions are listed there, add `treasury.transfer` alongside (L2-2).

- [ ] **Step 1: Failing test** — `hasPermission('treasury.transfer')` returns true for a token carrying it; typechecks.
- [ ] **Step 2: Run** — `pnpm vitest run src/hooks/usePermissions.test.ts` + `pnpm typecheck` → FAIL.
- [ ] **Step 3: Implement** — one map entry (+ optional server-authoritative entry).
- [ ] **Step 4: Verify pass** (vitest by path + typecheck).
- [ ] **Step 5: Commit** — `git commit -m "feat(web): register treasury.transfer permission"`

---

### Task D2: Transfer modal + hook + RepositoryListPage wiring + i18n

**Files:**
- Create: `apps/web/src/features/treasury/hooks/useTransferCash.ts` (+ `useTransferCash.test.ts`)
- Create: `apps/web/src/features/treasury/components/TransferCashModal.tsx` (+ `.test.tsx`)
- Modify: `apps/web/src/features/treasury/RepositoryListPage.tsx` (PageHeader actions at `:209`; compose multiple actions — L2-8)
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php` — **`formatRepository()` at `:262-278` does NOT emit `currency` (plan-review H1): add `'currency' => $repository->currency`.** One-line additive backend change, sanctioned despite this being an FE task — the modal is non-buildable without it (MoneyInput's `currency` prop is REQUIRED, `MoneyInput.tsx:29`). Add a backend test line asserting the list payload carries `currency`.
- Modify: `apps/web/src/features/treasury/hooks/usePaymentRepositories.ts` — add `currency: string` to the `PaymentRepository` interface (`:7-15`, currently absent — plan-review H1)
- Modify: `apps/web/src/locales/{en,fr,ar}/treasury.json` — keys under `repositories.transfer.*`

**Interfaces:**
- Consumes: A4's endpoint contract; `usePaymentRepositories`/`useActivePaymentRepositories` (`features/treasury/hooks/usePaymentRepositories.ts`); `Modal` (organism) + `FormField` (**atom** — `components/atoms/FormField/FormField.tsx`, plan-review L7); shared `MoneyInput`; `getErrorMessage` (`@/lib/api:61`) — canonical envelope only for this endpoint. **Note (plan-review L8):** the repo list endpoint is tenant-scoped, not company-scoped (`PaymentRepositoryController::index` `:29-33`) — if repo items expose a company discriminator, client-filter to the active company; otherwise accept that picking a foreign-company repo 404s at the service (pre-existing behavior, do not "fix" the backend scoping in this task).
- Produces: `useTransferCash(): UseMutationResult<TransferResponse, unknown, TransferPayload>` with
```ts
interface TransferPayload { from_repository_id: string; to_repository_id: string; amount: string; notes?: string; transfer_group_id: string }
interface TransferResponse { transfer_group_id: string; journal_entry_id: string | null; idempotent_replay: boolean;
  out: { movement_id: string; balance_after: string; repository_id: string };
  in:  { movement_id: string; balance_after: string; repository_id: string } }
```

- [ ] **Step 1: Failing hook test** — mutation posts to `/payment-repositories/transfers`; on success invalidates ALL of (assert via a spied queryClient):
```ts
tenantScopedKey(['payment-repositories'])
tenantScopedKey(['payment-repository', fromId])
tenantScopedKey(['payment-repository', toId])
tenantScopedKey(['payment-repository-transactions', fromId])
tenantScopedKey(['payment-repository-transactions', toId])
tenantScopedKey(['treasury-cash-position'])
['repository-movements', fromId]   // RAW prefix — deliberately NOT tenantScopedKey (spec §5.5 / L2-3)
['repository-movements', toId]     // RAW prefix
```
- [ ] **Step 2: Run to verify failure** (vitest by path).
- [ ] **Step 3: Implement hook**

```ts
export function useTransferCash() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (payload: TransferPayload): Promise<TransferResponse> => {
      const { data: response } = await api.post<{ message: string; data: TransferResponse }>(
        '/payment-repositories/transfers', payload,
      )
      return response.data
    },
    onSuccess: (_data, variables) => {
      const { from_repository_id: fromId, to_repository_id: toId } = variables
      queryClient.invalidateQueries({ queryKey: tenantScopedKey(['payment-repositories']) })
      queryClient.invalidateQueries({ queryKey: tenantScopedKey(['payment-repository', fromId]) })
      queryClient.invalidateQueries({ queryKey: tenantScopedKey(['payment-repository', toId]) })
      queryClient.invalidateQueries({ queryKey: tenantScopedKey(['payment-repository-transactions', fromId]) })
      queryClient.invalidateQueries({ queryKey: tenantScopedKey(['payment-repository-transactions', toId]) })
      queryClient.invalidateQueries({ queryKey: tenantScopedKey(['treasury-cash-position']) })
      // Raw prefixes: the movements key is ['repository-movements', id, filters, tenant, company] —
      // the filters object defeats any tenantScopedKey fixed-position prefix (spec §5.5).
      queryClient.invalidateQueries({ queryKey: ['repository-movements', fromId] })
      queryClient.invalidateQueries({ queryKey: ['repository-movements', toId] })
    },
  })
}
```

- [ ] **Step 4: Failing modal test** — renders from/to selects (active, non-virtual repos; `to` excludes selected `from` and different-currency repos), MoneyInput bound to the source repo currency, notes textarea; zod blocks 4-dp amounts and amount ≤ 0; a stable `transfer_group_id` (crypto.randomUUID once per open — assert two submits reuse it); success → toast + onClose.
- [ ] **Step 5: Implement modal** — RHF + `zodResolver` + `Modal/ModalHeader/ModalContent/ModalFooter` + `FormField` (form-mechanics template: `features/vouchers/components/TransferVoucherModal.tsx`; UI atoms: canonical `Select`, `Textarea`, `Button`). zod schema:
```ts
const schema = z.object({
  from_repository_id: z.string().uuid(),
  to_repository_id: z.string().uuid(),
  amount: z.string().regex(/^\d+(\.\d{1,3})?$/),
  notes: z.string().max(1000).optional(),
}).refine((v) => v.from_repository_id !== v.to_repository_id, { path: ['to_repository_id'], message: 'sameRepository' })
```
(Amount positivity: regex already excludes negatives; add `.refine((v) => v.amount !== '0' && !/^0+(\.0{1,3})?$/.test(v.amount))` for zero — NO numeric parsing on money.) Props: `{ isOpen, onClose, onSuccess? }`. i18n keys (en+fr+ar): `repositories.transfer.title/from/to/amount/notes/submit/success/sameRepository/zeroAmount/differentCurrency/balanceHint`.
- [ ] **Step 6: Wire RepositoryListPage** — compose the existing `addButton` and the new gated button in `PageHeader` `actions` (fragment); `hasPermission('treasury.transfer')` renders the `Transfer cash` button; modal state local to the page.
- [ ] **Step 7: Verify** — `pnpm vitest run src/features/treasury` (by path), `pnpm typecheck`, `pnpm lint`, `node tools/audit-design-system.mjs` (0 new), `node tools/audit-tanstack-keys.mjs`.
- [ ] **Step 8: Commit** — `git commit -m "feat(treasury-web): inter-repository transfer modal on RepositoryListPage"`

---

### Task D3: Notifications FE — hooks, bell, panel, i18n namespace

**Files:**
- Create: `apps/web/src/features/notifications/api/notificationsApi.ts`, `hooks/useNotifications.ts` (+ tests), `components/NotificationBell.tsx`, `components/NotificationPanel.tsx` (+ tests)
- Modify: `apps/web/src/components/organisms/TopBar/TopBar.tsx` (`:154-161` — the ORGANISMS file; `components/layout/TopBar.tsx` is a re-export, do not edit it — L3-6)
- Create: `apps/web/src/locales/{en,fr,ar}/notifications.json`; Modify: `apps/web/src/lib/i18n.ts` (**correct path — plan-review L1**; ~7 edits: 3 locale imports, 3 `resources` entries — the `ar` block uses the spread-merge pattern, see `:306` — and 1 `ns:` array entry at `:416`)

**Interfaces:**
- Consumes: B2's four endpoints. `{data,meta}` list → `api.get` + `response.data` (rule 14); unread-count via `apiGet` (single object, no meta).
- Produces:
```ts
interface AppNotification { id: string; type: string; data: Record<string, unknown>; read_at: string | null; created_at: string }
useUnreadNotificationCount(): UseQueryResult<{ count: number }>   // key: tenantScopedKey(['notifications', userId, 'unread-count']), refetchInterval: 60_000
useNotificationsList(enabled: boolean): UseQueryResult<{ data: AppNotification[]; meta: OffsetMeta }> // key: tenantScopedKey(['notifications', userId, 'list']), fetched on panel open
useMarkNotificationRead() / useMarkAllNotificationsRead()          // invalidate with the raw leading prefix ['notifications', userId] — it prefix-matches both scoped keys (trailing tenant/company suffix is irrelevant to a prefix match, same logic as the movements prefix in D2 — plan-review L2)
```
`userId` from `useAuthStore` (shared-localhost safety — spec §6.4); gate queries `enabled: !!userId && !!tenantId`.

- [ ] **Step 1: Failing hook tests** (poll key shape, mark-read invalidations).
- [ ] **Step 2: Implement hooks** per the interface block (straight `api.get`/`apiPost` wrappers in `notificationsApi.ts`).
- [ ] **Step 3: Failing bell/panel tests** — badge hidden at count 0 (kills the hardcoded fake dot); badge shows count; panel lists items newest-first, unread rows highlighted (`tokens`-based styling); clicking an unread item calls mark-read and navigates `data.deep_link` when present (string-typed check); **a seeded legacy row (`type` = a PHP-FQCN-looking string, `data.message` only) renders through the generic fallback** (L3-8); mark-all-read; empty state.
- [ ] **Step 4: Implement** `NotificationBell` (button + conditional count badge + popover state) and `NotificationPanel` (dropdown card: last 15 from the list query, per-type title via `t(\`notifications:types.${type}\`, { defaultValue: t('notifications:types.generic') })` + `data.message`/params rendering; deep-link navigate via `useNavigate`). Replace the TopBar static button (keep its `aria-label`, replace the hardcoded dot). Types with i18n entries: `treasury.reconcile.drift`, `treasury.reconcile.portfolio_drift`, `treasury.instrument.maturity_alert`, `generic`.
- [ ] **Step 5: Verify** — vitest by path (`src/features/notifications`, plus TopBar's test if one exists), typecheck, lint, design audit 0 new.
- [ ] **Step 6: Commit** — `git commit -m "feat(web): notification center — live bell, panel, polling inbox"`

---

### Task D4: Cash-movements report page + route + nav

**Files:**
- Create: `apps/web/src/features/finance/pages/CashMovementsReportPage.tsx` (+ `.test.tsx`)
- Create: `apps/web/src/features/finance/hooks/useCashMovementsReport.ts` (+ test)
- Modify: `apps/web/src/routes/index.tsx` (lazy import + route beside `/finance/overview` at `:1870`) · `apps/web/src/components/organisms/Sidebar/Sidebar.tsx` (finance group, beside `treasuryOverview` at `:284`)
- Modify: `apps/web/src/locales/{en,fr,ar}/finance.json` — `cashMovements.*` keys; `apps/web/src/locales/{en,fr,ar}/common.json` if `navigation.cashMovements` is needed (follow `getNavLabel` fallback rules at `Sidebar.tsx:366-376` — simplest: `labelKey: 'finance:cashMovements.navTitle'`)

**Interfaces:**
- Consumes: C1's endpoint. Hook:
```ts
interface CashMovementsFilters { from?: string; to?: string; repository_id?: string; direction?: 'in' | 'out'; page: number }
interface CashMovementRow { date: string; direction: 'in' | 'out'; amount: string; currency: string; source_type: string; source_id: string; counterparty: string | null; gl_account: string | null }
interface CashMovementsMeta { current_page: number; per_page: number; total: number; last_page: number; totals: Record<string, { in: string; out: string; net: string }> }
useCashMovementsReport(filters): UseQueryResult<{ data: CashMovementRow[]; meta: CashMovementsMeta }>
// api.get('/reports/cash-movements', { params }) + return response.data — NEVER apiGet (meta!)
// key: tenantScopedKey(['cash-movements-report', filters])
```

- [ ] **Step 1: Failing hook + page tests** — hook returns `{data, meta}`; page renders `DateRangeFilter` (default: current month), repository `Select` (from `usePaymentRepositories`), direction `Select`, hand-rolled `<table>` (copy the `RepositoryMovementsTab.tsx` table idiom + its DataTable-has-no-pagination comment), `<OffsetPagination>`, and **one totals row per currency key** in `meta.totals` (L2-1) formatted with `formatCurrency` from `@/lib/format`.
- [ ] **Step 2: Implement** page + hook per the interface block. Route:
```tsx
<Route path="finance/cash-movements" element={
  <SuspenseWrapper><RequirePermission permission="reports.view"><CashMovementsReportPage /></RequirePermission></SuspenseWrapper>
} />
```
Nav entry in the finance group: `{ key: 'cashMovements', labelKey: 'finance:cashMovements.navTitle', href: '/finance/cash-movements', icon: ArrowLeftRight, permission: 'reports' }`.
- [ ] **Step 3: Verify** — vitest by path, typecheck, lint, design audit, tanstack-keys audit.
- [ ] **Step 4: Commit** — `git commit -m "feat(finance-web): cash movements report page (G12 — unified in/out view)"`

---

### Task D5: Cash-position widget on both dashboards

**Files:**
- Modify: `apps/web/src/features/treasury/hooks/useCashPosition.ts` (add `options?: { flowsWindow?: number }`, folded into the query key + `flows_window` param; backward-compatible — arg-less call unchanged)
- Create: `apps/web/src/features/treasury/components/CashPositionWidget.tsx` (+ `.test.tsx`)
- Modify: `apps/web/src/features/owner-dashboard/OwnerDashboardPage.tsx` (widget-grid cell) · `apps/web/src/features/dashboard/Dashboard.tsx` (card in the recent-activity grid area)
- Modify: `apps/web/src/locales/{en,fr,ar}/treasury.json` — `cashWidget.*` keys

**Interfaces:**
- Consumes: C2's `flows` block; `useCashPosition({ flowsWindow: 7 })`; `usePermissions().canAccessModule('treasury')`; `useCompanyConfig().hasModule('Treasury')`.
- Produces: `<CashPositionWidget />` — self-gating (returns `null` unless BOTH gates pass — mirror the Sidebar's dual axes); renders grand total + per-type rows (registers/banks/safes with counts) + 7-day In/Out + links to `/finance/overview` and `/finance/cash-movements`.

- [ ] **Step 1: Failing tests** — gating (no permission → null; no module → null); renders totals/flows from a mocked position; links present.
- [ ] **Step 2: Implement** — one component, `tokens`/`textColors` styling (treasury convention — sanctioned in both hosts), `formatCurrency` for figures. Mount in both dashboards (owner: as a grid cell alongside the existing widgets; generic: as a bordered card in the 2-up grid — it self-gates so no host-side permission logic).
- [ ] **Step 3: Verify** — vitest by path (`src/features/treasury`, `src/features/dashboard`, `src/features/owner-dashboard` as applicable), typecheck, lint, audits.
- [ ] **Step 4: Commit** — `git commit -m "feat(web): cash-position widget on owner + generic dashboards (C9)"`

---

### Task E1: Playwright E2E (demo tenant)

**Files:**
- Create: `docs/sessions/treasury-phase3-e2e/` screenshots (gitignored) + a summary in the progress file

**Flow to drive** (local stack per `docs/handoff/RESUME-2026-07-08.md`, tenant `owner@pharmabio.tn`; Playwright MCP or manual):
1. Repositories list → `Transfer cash` visible (owner has `treasury.transfer` post-reseed) → drawer→bank 250 TND → toast, both repo balances move, movements rows on both repos, JE visible in the GL UI (Dr 512 / Cr 53), cash-position card updates.
2. Drawer→safe transfer → succeeds with NO JE (movements only).
3. `/finance/cash-movements` → both legs visible; per-currency totals row correct; direction + repository filters work.
4. Seed a drift alert (or run `treasury:reconcile` against an induced drift on a scratch repo) → bell badge appears → panel shows the alert → click navigates to the repository page → badge clears after mark-read.
5. Widget on both dashboards (owner login → /reports; a non-owner treasury user → /dashboard).

- [ ] Run the flow, screenshot each numbered step, record pass/fail + anomalies in the progress file. **A failing step is a STOP** (fix before proceeding), not a footnote.
- [ ] Commit the progress-file update.

---

### Task E2: Deploy checklist + docs

**Files:**
- Create: `docs/handoff/treasury-phase3-deploy-checklist.md`
- Modify: `docs/handoff/treasury-phase3-progress.md` (final state)

Checklist content: `php artisan tenants:migrate` (notifications table + JE index) · perm reseed (`treasury.transfer`) + `php artisan permission:cache-reset` (tenant-blind cache) · no chart reseed needed · note: tokens minted before the reseed lack `treasury.transfer` until roles re-sync (FE map fallback covers dev) · smoke: one drawer→bank transfer + bell delivery on staging.

- [ ] Write both docs, commit: `git commit -m "docs(treasury): Phase 3 deploy checklist + progress final"`

---

## Plan-review reconciliation (2026-07-12 — Rev 1 → Rev 2)

Review: `docs/superpowers/plans/reviews/2026-07-12-treasury-phase3-plan-adversarial-review.md` (Lane 1 Fable/Wave A, Lane 2 Opus/B–E; both CHANGES-REQUIRED, 0 BLOCKER).

| id | sev | resolution in Rev 2 |
|---|---|---|
| F1 | HIGH | A2 guard test rewritten with the transaction pop/restore pattern |
| F2 | HIGH | False port-suite claim deleted; sequential race-shape test kept as a SANCTIONED substitution (identical 23505-inside-savepoint path, Fable-verified mechanics), recorded as spec §15 amendment A-1 + mandatory progress-file deviation entry |
| F3 | MED | A1 driver guards removed (exemplar has none; sqlite supports partial indexes) |
| F4 | MED | Verified imports pasted into A3 (`App\Shared\Contracts\CurrencyScaleResolverInterface`, `App\Shared\Domain\CurrencyScale`) |
| F5 | MED | A5 reconcile call fixed: `CompanyContext::clear()` + `Artisan::call(..., ['--tenant' => ...])` |
| F6 | MED | Missing-GL-account 422 test added to A3 |
| F7 | LOW | Normalization assertion corrected for the decimal:3 model cast |
| F8 | LOW | Backend lang = en + fr only, noted in A4 |
| F9 | LOW | sqlite `markTestSkipped` escape hatch noted (never "fix" the port/index) |
| H1 | HIGH | `currency` added to `formatRepository()` + FE `PaymentRepository` type as explicit D2 deliverables |
| H2 | HIGH | Maturity notification gated on non-zero counts + anti-spam test added to B4 |
| M1 | MED | B4 freeze-site snippet rewritten to `$repository->tenant_id/company_id` + `company` relation |
| M2 | MED | Real helper `logAlertFailure($repo,$reason,$channel,$e)` used; repository-less logger for portfolio/maturity sites |
| M3 | MED | C1 direction filter pinned to before the `:92` count; `direction()` accessor + threading spelled out |
| L1–L8 | LOW | i18n path `src/lib/i18n.ts` (~7 edits); notifications invalidation via raw `['notifications', userId]` prefix (rationale corrected); C2 `$company->currency` + resolver-already-injected; B1 index leads with morph type; B3 "mirror + additionally flush" phrasing + spy fallback dropped; FormField=atom; L8 tenant-scoped-list note added to D2 |

Both lanes' "Verified accurate" sections are binding ground truth (exception ancestry — no rethrow contingency needed; route non-shadowing; all DTO/model signatures; FE key names; insertion anchors).

## Plan self-review (done at authoring)

1. **Spec coverage:** §5 → A1–A5 + D1–D2 · §6 → B1–B4 + D3 · §7 → C1 + D4 · §8 → C2 + D5 · §10 → embedded per task + E1 · §14 → A1/B1/E2. §15 items all land in their named tasks (L1-1→A1/A4, L1-2→A3/A4, L1-3/5→A3, L1-4→A3, L1-9→A3, L2-1/5→C1/D4, L2-2→D1, L2-3→D2, L2-4→C2, L3-1/2/3→B3, L3-4/5→B2, L3-6→D3, L3-8→D3).
2. **Placeholders:** none — every code step shows code; "verify before coding" blocks name exact files/lines to check, which is repo-verification, not deferral.
3. **Type consistency:** `RepositoryTransferResult` fields match A4's controller reads; `TransferPayload/TransferResponse` (D2) match A4's contract; `AppNotification` (D3) matches B2's resource shape; `CashMovementsMeta.totals` (D4) matches C1's shape; `useCashPosition({flowsWindow})` (D5) matches C2's param.
