<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA V4 / T13 — `unwindAllocationsProRata()` must unwind EXACTLY the refunded
 * amount, no more and no less.
 *
 * Two mirror-image defects, both reachable with rounding dust:
 *
 * **Over-unwind (the original bug).** Non-last slices were capped against the
 * REMAINING amount but the LAST document's slice was `$remainingToAllocate`
 * verbatim, with no cap against that document's own live share. Because the
 * per-document share is TRUNCATED to currency scale, every non-last slice is ≤ its
 * exact share and all the dust lands on the last one. Fixture: three documents live
 * `0.333` each, `liveTotal = toUnwind = 0.999`. `bcdiv` truncation makes each exact
 * share `0.3329999…`, so doc1 and doc2 truncate to `0.332` and doc3 receives
 * `0.999 − 0.664 = 0.335` against a live share of `0.333` — over-unwound by `0.002`.
 * That produces a NEGATIVE lineage net, which the balance formula turns into
 * `balance_due` ABOVE the document total: the C6 invariant.
 *
 * **Under-unwind (what a cap-only fix does).** Capping the last slice at
 * `min($remaining, $live)` and stopping yields `0.332 + 0.332 + 0.333 = 0.997`
 * against `toUnwind = 0.999` — `0.002` of the refund is silently NEVER unwound. The
 * documents keep allocation they should have lost, so `balance_due` lands BELOW the
 * true receivable while the refund journal entry debited AR for the full `0.999`:
 * an AR understatement and a document-vs-GL divergence.
 *
 * The invariant that catches BOTH is `Σ slices == toUnwind` exactly. The two weaker
 * assertions the first fix round proposed — "no slice exceeds its live share" and
 * "`balance_due <= total`" — are both SATISFIED by the under-unwind defect, so they
 * are kept here but are explicitly insufficient on their own.
 *
 * TND (3 decimals) is used because the fixture needs a third decimal place.
 */
final class ProRataResidualRedistributionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private Partner $customer;

    private PaymentRefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->customer = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->refundService = app(PaymentRefundService::class);
    }

    /**
     * THE assertion. Red twice before the fix: `1.001`-style over-unwind against the
     * original code, `0.997` under-unwind against a cap-only implementation.
     */
    public function test_the_unwound_total_equals_the_refunded_amount_exactly(): void
    {
        [$payment, $invoices] = $this->threeWayDustFixture();

        $refund = $this->refundService->partialRefund($payment, '0.999', 'dust', $this->user->id);

        $unwound = PaymentAllocation::query()
            ->where('payment_id', $refund->id)
            ->get('amount')
            ->reduce(
                static fn (string $carry, PaymentAllocation $row): string => bcadd(
                    $carry,
                    ltrim((string) $row->amount, '-'),
                    3,
                ),
                '0',
            );

        self::assertSame(
            0,
            bccomp('0.999', $unwound, 3),
            "Σ slices must equal toUnwind exactly; got {$unwound}",
        );

        // Every document's own share is respected — 0.333 each, no over-unwind.
        foreach ($invoices as $invoice) {
            $perDocument = PaymentAllocation::query()
                ->where('payment_id', $refund->id)
                ->where('document_id', $invoice->id)
                ->get('amount')
                ->reduce(
                    static fn (string $carry, PaymentAllocation $row): string => bcadd(
                        $carry,
                        ltrim((string) $row->amount, '-'),
                        3,
                    ),
                    '0',
                );

            self::assertLessThanOrEqual(
                0,
                bccomp($perDocument, '0.333', 3),
                "document {$invoice->document_number} was over-unwound: {$perDocument} of a 0.333 live share",
            );
        }
    }

    /**
     * Kept from the first fix round, and explicitly recorded as INSUFFICIENT on its
     * own: the under-unwind defect satisfies it.
     */
    public function test_no_touched_document_ends_above_its_total(): void
    {
        [$payment, $invoices] = $this->threeWayDustFixture();

        $this->refundService->partialRefund($payment, '0.999', 'dust', $this->user->id);

        foreach ($invoices as $invoice) {
            $invoice->refresh();
            self::assertLessThanOrEqual(
                0,
                bccomp((string) $invoice->balance_due, (string) $invoice->total, 3),
                'balance_due must never exceed the document total (C6)',
            );
        }
    }

    /**
     * The end state the whole lane protects: after the dust-scale partial refund AND
     * a reversal, every document's lineage sums to exactly zero. This is where the
     * two halves meet — T13 stops the drift at its source, D-3b's negative mirrors
     * neutralise whatever already drifted.
     */
    public function test_a_reversal_after_the_dust_refund_nets_every_document_to_zero(): void
    {
        [$payment, $invoices] = $this->threeWayDustFixture();

        $this->refundService->partialRefund($payment, '0.999', 'dust', $this->user->id);
        $reversal = $this->refundService->reversePayment(
            $payment->fresh() ?? $payment,
            'reverse after dust refund',
            $this->user->id,
        );
        self::assertInstanceOf(Payment::class, $reversal);

        foreach ($invoices as $invoice) {
            $sum = PaymentAllocation::query()
                ->where('document_id', $invoice->id)
                ->get('amount')
                ->reduce(
                    static fn (string $carry, PaymentAllocation $row): string => bcadd($carry, (string) $row->amount, 3),
                    '0',
                );

            self::assertSame(
                0,
                bccomp($sum, '0', 3),
                "document {$invoice->document_number}: lineage must net to exactly 0, got {$sum}",
            );

            $invoice->refresh();
            self::assertSame(
                0,
                bccomp((string) $invoice->balance_due, (string) $invoice->total, 3),
                "document {$invoice->document_number}: balance_due must equal total",
            );
        }
    }

    /** The four existing pro-rata behaviours must be unaffected by the redistribution. */
    public function test_a_clean_pro_rata_split_is_unchanged(): void
    {
        $invA = $this->invoice('600.000');
        $invB = $this->invoice('400.000');
        $payment = $this->payment('1000.000', [[$invA, '600.000'], [$invB, '400.000']]);

        $refund = $this->refundService->partialRefund($payment, '250.000', 'clean split', $this->user->id);

        $byDocument = PaymentAllocation::query()
            ->where('payment_id', $refund->id)
            ->get()
            ->keyBy('document_id');

        self::assertSame(0, bccomp('-150.000', (string) $byDocument[$invA->id]->amount, 3));
        self::assertSame(0, bccomp('-100.000', (string) $byDocument[$invB->id]->amount, 3));
    }

    /**
     * @return array{Payment, list<Document>}
     */
    private function threeWayDustFixture(): array
    {
        $invoices = [
            $this->invoice('0.333'),
            $this->invoice('0.333'),
            $this->invoice('0.333'),
        ];

        $allocations = [];
        foreach ($invoices as $invoice) {
            $allocations[] = [$invoice, '0.333'];
        }

        return [$this->payment('0.999', $allocations), $invoices];
    }

    private function invoice(string $total): Document
    {
        return Document::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-'.Str::random(10),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Paid,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'balance_due' => '0.000',
            'currency' => 'TND',
        ]);
    }

    /**
     * @param  list<array{0: Document, 1: string}>  $allocations
     */
    private function payment(string $amount, array $allocations): Payment
    {
        $payment = Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PMT-'.Str::random(10),
            'created_by' => $this->user->id,
        ]);

        foreach ($allocations as [$document, $allocated]) {
            PaymentAllocation::query()->create([
                'id' => Str::uuid()->toString(),
                'payment_id' => $payment->id,
                'document_id' => $document->id,
                'amount' => $allocated,
            ]);
        }

        return $payment;
    }
}
