<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Shared\Contracts\Loyalty\LoyaltyEarningContract;
use App\Shared\Contracts\Loyalty\SaleEarnContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SaleEarningServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedActiveSpendProgram(string $tenantId, string $rate = '1'): LoyaltyProgram
    {
        $program = LoyaltyProgram::factory()->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Active,
        ]);
        EarningRule::factory()->create([
            'program_id' => $program->id,
            'rule_type' => EarningRuleType::Spend,
            'reward_value' => $rate,
            'is_active' => true,
            'conditions' => [],
        ]);

        return $program;
    }

    private function enrollContactMember(string $tenantId, string $programId, string $contactId): Enrollment
    {
        $member = LoyaltyMember::factory()->create([
            'tenant_id' => $tenantId,
            'loyaltyable_type' => 'contact',
            'loyaltyable_id' => $contactId,
        ]);

        return Enrollment::factory()->create([
            'program_id' => $programId,
            'member_id' => $member->id,
            'status' => EnrollmentStatus::Active,
            'current_balance' => '0.000',
        ]);
    }

    private function context(string $tenantId, ?string $contactId, string $earnBase, string $sourceId): SaleEarnContext
    {
        return new SaleEarnContext(
            tenantId: $tenantId,
            contactId: $contactId,
            partnerId: null,
            currency: 'TND',
            sourceType: 'pos_receipt',
            sourceId: $sourceId,
            receiptNumber: 'POS-1',
            postedAt: CarbonImmutable::parse('2026-06-27 10:00:00'),
            earnBase: $earnBase,
        );
    }

    public function test_it_credits_points_to_an_enrolled_contact(): void
    {
        $tenantId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        $program = $this->seedActiveSpendProgram($tenantId, '1');
        $enrollment = $this->enrollContactMember($tenantId, $program->id, $contactId);

        // Projections/workers run with NO CompanyContext (rule 20) — prove it.
        app(CompanyContext::class)->clear();

        app(LoyaltyEarningContract::class)->earnForSale(
            $this->context($tenantId, $contactId, '12.000', 'receipt-1')
        );

        $txns = Transaction::where('enrollment_id', $enrollment->id)
            ->where('transaction_type', TransactionType::Earn)->get();
        self::assertCount(1, $txns);
        self::assertSame('12.000', $txns->first()->amount);
        self::assertSame('12.000', $enrollment->fresh()->current_balance);
    }

    public function test_no_member_is_a_silent_noop(): void
    {
        $tenantId = (string) Str::uuid();
        $this->seedActiveSpendProgram($tenantId);

        app(LoyaltyEarningContract::class)->earnForSale(
            $this->context($tenantId, (string) Str::uuid(), '12.000', 'receipt-2')
        );

        self::assertSame(0, Transaction::count());
    }

    public function test_disabled_tenant_without_active_program_is_a_noop(): void
    {
        $tenantId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        // No active program seeded → module guard returns early.
        LoyaltyMember::factory()->create([
            'tenant_id' => $tenantId,
            'loyaltyable_type' => 'contact',
            'loyaltyable_id' => $contactId,
        ]);

        app(LoyaltyEarningContract::class)->earnForSale(
            $this->context($tenantId, $contactId, '12.000', 'receipt-3')
        );

        self::assertSame(0, Transaction::count());
    }

    public function test_replaying_the_same_source_credits_points_exactly_once(): void
    {
        $tenantId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        $program = $this->seedActiveSpendProgram($tenantId, '1');
        $enrollment = $this->enrollContactMember($tenantId, $program->id, $contactId);

        $ctx = $this->context($tenantId, $contactId, '12.000', 'receipt-4');
        $service = app(LoyaltyEarningContract::class);
        $service->earnForSale($ctx);
        $service->earnForSale($ctx); // replay — must not double-credit

        self::assertCount(
            1,
            Transaction::where('enrollment_id', $enrollment->id)
                ->where('transaction_type', TransactionType::Earn)->get()
        );
        self::assertSame('12.000', $enrollment->fresh()->current_balance);
    }
}
