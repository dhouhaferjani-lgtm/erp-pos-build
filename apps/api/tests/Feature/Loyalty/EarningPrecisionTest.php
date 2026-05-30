<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
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
use App\Modules\Loyalty\Domain\Services\PointEarningService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Precision regression: PointEarningService earning calculation for TND (scale=3).
 *
 * Before fix (float multiplication in calculateSpendPoints):
 *   PHP: 0.1 * 0.1 = 0.010000000000000002 (IEEE 754 drift)
 *   (float)'0.010000000000000002' !== 0.01 → fails strict PHP float identity
 *   EarningProcessingService float-sum of 10k × that value drifts from 100.
 *
 * After fix (bcmath):
 *   bcmul('0.1', '0.1', 3+4=7) → '0.0100000'
 *   CurrencyScale::bcformat('0.0100000', 3) → '0.010'
 *   (float) '0.010' === 0.01 in PHP (no IEEE representation issue for this value)
 *   DB SUM of 10k × '0.010' rows → '100.000' exactly
 */
final class EarningPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private LoyaltyProgram $program;

    private LoyaltyMember $member;

    private Enrollment $enrollment;

    private EarningRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'TND Earning Precision Tenant',
            'slug' => 'tnd-earning-precision-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // TND company → CurrencyScale::for('TND') = 3
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'TND Earning Company',
            'legal_name' => 'TND Earning Company SARL',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        // Bind company context so scaleResolver->getScale() returns 3
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->program = LoyaltyProgram::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'TND Test Program',
            'program_type' => ProgramType::Points,
            'currency' => 'TND',
            'status' => ProgramStatus::Active,
        ]);

        $this->member = LoyaltyMember::create([
            'tenant_id' => $this->tenant->id,
            'phone' => '+21622000001',
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

        // Spend rule: reward_value = 0.1 (earn 0.1 points per TND spent)
        $this->rule = new EarningRule;
        $this->rule->id = (string) Str::uuid();
        $this->rule->program_id = $this->program->id;
        $this->rule->name = 'TND 0.1x Spend';
        $this->rule->rule_type = EarningRuleType::Spend;
        $this->rule->priority = 1;
        $this->rule->is_active = true;
        $this->rule->conditions = [];
        $this->rule->reward_value = '0.1';
        $this->rule->reward_type = 'points';
        $this->rule->max_earn_per_transaction = null;
        $this->rule->max_earn_per_day = null;
        $this->rule->start_date = null;
        $this->rule->end_date = null;
    }

    /**
     * Gold test: 10,000 earnings of 0.1 TND × 0.1 reward → ledger SUM = '100.000' exactly.
     *
     * The test has two gates:
     *
     * Gate 1 — per-earning value precision (strict PHP float identity):
     *   Before fix: 0.1 * 0.1 = 0.010000000000000002 (IEEE 754 drift) ≠ 0.01
     *   After fix: (float) CurrencyScale::bcformat(bcmul('0.1','0.1',7), 3)
     *            = (float) '0.010' = 0.01 exactly in PHP (no IEEE drift for this decimal)
     *
     * Gate 2 — ledger SUM (DB accumulation):
     *   10,000 Transaction rows each with amount = CurrencyScale::bcformat($points->value, 3)
     *   SQL SUM normalised at scale=3 must equal '100.000'.
     */
    public function test_10000_sequential_earnings_of_0_1_tnd_sum_to_100_exact(): void
    {
        /** @var PointEarningService $service */
        $service = app(PointEarningService::class);

        /** @var CurrencyScaleResolverInterface $resolver */
        $resolver = app(CurrencyScaleResolverInterface::class);
        $scale = $resolver->getScale(); // TND → 3

        $runningBalance = '0.000';

        for ($i = 0; $i < 10_000; $i++) {
            $points = $service->calculatePoints(
                $this->enrollment,
                ['amount' => '0.1', 'items' => []],
                $this->rule,
            );

            // Gate 1: per-earning value must be strictly equal to 0.01
            // Before fix: $points->value = 0.010000000000000002 !== 0.01
            // After fix:  $points->value = (float)'0.010' === 0.01 (PHP exact)
            if ($i === 0) {
                $this->assertSame(
                    0.01,
                    $points->value,
                    'PointsAmount::value for 0.1 TND × 0.1 reward (TND scale=3) must be '
                    .'exactly 0.01 (PHP strict float identity). '
                    .'Got: '.var_export($points->value, true).'. '
                    .'Fix: calculateSpendPoints must use bcmul + CurrencyScale::bcformat '
                    .'with scaleResolver instead of float multiplication.',
                );
            }

            // Gate 2: store as bcmath-formatted string in the DB
            $pointsStr = CurrencyScale::bcformat($points->value, $scale);
            $newBalance = bcadd($runningBalance, $pointsStr, $scale);

            Transaction::create([
                'enrollment_id' => $this->enrollment->id,
                'transaction_type' => TransactionType::Earn,
                'amount' => $pointsStr,
                'balance_before' => $runningBalance,
                'balance_after' => $newBalance,
                'created_at' => now(),
            ]);

            $runningBalance = $newBalance;
        }

        // Gate 2 assertion: DB ledger SUM must be '100.000' exactly
        /** @var string|float $rawSum */
        $rawSum = Transaction::where('enrollment_id', $this->enrollment->id)
            ->where('transaction_type', TransactionType::Earn)
            ->sum('amount');

        $this->assertSame(
            '100.000',
            bcadd((string) $rawSum, '0', $scale),
            'DB ledger SUM of 10,000 Transaction.amount rows must be exactly 100.000 at scale=3. '
            ."Got: {$rawSum}.",
        );
    }
}
