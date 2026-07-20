<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Treasury spine (Task 26): `GET /api/v1/payment-repositories/{id}/movements`
 * — paginated read side of the append-only `repository_movements` ledger
 * written exclusively by TreasuryMovementService::record() (Task 11).
 */
final class RepositoryMovementsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $companyA;

    private Company $companyB;

    private User $user;

    private PaymentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Movements Tenant',
            'slug' => 'movements-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->companyB = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAX-B',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Movements User',
            'email' => 'movements@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['treasury.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->companyB->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->companyA->id);

        $this->repository = PaymentRepository::factory()->for($this->companyA)->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'TND',
            'balance' => '0.000',
            'next_movement_ordinal' => 0,
        ]);
    }

    private function service(): TreasuryMovementServiceInterface
    {
        return app(TreasuryMovementServiceInterface::class);
    }

    private function postedJournalEntryId(Company $company): string
    {
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'entry_number' => 'JE-'.Str::upper(Str::random(8)),
            'entry_date' => now()->toDateString(),
            'description' => 'movements endpoint test entry',
            'status' => 'posted',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  numeric-string  $amount
     */
    private function recordMovement(
        PaymentRepository $repository,
        MovementDirection $direction,
        string $amount,
        MovementSourceType $sourceType,
        ?string $sourceId = null,
        ?string $journalEntryId = null,
        ?CarbonImmutable $occurredAt = null,
    ): void {
        $intent = new MovementIntent(
            repositoryId: $repository->id,
            tenantId: $repository->tenant_id,
            companyId: $repository->company_id,
            direction: $direction,
            amount: $amount,
            currency: $repository->currency,
            sourceType: $sourceType,
            sourceId: $sourceId ?? (string) Str::uuid(),
            idempotencyLeg: 'main',
            journalEntryId: $journalEntryId,
            occurredAt: $occurredAt,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: null,
        );

        DB::transaction(fn () => $this->service()->record($intent));
    }

    public function test_returns_paginated_movements_newest_first_with_drill_down_links(): void
    {
        $je = $this->postedJournalEntryId($this->companyA);
        $sourceId = (string) Str::uuid();

        $this->recordMovement(
            $this->repository,
            MovementDirection::In,
            '10.000',
            MovementSourceType::Payment,
            sourceId: $sourceId,
            journalEntryId: $je,
            occurredAt: CarbonImmutable::parse('2026-07-01 09:00:00'),
        );
        $this->recordMovement(
            $this->repository,
            MovementDirection::In,
            '20.000',
            MovementSourceType::Expense,
            occurredAt: CarbonImmutable::parse('2026-07-02 09:00:00'),
        );
        $this->recordMovement(
            $this->repository,
            MovementDirection::Out,
            '5.000',
            MovementSourceType::Refund,
            occurredAt: CarbonImmutable::parse('2026-07-03 09:00:00'),
        );

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/payment-repositories/{$this->repository->id}/movements");

        $response->assertOk();

        $data = $response->json('data');
        $meta = $response->json('meta');

        $this->assertIsArray($data);
        $this->assertCount(3, $data);
        $this->assertSame(3, $meta['total']);
        $this->assertSame(20, $meta['per_page']);
        $this->assertSame(1, $meta['current_page']);

        // Newest first — by ordinal desc (3, 2, 1).
        $this->assertSame(3, $data[0]['ordinal']);
        $this->assertSame(2, $data[1]['ordinal']);
        $this->assertSame(1, $data[2]['ordinal']);

        $firstMovementRecordedRow = $data[2];
        $this->assertSame('payment', $firstMovementRecordedRow['source_type']);
        $this->assertSame($sourceId, $firstMovementRecordedRow['source_id']);
        $this->assertSame($je, $firstMovementRecordedRow['journal_entry_id']);
        $this->assertSame('in', $firstMovementRecordedRow['direction']);
        $this->assertIsString($firstMovementRecordedRow['amount']);
        $this->assertSame('10.000', $firstMovementRecordedRow['amount']);
        $this->assertIsString($firstMovementRecordedRow['balance_after']);
        $this->assertArrayHasKey('currency', $firstMovementRecordedRow);
        $this->assertArrayHasKey('reason_code', $firstMovementRecordedRow);
        $this->assertArrayHasKey('recorded_while_frozen', $firstMovementRecordedRow);
        $this->assertArrayHasKey('recorded_behind_checkpoint', $firstMovementRecordedRow);
        $this->assertArrayHasKey('occurred_at', $firstMovementRecordedRow);
    }

    public function test_filters_by_source_type(): void
    {
        $this->recordMovement($this->repository, MovementDirection::In, '10.000', MovementSourceType::Payment);
        $this->recordMovement($this->repository, MovementDirection::In, '20.000', MovementSourceType::Expense);
        $this->recordMovement($this->repository, MovementDirection::Out, '5.000', MovementSourceType::Payment);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/payment-repositories/{$this->repository->id}/movements?source_type=payment");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $row) {
            $this->assertSame('payment', $row['source_type']);
        }
    }

    public function test_filters_by_direction(): void
    {
        $this->recordMovement($this->repository, MovementDirection::In, '10.000', MovementSourceType::Payment);
        $this->recordMovement($this->repository, MovementDirection::In, '20.000', MovementSourceType::Expense);
        $this->recordMovement($this->repository, MovementDirection::Out, '5.000', MovementSourceType::Refund);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/payment-repositories/{$this->repository->id}/movements?direction=out");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('out', $data[0]['direction']);
        $this->assertSame('refund', $data[0]['source_type']);
    }

    public function test_filters_by_date_range(): void
    {
        $this->recordMovement(
            $this->repository,
            MovementDirection::In,
            '10.000',
            MovementSourceType::Payment,
            occurredAt: CarbonImmutable::parse('2026-06-01 09:00:00'),
        );
        $this->recordMovement(
            $this->repository,
            MovementDirection::In,
            '20.000',
            MovementSourceType::Payment,
            occurredAt: CarbonImmutable::parse('2026-07-15 09:00:00'),
        );
        $this->recordMovement(
            $this->repository,
            MovementDirection::In,
            '30.000',
            MovementSourceType::Payment,
            occurredAt: CarbonImmutable::parse('2026-08-30 09:00:00'),
        );

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/payment-repositories/{$this->repository->id}/movements?date_from=2026-07-01&date_to=2026-07-31");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('20.000', $data[0]['amount']);
    }

    /**
     * Audit M1: `occurred_at` is a full UTC timestamp, so a naive
     * `date_to=<today>` comparison against midnight would exclude every
     * same-day row (proven live: 59 unfiltered vs 58 filtered). `date_to`
     * must be inclusive of the entire end date.
     */
    public function test_date_to_is_inclusive_of_the_entire_end_date(): void
    {
        $this->recordMovement(
            $this->repository,
            MovementDirection::In,
            '10.000',
            MovementSourceType::Payment,
            occurredAt: CarbonImmutable::parse('2026-07-10 23:30:00'),
        );
        $this->recordMovement(
            $this->repository,
            MovementDirection::In,
            '20.000',
            MovementSourceType::Payment,
            occurredAt: CarbonImmutable::parse('2026-07-11 00:00:00'),
        );

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/payment-repositories/{$this->repository->id}/movements?date_to=2026-07-10");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('10.000', $data[0]['amount']);
    }

    public function test_movements_for_repository_in_another_company_is_not_found(): void
    {
        $otherRepository = PaymentRepository::factory()->for($this->companyB)->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'TND',
            'balance' => '0.000',
            'next_movement_ordinal' => 0,
        ]);

        $this->recordMovement($otherRepository, MovementDirection::In, '10.000', MovementSourceType::Payment);

        // Current CompanyContext is companyA — companyB's repository is out of scope.
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/payment-repositories/{$otherRepository->id}/movements");

        $response->assertStatus(404);
    }

    /**
     * Audit fix N5: a malformed (non-UUID) `{repository}` path param must
     * 404, not 500. On sqlite (the fast driver used by this suite) this
     * assertion passes even WITHOUT the `Str::isUuid()` guard — sqlite is
     * typeless, so `findOrFail()`'s `WHERE id = 'not-a-uuid'` simply matches
     * no row and throws `ModelNotFoundException` (404) regardless. The guard
     * is only load-bearing on Postgres, where the same malformed literal in a
     * uuid column comparison raises `22P02` (invalid input syntax) → HTTP 500
     * without it. See `.superpowers/sdd/audit-fix-3-report.md` for the pgsql
     * before/after evidence proving this test is genuinely red before the fix
     * and green after, on that driver.
     */
    public function test_malformed_repository_id_returns_404_not_500(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/payment-repositories/not-a-uuid/movements');

        $response->assertStatus(404);
    }

    public function test_forbidden_without_treasury_view_permission(): void
    {
        $this->user->revokePermissionTo('treasury.view');

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/payment-repositories/{$this->repository->id}/movements");

        $response->assertStatus(403);
    }
}
