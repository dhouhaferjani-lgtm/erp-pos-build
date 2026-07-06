<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Contact\Domain\Contact;
use App\Modules\Identity\Domain\User;
use App\Modules\Loyalty\Application\Listeners\EarnPointsOnReceiptCompleted;
use App\Modules\Loyalty\Application\Services\EarningProcessingService;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Events\ReceiptCompleted;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDOException;
use Tests\TestCase;

/**
 * Task 7 (LB-4) — atomic earn dedupe via dedicated source columns +
 * partial unique index `loyalty_txn_earn_source_unique`.
 *
 * Closes the TOCTOU between the two queued earn paths (fiscal projection +
 * EarnPointsOnReceiptCompleted listener) that could double-credit the same
 * receipt.
 */
final class EarningDedupeConstraintTest extends TestCase
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

    private function service(): EarningProcessingService
    {
        return app(EarningProcessingService::class);
    }

    /**
     * Fabricate a PG-shaped QueryException with the given SQLSTATE and a message
     * that carries the constraint name (as PG surfaces it). Mirrors how
     * QueryException derives errorInfo/message from the previous PDOException.
     */
    private function makeQueryException(string $sqlState, string $constraintMessage): QueryException
    {
        $previous = new PDOException("SQLSTATE[{$sqlState}]: Unique violation: 7 ERROR: {$constraintMessage}");
        $previous->errorInfo = [$sqlState, 7, $constraintMessage];

        return new QueryException(
            'tenant',
            'insert into "loyalty_transactions" (...) values (...)',
            [],
            $previous,
        );
    }

    public function test_concurrent_style_duplicate_insert_hits_unique_constraint(): void
    {
        $tenantId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        $program = $this->seedActiveSpendProgram($tenantId, '1');
        $enrollment = $this->enrollContactMember($tenantId, $program->id, $contactId);

        $sourceId = (string) Str::uuid();
        $this->service()->earnPoints(
            $enrollment->id,
            ['amount' => '12.000', 'currency' => 'TND'],
            'pos_receipt',
            $sourceId,
        );

        // Bypass the pre-check: insert a second earn row directly with the same
        // (enrollment_id, source_type, source_id) → the partial unique index must fire.
        $this->expectException(QueryException::class);

        $dup = new Transaction([
            'enrollment_id' => $enrollment->id,
            'transaction_type' => TransactionType::Earn,
            'amount' => '12.000',
            'balance_before' => '12.000',
            'balance_after' => '24.000',
            'description' => 'racing duplicate',
            'source_type' => 'pos_receipt',
            'source_id' => $sourceId,
            'metadata' => ['source_type' => 'pos_receipt', 'source_id' => $sourceId],
            'created_at' => now(),
        ]);
        $dup->save();
    }

    public function test_translate_earn_duplicate_converts_constraint_violation(): void
    {
        $service = $this->service();

        // (a) 23505 + our index name → InvalidArgumentException with 'already earned'.
        $onIndex = $this->makeQueryException(
            '23505',
            'duplicate key value violates unique constraint "loyalty_txn_earn_source_unique"',
        );
        $result = $service->translateEarnDuplicate($onIndex, 'pos_receipt', 'receipt-a');
        self::assertInstanceOf(InvalidArgumentException::class, $result);
        self::assertStringContainsString('already earned', $result->getMessage());
        self::assertStringContainsString('pos_receipt', $result->getMessage());
        self::assertStringContainsString('receipt-a', $result->getMessage());

        // (b) 23505 but a DIFFERENT constraint → null (rethrown as-is by caller).
        $onOther = $this->makeQueryException(
            '23505',
            'duplicate key value violates unique constraint "some_other_unique"',
        );
        self::assertNull($service->translateEarnDuplicate($onOther, 'pos_receipt', 'receipt-a'));

        // (c) non-23505 (foreign-key violation) → null.
        $fk = $this->makeQueryException(
            '23503',
            'insert or update on table "loyalty_transactions" violates foreign key constraint',
        );
        self::assertNull($service->translateEarnDuplicate($fk, 'pos_receipt', 'receipt-a'));
    }

    public function test_duplicate_earn_via_earn_points_still_yields_already_earned_and_one_row(): void
    {
        $tenantId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        $program = $this->seedActiveSpendProgram($tenantId, '1');
        $enrollment = $this->enrollContactMember($tenantId, $program->id, $contactId);

        $sourceId = (string) Str::uuid();
        $service = $this->service();
        $service->earnPoints($enrollment->id, ['amount' => '12.000', 'currency' => 'TND'], 'pos_receipt', $sourceId);

        try {
            $service->earnPoints($enrollment->id, ['amount' => '12.000', 'currency' => 'TND'], 'pos_receipt', $sourceId);
            self::fail('Expected InvalidArgumentException for duplicate earn');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('already earned', $e->getMessage());
        }

        self::assertSame(
            1,
            Transaction::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('transaction_type', TransactionType::Earn)
                ->count(),
        );
    }

    public function test_source_columns_written_and_backfill_query_shape(): void
    {
        $tenantId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        $program = $this->seedActiveSpendProgram($tenantId, '1');
        $enrollment = $this->enrollContactMember($tenantId, $program->id, $contactId);

        $sourceId = (string) Str::uuid();
        $this->service()->earnPoints(
            $enrollment->id,
            ['amount' => '12.000', 'currency' => 'TND'],
            'pos_receipt',
            $sourceId,
        );

        /** @var Transaction $row */
        $row = Transaction::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('transaction_type', TransactionType::Earn)
            ->firstOrFail();

        self::assertSame('pos_receipt', $row->source_type);
        self::assertSame($sourceId, $row->source_id);
        // Metadata still carries the refs (audit intact).
        self::assertSame('pos_receipt', $row->metadata['source_type'] ?? null);
        self::assertSame($sourceId, $row->metadata['source_id'] ?? null);
    }

    // --- Task 8 (LB-4): quiet + rule-19-clean listener + earn-eligibility guard ---

    /**
     * @return array{0: Company, 1: Location, 2: Terminal, 3: User}
     */
    private function createReceiptScaffold(string $tenantId): array
    {
        $company = Company::factory()->create(['tenant_id' => $tenantId]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);
        $cashier = User::factory()->create(['tenant_id' => $tenantId]);

        return [$company, $location, $terminal, $cashier];
    }

    private function createContact(string $tenantId, string $companyId): Contact
    {
        // NOTE: 'id' is deliberately NOT in Contact::$fillable, so a mass-assigned
        // 'id' is silently dropped and HasUuids generates a fresh one — return the
        // model and read ->id back rather than trying to pin a pre-chosen UUID.
        return Contact::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'first_name' => 'Jane',
            'last_name' => 'Doe-'.Str::random(6),
            'email' => 'contact-'.Str::uuid().'@example.com',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array{0: Company, 1: Location, 2: Terminal, 3: User}  $scaffold
     */
    private function createReceiptFor(
        string $tenantId,
        array $scaffold,
        string $contactId,
        string $total,
        ReceiptType $receiptType = ReceiptType::Sale,
        bool $isTraining = false,
        ?string $originalReceiptId = null,
        ?ReturnReason $returnReason = null,
    ): Receipt {
        [$company, $location, $terminal, $cashier] = $scaffold;

        return Receipt::factory()
            ->withTotal($total, '0.000')
            ->create([
                'tenant_id' => $tenantId,
                'company_id' => $company->id,
                'location_id' => $location->id,
                'terminal_id' => $terminal->id,
                'cashier_id' => $cashier->id,
                'contact_id' => $contactId,
                'currency' => 'TND',
                'receipt_type' => $receiptType,
                'is_training' => $isTraining,
                'training_flag' => $isTraining,
                'original_receipt_id' => $originalReceiptId,
                'return_reason' => $returnReason,
                'fiscal_status' => FiscalStatus::Fiscalized,
            ]);
    }

    private function createReceiptLine(Receipt $receipt, string $unitPrice, string $lineTotal): ReceiptLine
    {
        return ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '1.0000',
            'unit' => 'pcs',
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
        ]);
    }

    private function listener(): EarnPointsOnReceiptCompleted
    {
        return app(EarnPointsOnReceiptCompleted::class);
    }

    public function test_listener_does_not_log_error_and_does_not_double_earn_on_duplicate_receipt(): void
    {
        $tenantId = (string) Str::uuid();
        $scaffold = $this->createReceiptScaffold($tenantId);
        $contact = $this->createContact($tenantId, $scaffold[0]->id);
        $program = $this->seedActiveSpendProgram($tenantId, '1');
        $enrollment = $this->enrollContactMember($tenantId, $program->id, $contact->id);

        $receipt = $this->createReceiptFor($tenantId, $scaffold, $contact->id, '10.000');
        $this->createReceiptLine($receipt, '10.000', '10.000');

        // Pre-existing earn for this exact receipt (simulates the fiscal
        // projection having already earned it, or a redelivered queue job).
        $this->service()->earnPoints(
            $enrollment->id,
            ['amount' => '10.000', 'currency' => 'TND'],
            'pos_receipt',
            $receipt->id,
        );

        $event = new ReceiptCompleted(
            receiptId: $receipt->id,
            tenantId: $tenantId,
            companyId: $scaffold[0]->id,
            customerId: null,
            totalAmount: '10.000',
            currency: 'TND',
        );

        Log::spy();

        $this->listener()->handle($event);

        Log::shouldNotHaveReceived('error');

        self::assertSame(
            1,
            Transaction::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('transaction_type', TransactionType::Earn)
                ->count(),
        );
    }

    public function test_listener_earns_fresh_receipt_with_exact_bcmath_string_amount(): void
    {
        $tenantId = (string) Str::uuid();
        $scaffold = $this->createReceiptScaffold($tenantId);
        $contact = $this->createContact($tenantId, $scaffold[0]->id);
        $program = $this->seedActiveSpendProgram($tenantId, '1');
        $enrollment = $this->enrollContactMember($tenantId, $program->id, $contact->id);

        $receipt = $this->createReceiptFor($tenantId, $scaffold, $contact->id, '25.500');
        $this->createReceiptLine($receipt, '25.500', '25.500');

        $event = new ReceiptCompleted(
            receiptId: $receipt->id,
            tenantId: $tenantId,
            companyId: $scaffold[0]->id,
            customerId: null,
            totalAmount: '25.500',
            currency: 'TND',
        );

        Log::spy();

        $this->listener()->handle($event);

        Log::shouldNotHaveReceived('error');

        $transaction = Transaction::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('transaction_type', TransactionType::Earn)
            ->where('source_type', 'pos_receipt')
            ->where('source_id', $receipt->id)
            ->firstOrFail();

        self::assertSame('25.500', $transaction->amount);
    }

    public function test_listener_earns_nothing_for_a_non_sale_receipt(): void
    {
        $tenantId = (string) Str::uuid();
        $scaffold = $this->createReceiptScaffold($tenantId);
        $contact = $this->createContact($tenantId, $scaffold[0]->id);
        $program = $this->seedActiveSpendProgram($tenantId, '1');
        $enrollment = $this->enrollContactMember($tenantId, $program->id, $contact->id);

        // Original sale receipt the return references (FK + CHECK constraint).
        $originalSale = $this->createReceiptFor($tenantId, $scaffold, $contact->id, '25.500');
        $this->createReceiptLine($originalSale, '25.500', '25.500');

        $returnReceipt = $this->createReceiptFor(
            $tenantId,
            $scaffold,
            $contact->id,
            '-25.500',
            receiptType: ReceiptType::Return,
            originalReceiptId: $originalSale->id,
            returnReason: ReturnReason::CustomerChangedMind,
        );

        $event = new ReceiptCompleted(
            receiptId: $returnReceipt->id,
            tenantId: $tenantId,
            companyId: $scaffold[0]->id,
            customerId: null,
            totalAmount: '-25.500',
            currency: 'TND',
        );

        Log::spy();

        $this->listener()->handle($event);

        Log::shouldNotHaveReceived('error');

        self::assertSame(
            0,
            Transaction::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('transaction_type', TransactionType::Earn)
                ->count(),
        );
    }

    /**
     * Task 9 review fix (Important-1): `Product::category_id` is an UNCAST
     * nullable bigint FK, so on pgsql it arrives at
     * `$line->product->category_id` as a numeric STRING ("5"), while
     * `SaleEarningService::resolveItemCategories` (the device-sale earn path)
     * normalises to `int`. `PointEarningService::calculateCategoryPoints`
     * matches with a strict `in_array(..., true)`, so a category rule keyed on
     * an int must earn on BOTH paths identically — this proves the listener
     * path now normalises the same way.
     */
    public function test_listener_category_rule_earns_with_int_normalized_category_id(): void
    {
        $tenantId = (string) Str::uuid();
        $scaffold = $this->createReceiptScaffold($tenantId);
        $contact = $this->createContact($tenantId, $scaffold[0]->id);

        $category = Category::factory()->create(['company_id' => $scaffold[0]->id]);
        $product = Product::factory()->create([
            'tenant_id' => $tenantId,
            'company_id' => $scaffold[0]->id,
            'category_id' => $category->id,
        ]);

        $program = LoyaltyProgram::factory()->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Active,
        ]);
        EarningRule::factory()->create([
            'program_id' => $program->id,
            'rule_type' => EarningRuleType::Category,
            'reward_value' => '5',
            'is_active' => true,
            'conditions' => ['category_ids' => [$category->id]],
        ]);
        $enrollment = $this->enrollContactMember($tenantId, $program->id, $contact->id);

        $receipt = $this->createReceiptFor($tenantId, $scaffold, $contact->id, '10.000');
        ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '2.0000',
            'unit' => 'pcs',
            'unit_price' => '5.000',
            'line_total' => '10.000',
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
        ]);

        $event = new ReceiptCompleted(
            receiptId: $receipt->id,
            tenantId: $tenantId,
            companyId: $scaffold[0]->id,
            customerId: null,
            totalAmount: '10.000',
            currency: 'TND',
        );

        Log::spy();

        $this->listener()->handle($event);

        Log::shouldNotHaveReceived('error');

        $transaction = Transaction::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('transaction_type', TransactionType::Earn)
            ->firstOrFail();

        // qty 2 x reward_value 5 = 10.000 — only matches because category_id is
        // int-normalized in the listener the same way SaleEarningService
        // normalizes it on the device-sale path (review fix 1).
        self::assertSame('10.000', $transaction->amount);
    }
}
