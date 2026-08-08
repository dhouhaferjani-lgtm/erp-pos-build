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
