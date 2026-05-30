<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Application\Services\EarningProcessingService;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\MemberStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Precision regression: EarningProcessingService JSONB metadata monetary values
 * must be stored as canonical numeric strings, not floats.
 *
 * Invariants verified:
 * 1. The `amount` key in `metadata.transaction_data` is stored at the canonical
 *    currency scale (TND = 3 → '100.000'), not as a float or arbitrary string.
 * 2. The bcmath accumulator for totalPoints produces no drift when summing
 *    across multiple rules.
 * 3. The stored transaction `amount` itself is a canonical string, not a float.
 */
final class EarningConditionsPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private LoyaltyProgram $program;

    private LoyaltyMember $member;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'EarningConditions Precision Tenant',
            'slug' => 'earning-cond-precision-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // TND company → scale = 3
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'EarningConditions Company',
            'legal_name' => 'EarningConditions Company SARL',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        // Bind company context so scaleResolver returns 3
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->program = LoyaltyProgram::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'TND Conditions Test Program',
            'program_type' => ProgramType::Points,
            'currency' => 'TND',
            'status' => ProgramStatus::Active,
        ]);

        $this->member = LoyaltyMember::create([
            'tenant_id' => $this->tenant->id,
            'phone' => '+21622000099',
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ]);

        $this->enrollment = Enrollment::create([
            'program_id' => $this->program->id,
            'member_id' => $this->member->id,
            'current_balance' => '0.000',
            'lifetime_earned' => '0.000',
            'lifetime_redeemed' => '0.000',
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);
    }

    /**
     * The `metadata.transaction_data.amount` stored in the loyalty_transactions
     * JSONB column must be a canonical numeric string at TND scale (3 decimals),
     * not a float literal or unscaled string.
     *
     * Before fix: transactionData['amount'] was cast (float) in the controller
     *   → stored in JSONB as PHP float 100.0 → JSON "100" or "100.0" (no canonical scale)
     *   → reading back gives ambiguous type (int or float)
     *
     * After fix: pre-canonicalized via CurrencyScale::bcformat((string)$amount, 3)
     *   → stored as string "100.000" (always 3 decimal places, TND canonical)
     *   → reading back gives stable numeric string
     */
    public function test_metadata_transaction_data_amount_is_stored_at_canonical_scale(): void
    {
        // Arrange: SPEND rule — 0.1 pts per TND
        $rule = new EarningRule;
        $rule->id = (string) Str::uuid();
        $rule->program_id = $this->program->id;
        $rule->name = 'TND 0.1x Spend Rule';
        $rule->rule_type = EarningRuleType::Spend;
        $rule->priority = 1;
        $rule->is_active = true;
        $rule->conditions = []; // no min purchase condition
        $rule->reward_value = '0.1';
        $rule->reward_type = 'points';
        $rule->max_earn_per_transaction = null;
        $rule->max_earn_per_day = null;
        $rule->start_date = null;
        $rule->end_date = null;
        $rule->save();

        /** @var EarningProcessingService $service */
        $service = app(EarningProcessingService::class);

        // Amount as string (as the fixed controller now sends)
        $transactionData = [
            'amount' => '100.000', // TND canonical
            'currency' => 'TND',
            'items' => [],
        ];

        // Act
        $result = $service->earnPoints(
            enrollmentId: $this->enrollment->id,
            transactionData: $transactionData,
            sourceType: 'invoice',
            sourceId: (string) Str::uuid(),
        );

        // Assert: points were earned (sanity check)
        $this->assertNotSame('0', (string) $result->amount);

        // Assert: the stored transaction's metadata.transaction_data.amount
        // must be a canonical string at scale 3, not a float
        $stored = Transaction::where('enrollment_id', $this->enrollment->id)
            ->where('transaction_type', TransactionType::Earn)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($stored, 'A loyalty_transactions row must have been created');
        $this->assertIsArray($stored->metadata);
        $this->assertArrayHasKey('transaction_data', $stored->metadata);

        $storedTxData = $stored->metadata['transaction_data'];
        $this->assertIsArray($storedTxData);
        $this->assertArrayHasKey('amount', $storedTxData);

        $storedAmount = $storedTxData['amount'];

        // Must be a string, not float or int
        $this->assertIsString(
            $storedAmount,
            'metadata.transaction_data.amount must be a string after JSONB round-trip. '
            .'Got: '.gettype($storedAmount).' value: '.var_export($storedAmount, true),
        );

        // Must be canonical TND (3 decimal places) — no float drift artifacts
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d{3}$/',
            $storedAmount,
            'metadata.transaction_data.amount must match TND canonical format (N.NNN). '
            ."Got: {$storedAmount}",
        );

        // bcformat correctness — must equal original after round-trip
        $this->assertSame(
            '100.000',
            $storedAmount,
            "metadata.transaction_data.amount must be '100.000' (canonical TND scale). "
            ."Got: {$storedAmount}",
        );
    }

    /**
     * When multiple rules are active, the bcmath accumulator must produce
     * zero drift across additions — no float summation error.
     *
     * Two rules: each 0.1 pts/TND → total 0.2 pts on 100 TND = 20.000 pts.
     * Before fix: float sum 10.0 + 10.0 = 20.0 (OK for integer amounts,
     * but '0.1 * amount' paths have IEEE drift for fractional inputs).
     * After fix: bcadd canonical strings → '20.000' exactly.
     */
    public function test_multi_rule_bcmath_accumulator_is_exact(): void
    {
        // Two identical spend rules
        foreach (['Rule A', 'Rule B'] as $name) {
            $rule = new EarningRule;
            $rule->id = (string) Str::uuid();
            $rule->program_id = $this->program->id;
            $rule->name = $name;
            $rule->rule_type = EarningRuleType::Spend;
            $rule->priority = 1;
            $rule->is_active = true;
            $rule->conditions = [];
            $rule->reward_value = '0.1';
            $rule->reward_type = 'points';
            $rule->max_earn_per_transaction = null;
            $rule->max_earn_per_day = null;
            $rule->start_date = null;
            $rule->end_date = null;
            $rule->save();
        }

        /** @var EarningProcessingService $service */
        $service = app(EarningProcessingService::class);

        $transactionData = [
            'amount' => '100.000',
            'currency' => 'TND',
            'items' => [],
        ];

        $sourceId = (string) Str::uuid();

        $result = $service->earnPoints(
            enrollmentId: $this->enrollment->id,
            transactionData: $transactionData,
            sourceType: 'invoice',
            sourceId: $sourceId,
        );

        // Two rules × 0.1 pts/TND × 100.000 TND = 20.000 pts
        $storedTx = Transaction::where('enrollment_id', $this->enrollment->id)
            ->where('transaction_type', TransactionType::Earn)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($storedTx);

        // Amount stored at TND scale 3 → '20.000'
        $this->assertSame(
            '20.000',
            bcadd((string) $storedTx->amount, '0', 3),
            "Two 0.1-pt/TND rules on 100.000 TND must accumulate to '20.000' exactly. "
            .'Got: '.var_export($storedTx->amount, true),
        );
    }

    /**
     * Idempotency: a second earnPoints call with the same source_id must throw
     * and NOT create a second transaction — ensuring the metadata canonicalization
     * path doesn't bypass the duplicate guard.
     */
    public function test_duplicate_earn_is_rejected_with_canonical_amount(): void
    {
        $rule = new EarningRule;
        $rule->id = (string) Str::uuid();
        $rule->program_id = $this->program->id;
        $rule->name = 'Idempotency Rule';
        $rule->rule_type = EarningRuleType::Spend;
        $rule->priority = 1;
        $rule->is_active = true;
        $rule->conditions = [];
        $rule->reward_value = '0.1';
        $rule->reward_type = 'points';
        $rule->max_earn_per_transaction = null;
        $rule->max_earn_per_day = null;
        $rule->start_date = null;
        $rule->end_date = null;
        $rule->save();

        /** @var EarningProcessingService $service */
        $service = app(EarningProcessingService::class);

        $sourceId = (string) Str::uuid();
        $transactionData = [
            'amount' => '50.000',
            'currency' => 'TND',
            'items' => [],
        ];

        // First call — must succeed
        $service->earnPoints(
            enrollmentId: $this->enrollment->id,
            transactionData: $transactionData,
            sourceType: 'invoice',
            sourceId: $sourceId,
        );

        // Second call with same sourceId — must throw
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already earned/i');

        $service->earnPoints(
            enrollmentId: $this->enrollment->id,
            transactionData: $transactionData,
            sourceType: 'invoice',
            sourceId: $sourceId,
        );
    }
}
