<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryAdjustment;
use App\Modules\Treasury\Domain\RepositoryMovement;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Wave-E coverage for POST /payment-repositories/{id}/adjustments — Task 23,
 * the gated manual cash-count-variance / correction adjustment endpoint.
 * Writes a movement(adjustment) via the write port AND a balanced GL entry
 * (cash ↔ variance account), atomically, so the movement always carries a
 * non-null journal_entry_id.
 */
final class RepositoryAdjustmentTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    public function test_out_adjustment_decrements_balance_and_posts_balanced_gl_entry(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ])
            ->assertCreated();

        // Balance decremented.
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('475.000', $freshRepo->balance);

        $movementId = $response->json('data.movement_id');
        $this->assertIsString($movementId);

        // Exactly ONE movement, source_type=adjustment, reason_code=count_variance,
        // non-null journal_entry_id.
        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'adjustment')
            ->get();
        $this->assertCount(1, $movements);
        $movement = $movements->first();
        $this->assertNotNull($movement);
        $this->assertSame($movementId, $movement->id);
        $this->assertSame('out', $movement->direction);
        $this->assertSame(0, bccomp((string) $movement->amount, '25.000', 3));
        $this->assertSame('count_variance', $movement->reason_code);
        $this->assertNotNull($movement->journal_entry_id);

        // Balanced GL entry: Dr variance expense (658) / Cr cash.
        $varianceExpenseAccount = $this->accountFor($user, $company, SystemAccountPurpose::PaymentToleranceExpense);
        $entry = JournalEntry::query()->whereKey($movement->journal_entry_id)->with('lines')->firstOrFail();
        $this->assertSame('repository_adjustment', $entry->source_type);
        $debitLine = $entry->lines->first(fn (JournalLine $line): bool => bccomp((string) $line->debit, '0', 3) > 0);
        $creditLine = $entry->lines->first(fn (JournalLine $line): bool => bccomp((string) $line->credit, '0', 3) > 0);
        $this->assertNotNull($debitLine);
        $this->assertNotNull($creditLine);
        $this->assertSame($varianceExpenseAccount->id, $debitLine->account_id);
        $this->assertSame($glAccount->id, $creditLine->account_id);
        $totalDebit = $entry->lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->debit, 3), '0');
        $totalCredit = $entry->lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->credit, 3), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 3));
        $this->assertSame('25.000', $totalDebit);
    }

    public function test_in_adjustment_increments_balance_and_posts_balanced_gl_entry(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'in',
                'amount' => '10.000',
                'reason_code' => 'correction',
                'reason_text' => 'Found extra cash during count.',
            ])
            ->assertCreated();

        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('510.000', $freshRepo->balance);

        $movementId = $response->json('data.movement_id');
        $movement = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'adjustment')
            ->where('id', $movementId)
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame('in', $movement->direction);
        $this->assertSame('correction', $movement->reason_code);
        $this->assertNotNull($movement->journal_entry_id);

        // Balanced GL entry: Dr cash / Cr variance income (758).
        $varianceIncomeAccount = $this->accountFor($user, $company, SystemAccountPurpose::PaymentToleranceIncome);
        $entry = JournalEntry::query()->whereKey($movement->journal_entry_id)->with('lines')->firstOrFail();
        $debitLine = $entry->lines->first(fn (JournalLine $line): bool => bccomp((string) $line->debit, '0', 3) > 0);
        $creditLine = $entry->lines->first(fn (JournalLine $line): bool => bccomp((string) $line->credit, '0', 3) > 0);
        $this->assertNotNull($debitLine);
        $this->assertNotNull($creditLine);
        $this->assertSame($glAccount->id, $debitLine->account_id);
        $this->assertSame($varianceIncomeAccount->id, $creditLine->account_id);
        $totalDebit = $entry->lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->debit, 3), '0');
        $totalCredit = $entry->lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->credit, 3), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 3));
    }

    public function test_missing_reason_text_returns_422(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                // reason_text omitted
            ])
            ->assertStatus(422);

        $this->assertApiValidationErrors($response, ['reason_text']);

        // No money moved.
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('500.000', $freshRepo->balance);
    }

    public function test_route_requires_treasury_adjust_permission(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.view']); // no treasury.adjust
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ])
            ->assertStatus(403);

        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('500.000', $freshRepo->balance);
    }

    /**
     * Audit fix N5: a malformed (non-UUID) `{repository}` path param must 404,
     * not 500. On sqlite (the fast driver used by this suite) this assertion
     * passes even WITHOUT the `Str::isUuid()` guard — sqlite is typeless, so
     * `findOrFail()`'s `WHERE id = 'not-a-uuid'` simply matches no row and
     * throws `ModelNotFoundException` (404) regardless. The guard is only
     * load-bearing on Postgres, where the same malformed literal in a uuid
     * column comparison raises `22P02` (invalid input syntax) → HTTP 500
     * without it. See `.superpowers/sdd/audit-fix-3-report.md` for the pgsql
     * before/after evidence proving this test is genuinely red before the fix
     * and green after, on that driver.
     */
    public function test_malformed_repository_id_returns_404_not_500(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/payment-repositories/not-a-uuid/adjustments', [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ])
            ->assertStatus(404);
    }

    public function test_adjustment_movement_writes_an_audit_event(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'theft_loss',
                'reason_text' => 'Suspected till theft.',
            ])
            ->assertCreated();

        $movementId = $response->json('data.movement_id');

        $auditEventCount = AuditEvent::where('aggregate_id', $movementId)
            ->where('event_type', 'treasury.repository.movement_recorded')
            ->count();
        $this->assertSame(1, $auditEventCount);
    }

    /**
     * Audit fix 4 (K2): the Tunisia chart-of-accounts seeder now wires the
     * 658/758 "payment tolerance" purposes (see
     * TunisiaChartOfAccountsSeeder::getAccountsDefinition, codes 6580/7580),
     * so a plain `seedForCompany()` is sufficient for the 'out' direction —
     * no missing-purpose 422 should surface.
     */
    public function test_out_adjustment_succeeds_with_only_tunisia_seeder_no_manual_tolerance_accounts(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ])
            ->assertCreated();

        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('475.000', $freshRepo->balance);
    }

    /**
     * Audit fix 4 (K2): defense in depth for any tenant whose chart was seeded
     * before this fix (or any custom chart that never assigned the 658/758
     * purposes). Previously `Account::findByPurposeOrFail` threw a bare
     * `RuntimeException` here, uncaught → HTTP 500. Must now be a translated
     * 422 with no money moved and no GL entry created.
     */
    public function test_out_adjustment_returns_422_when_chart_lacks_payment_tolerance_expense_purpose(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);

        // Deliberately do NOT run a chart-of-accounts seeder — only create the
        // one account the repository itself needs (Cash). No account carries
        // the PaymentToleranceExpense purpose.
        $cashAccount = Account::create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'code' => '53',
            'name' => 'Caisse',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::Cash,
            'is_active' => true,
        ]);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ]);

        $response->assertStatus(422);
        $this->assertIsString($response->json('error'));

        // No money moved, no movement/GL row created.
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('500.000', $freshRepo->balance);

        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'adjustment')
            ->count();
        $this->assertSame(0, $movements);
    }

    /**
     * Same defense-in-depth guarantee for the 'in' direction / income purpose.
     */
    public function test_in_adjustment_returns_422_when_chart_lacks_payment_tolerance_income_purpose(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);

        $cashAccount = Account::create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'code' => '53',
            'name' => 'Caisse',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::Cash,
            'is_active' => true,
        ]);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'in',
                'amount' => '10.000',
                'reason_code' => 'correction',
                'reason_text' => 'Found extra cash during count.',
            ]);

        $response->assertStatus(422);
        $this->assertIsString($response->json('error'));

        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('500.000', $freshRepo->balance);
    }

    /**
     * W-5b Option B (owner ruling 2026-08-05): the interactive adjustment
     * endpoint is an INTERACTIVE writer (MovementIntent::$allowNegative left
     * at its default false) — an outflow that would drive a cash_register
     * below zero must be refused end-to-end with the typed 422 envelope, not
     * just at the service-unit level. GL entry + movement are both inside
     * the same transaction, so a refusal must roll back the JE too.
     */
    public function test_out_adjustment_returns_422_insufficient_repository_balance_and_writes_nothing(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '10.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INSUFFICIENT_REPOSITORY_BALANCE', $response->json('error.code'));
        $this->assertSame('10.000', $response->json('error.available'));
        $this->assertSame('25.000', $response->json('error.requested'));
        $this->assertSame('-15.000', $response->json('error.resulting_balance'));
        $this->assertSame($repo->id, $response->json('error.repository_id'));
        $this->assertIsString($response->json('error.message'));

        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('10.000', $freshRepo->balance);

        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'adjustment')
            ->count();
        $this->assertSame(0, $movements);

        $journalEntries = JournalEntry::query()
            ->where('company_id', $company->id)
            ->where('source_type', 'repository_adjustment')
            ->count();
        $this->assertSame(0, $journalEntries);
    }

    /**
     * DPA lane V3 (document-per-action remediation): the adjustment UUID that
     * already flowed to the journal entry (`source_type='repository_adjustment'`)
     * and to the `MovementSourceType::Adjustment` movement must now address a
     * REAL row — a `repository_adjustments` document. Linkage shape was already
     * right; the referent did not exist.
     */
    public function test_adjustment_creates_a_document_row_cross_linked_to_journal_entry_and_movement(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ])
            ->assertCreated();

        $movementId = $response->json('data.movement_id');
        $this->assertIsString($movementId);

        $movement = RepositoryMovement::query()->findOrFail($movementId);

        $adjustments = RepositoryAdjustment::query()->get();
        $this->assertCount(1, $adjustments, 'exactly one repository_adjustments document row');
        $adjustment = $adjustments->firstOrFail();

        // The document IS the referent the movement and the JE already pointed at.
        $this->assertSame($adjustment->id, $movement->source_id);
        $this->assertSame(MovementSourceType::Adjustment, $movement->source_type);

        $entry = JournalEntry::query()->whereKey($movement->journal_entry_id)->firstOrFail();
        $this->assertSame('repository_adjustment', $entry->source_type);
        $this->assertSame($adjustment->id, $entry->source_id);

        // …and the document links back to both (cross-linked, both directions).
        $this->assertSame($entry->id, $adjustment->journal_entry_id);
        $this->assertSame($movement->id, $adjustment->movement_id);

        // Scoping + payload fidelity.
        $this->assertSame($user->tenant_id, $adjustment->tenant_id);
        $this->assertSame($company->id, $adjustment->company_id);
        $this->assertSame($repo->id, $adjustment->payment_repository_id);
        $this->assertSame(MovementDirection::Out, $adjustment->direction);
        $this->assertSame(0, bccomp($adjustment->amount, '25.000', 3));
        $this->assertSame('TND', $adjustment->currency);
        $this->assertSame(MovementReasonCode::CountVariance, $adjustment->reason_code);
        $this->assertSame('Till was short at close.', $adjustment->reason_text);
        $this->assertSame($user->id, $adjustment->created_by);
    }

    /**
     * DPA lane V3 requirement 3(b): `record()`'s idempotent-replay path
     * (`MovementResult::$wasIdempotentHit`) must NOT mint a second adjustment
     * document. The document is keyed on the SAME discriminator the movement's
     * idempotency key is built from (`adjustment:{adjustmentId}:main`), so a
     * replay of the same adjustment id resolves to the already-written row.
     *
     * The endpoint mints its adjustment id inline, so a replay is forced here
     * by pinning the FIRST uuid each request generates (and only the first —
     * the journal entry, its lines and the movement must all keep unique ids).
     * The assertion that the pinned id actually landed on the document keeps
     * this harness honest: if anything ever consumes a uuid ahead of the
     * controller, this test fails loudly rather than silently testing nothing.
     */
    public function test_idempotent_replay_records_exactly_one_adjustment_document_row(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $pinnedAdjustmentId = (string) Str::uuid();
        $payload = [
            'direction' => 'out',
            'amount' => '25.000',
            'reason_code' => 'count_variance',
            'reason_text' => 'Till was short at close.',
        ];

        $this->pinFirstGeneratedUuid($pinnedAdjustmentId);
        $first = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", $payload)
            ->assertCreated();
        Str::createUuidsNormally();

        $this->assertFalse($first->json('data.idempotent_replay'));
        $this->assertNotNull(RepositoryAdjustment::query()->find($pinnedAdjustmentId), 'the pinned uuid must be the adjustment id — harness precondition');

        $this->pinFirstGeneratedUuid($pinnedAdjustmentId);
        $second = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", $payload)
            ->assertCreated();
        Str::createUuidsNormally();

        // The write port replayed rather than recording a second movement…
        $this->assertTrue($second->json('data.idempotent_replay'));
        $this->assertSame($first->json('data.movement_id'), $second->json('data.movement_id'));
        $this->assertSame(1, RepositoryMovement::query()->where('payment_repository_id', $repo->id)->count());

        // …so exactly ONE adjustment document exists, with its original linkage.
        $this->assertSame(1, RepositoryAdjustment::query()->count());
        $adjustment = RepositoryAdjustment::query()->findOrFail($pinnedAdjustmentId);
        $this->assertSame($first->json('data.movement_id'), $adjustment->movement_id);

        // Balance moved exactly once.
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('475.000', $freshRepo->balance);
    }

    /**
     * The 658/758 seeded-purpose precheck refuses BEFORE the transaction opens,
     * so no adjustment document may be minted on that path either.
     */
    public function test_tolerance_purpose_refusal_writes_no_adjustment_document_row(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);

        $cashAccount = Account::create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'code' => '53',
            'name' => 'Caisse',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::Cash,
            'is_active' => true,
        ]);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ])
            ->assertStatus(422);

        $this->assertSame(0, RepositoryAdjustment::query()->count());
    }

    /**
     * An insufficient-balance refusal throws from inside the transaction, AFTER
     * the document row was written — the rollback must take the document with
     * the journal entry and the movement.
     */
    public function test_insufficient_balance_refusal_rolls_back_the_adjustment_document_row(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '10.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ])
            ->assertStatus(422);

        $this->assertSame(0, RepositoryAdjustment::query()->count());
    }

    /**
     * Gate C1 (fix round 1) — the document, the journal entry and the movement
     * must state the SAME amount. `amount` is normalized ONCE at the boundary to
     * the repository's own currency scale and that single value is fed to all
     * three; previously only the document was scaled, so an EUR (scale 2)
     * adjustment of 25.005 stored doc 25.000 against movement/JE 25.005.
     *
     * Reproduced by the gate on PostgreSQL; asserted here on both drivers.
     */
    public function test_sub_scale_amount_is_normalized_once_so_document_journal_entry_and_movement_agree(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        // EUR — scale 2, below the FormRequest's 3-decimal regex ceiling.
        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'EUR',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.005',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ])
            ->assertCreated();

        $movement = RepositoryMovement::query()->findOrFail($response->json('data.movement_id'));
        $adjustment = RepositoryAdjustment::query()->firstOrFail();
        $entry = JournalEntry::query()->whereKey($movement->journal_entry_id)->with('lines')->firstOrFail();

        // One normalized value everywhere — 25.005 truncated once to EUR's scale.
        $this->assertSame(0, bccomp($adjustment->amount, '25.00', 2), "document amount {$adjustment->amount}");
        $this->assertSame(0, bccomp((string) $movement->amount, '25.00', 2), "movement amount {$movement->amount}");
        $this->assertSame(0, bccomp($adjustment->amount, (string) $movement->amount, 3), 'document and movement must agree');

        foreach ($entry->lines as $line) {
            $posted = bccomp((string) $line->debit, '0', 3) > 0 ? (string) $line->debit : (string) $line->credit;
            $this->assertSame(0, bccomp($posted, $adjustment->amount, 3), "journal line {$posted} must agree with the document");
        }

        // …and the balance moved by that same normalized amount.
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame(0, bccomp((string) $freshRepo->balance, '475.00', 2), "balance {$freshRepo->balance}");
    }

    /**
     * Gate C2 (fix round 1) — an amount below the currency's smallest unit
     * passes `required|numeric|gt:0|regex:{1,3}` and normalizes to zero. It used
     * to reach the pgsql-only `CHECK (amount > 0)` as SQLSTATE 23514 → HTTP 500
     * (invisible to the sqlite suite, live on the auto-deploying origin/dev
     * path). It must now be a graceful 422 refused BEFORE any insert.
     */
    public function test_amount_below_currency_precision_is_refused_with_422_before_any_write(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'EUR',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '0.005',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ]);

        $response->assertStatus(422);
        $this->assertIsString($response->json('error'));

        // Nothing written, nothing moved — on either driver.
        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, RepositoryMovement::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'repository_adjustment')->count());

        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame(0, bccomp((string) $freshRepo->balance, '500.00', 2));
    }

    /**
     * Gate C2, scale-0 currency (XOF/XAF/JPY — `CurrencyScale::SCALE_MAP`):
     * EVERY sub-unit amount normalizes to zero there, so the refusal must not be
     * a scale-2 special case.
     */
    public function test_sub_unit_amount_on_a_zero_scale_currency_is_refused_with_422(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'XOF',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '0.500',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ])
            ->assertStatus(422);

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, RepositoryMovement::query()->count());
    }

    /**
     * Gate I1 (fix round 1) — a replay must not post a SECOND journal entry.
     * The GL post used to run unconditionally, ahead of record()'s idempotent
     * short-circuit, minting an extra posted `repository_adjustment` entry with
     * no compensating movement and nothing pointing at it. The controller now
     * reuses the document's entry; the partial unique index on
     * `journal_entries (source_type, source_id) WHERE … status='posted'` is the
     * database-level backstop.
     */
    public function test_idempotent_replay_posts_exactly_one_journal_entry(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $pinnedAdjustmentId = (string) Str::uuid();
        $payload = [
            'direction' => 'out',
            'amount' => '25.000',
            'reason_code' => 'count_variance',
            'reason_text' => 'Till was short at close.',
        ];

        $this->pinFirstGeneratedUuid($pinnedAdjustmentId);
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", $payload)
            ->assertCreated();
        Str::createUuidsNormally();

        $this->pinFirstGeneratedUuid($pinnedAdjustmentId);
        $second = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", $payload)
            ->assertCreated();
        Str::createUuidsNormally();

        $this->assertTrue($second->json('data.idempotent_replay'));

        $entries = JournalEntry::query()
            ->where('source_type', 'repository_adjustment')
            ->where('source_id', $pinnedAdjustmentId)
            ->get();
        $this->assertCount(1, $entries, 'a replay must reuse the journal entry, not post a second one');

        $adjustment = RepositoryAdjustment::query()->findOrFail($pinnedAdjustmentId);
        $this->assertSame($entries->firstOrFail()->id, $adjustment->journal_entry_id);
    }

    /**
     * Gate I5 (fix round 1) — the document justifies a posted journal entry and
     * an append-only movement, so it is immutable apart from the write-once
     * linkage backfill, and it can never be deleted.
     */
    public function test_document_refuses_mutation_and_deletion(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ])
            ->assertCreated();

        $adjustment = RepositoryAdjustment::query()->firstOrFail();

        // Amount rewrite refused.
        $adjustment->amount = '1.000';
        try {
            $adjustment->save();
            $this->fail('rewriting a document amount must throw');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Repointing an already-set linkage column refused (write-once).
        $fresh = RepositoryAdjustment::query()->findOrFail($adjustment->id);
        $fresh->movement_id = (string) Str::uuid();
        try {
            $fresh->save();
            $this->fail('repointing movement_id must throw');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('write-once', $e->getMessage());
        }

        // Deletion refused.
        $fresh = RepositoryAdjustment::query()->findOrFail($adjustment->id);
        try {
            $fresh->delete();
            $this->fail('deleting a document must throw');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('permanent', $e->getMessage());
        }

        $this->assertSame(1, RepositoryAdjustment::query()->count());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function tearDown(): void
    {
        Str::createUuidsNormally();

        parent::tearDown();
    }

    /**
     * Pin the NEXT uuid the framework generates to $uuid; every later uuid in
     * the same request falls back to a fresh random one. Caller resets with
     * {@see Str::createUuidsNormally()}.
     */
    private function pinFirstGeneratedUuid(string $uuid): void
    {
        $consumed = false;

        Str::createUuidsUsing(function () use ($uuid, &$consumed): UuidInterface {
            if ($consumed) {
                return Uuid::uuid4();
            }

            $consumed = true;

            return Uuid::fromString($uuid);
        });
    }

    private function accountFor(User $user, Company $company, SystemAccountPurpose $purpose): Account
    {
        return Account::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $company->id)
            ->where('system_purpose', $purpose->value)
            ->firstOrFail();
    }

    /**
     * @param  list<string>  $permissions
     * @return array{0: User, 1: Company}
     */
    private function makeUserWithPermissions(array $permissions): array
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'user_'.uniqid().'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'accountant',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$user, $company];
    }
}
