<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 13 (Treasury Money-Movement Spine, Wave D): repository freeze/unfreeze
 * admin methods. The freeze POLICY itself already lives inside
 * TreasuryMovementService::record() (Task 11) — this test proves the new
 * freeze()/unfreeze() methods correctly flip frozen_at/frozen_reason and that
 * the existing policy reacts to it as expected.
 */
final class RepositoryFreezeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // Rule 20: the port runs with NO CompanyContext in queued/fiscal
        // contexts — clear it so scale resolution is exercised via the explicit
        // intent currency, matching the worker reality.
        app(CompanyContext::class)->clear();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    private function service(): TreasuryMovementServiceInterface
    {
        return app(TreasuryMovementServiceInterface::class);
    }

    private function seedRepository(string $currency = 'TND', string $balance = '100.000'): PaymentRepository
    {
        return PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'currency' => $currency,
            'balance' => $balance,
            'next_movement_ordinal' => 0,
            'frozen_at' => null,
            'frozen_reason' => null,
        ]);
    }

    /**
     * @param  numeric-string  $amount
     */
    private function intent(
        PaymentRepository $repo,
        string $amount,
        bool $allowWhileFrozen,
    ): MovementIntent {
        return new MovementIntent(
            repositoryId: $repo->id,
            tenantId: $repo->tenant_id,
            companyId: $repo->company_id,
            direction: MovementDirection::In,
            amount: $amount,
            currency: $repo->currency,
            sourceType: MovementSourceType::Payment,
            sourceId: (string) Str::uuid(),
            idempotencyLeg: 'main',
            journalEntryId: null,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: null,
            allowWhileFrozen: $allowWhileFrozen,
        );
    }

    public function test_freeze_sets_frozen_at_and_reason_only(): void
    {
        $repo = $this->seedRepository(balance: '100.000');

        $this->service()->freeze($repo->id, 'end_of_day_count');

        $repo->refresh();
        $this->assertNotNull($repo->frozen_at);
        $this->assertSame('end_of_day_count', $repo->frozen_reason);
        // Untouched — freeze must never write balance.
        $this->assertSame('100.000', $repo->balance);
        $this->assertSame(0, $repo->next_movement_ordinal);
    }

    public function test_frozen_repository_rejects_interactive_record(): void
    {
        $repo = $this->seedRepository();
        $this->service()->freeze($repo->id, 'suspected_fraud');

        $this->expectException(RepositoryFrozenException::class);

        DB::transaction(fn () => $this->service()->record(
            $this->intent($repo, amount: '10.000', allowWhileFrozen: false),
        ));
    }

    public function test_frozen_repository_allows_projection_record_and_marks_recorded_while_frozen(): void
    {
        $repo = $this->seedRepository(balance: '100.000');
        $this->service()->freeze($repo->id, 'suspected_fraud');

        $result = DB::transaction(fn () => $this->service()->record(
            $this->intent($repo, amount: '15.000', allowWhileFrozen: true),
        ));

        $this->assertFalse($result->wasIdempotentHit);
        $movement = RepositoryMovement::findOrFail($result->movementId);
        $this->assertTrue($movement->recorded_while_frozen);

        $repo->refresh();
        $this->assertSame('115.000', $repo->balance);
        // Freeze columns untouched by record().
        $this->assertNotNull($repo->frozen_at);
        $this->assertSame('suspected_fraud', $repo->frozen_reason);
    }

    public function test_unfreeze_clears_columns_and_restores_interactive_writes(): void
    {
        $repo = $this->seedRepository(balance: '100.000');
        $this->service()->freeze($repo->id, 'end_of_day_count');

        $this->service()->unfreeze($repo->id);

        $repo->refresh();
        $this->assertNull($repo->frozen_at);
        $this->assertNull($repo->frozen_reason);
        $this->assertSame('100.000', $repo->balance);

        $result = DB::transaction(fn () => $this->service()->record(
            $this->intent($repo, amount: '20.000', allowWhileFrozen: false),
        ));

        $this->assertFalse($result->wasIdempotentHit);
        $movement = RepositoryMovement::findOrFail($result->movementId);
        $this->assertFalse($movement->recorded_while_frozen);

        $repo->refresh();
        $this->assertSame('120.000', $repo->balance);
    }
}
