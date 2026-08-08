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
use App\Modules\Treasury\Domain\Events\PaymentReversed;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA V4 / T9 — the reversing document on the wire, and in the audit event.
 *
 * `PaymentReversed`'s existing `amount` field is FROZEN (events are immutable) and
 * still carries the ORIGINAL payment's amount. Post-V4 a reversal is NET of the
 * refund lineage, so that field alone would misreport the magnitude: reversing a
 * 1000 payment already refunded 400 unwinds 600 while `amount` says 1000. The two
 * additive trailing fields close that gap without touching the frozen one.
 *
 * The controller response is a RESHAPE, not an addition: `data` used to BE the
 * payment, and is now `{payment, reversal}`. Verified safe for the app because the
 * sole production consumer (`PaymentDetailPage.tsx`) discards the body and just
 * invalidates its queries; the one e2e reader is updated in the same task.
 */
final class PaymentReversalApiAndEventTest extends TestCase
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
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'EUR',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->givePermissionTo(['payments.view', 'payments.reverse', 'payments.refund']);
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
     * D-15 on the C6 fixture: `reversed_amount` is the NET (600) while the frozen
     * `amount` stays the original (1000).
     */
    public function test_the_reversed_event_carries_the_net_alongside_the_frozen_original_amount(): void
    {
        Event::fake([PaymentReversed::class]);

        $invoice = $this->paidInvoice('1000.00');
        $original = $this->paymentAllocatedTo($invoice, '1000.00');
        $this->refundService->partialRefund($original, '400.00', 'partial', $this->user->id);

        $reversal = $this->refundService->reversePayment(
            $original->fresh() ?? $original,
            'net vs gross',
            $this->user->id,
        );
        self::assertInstanceOf(Payment::class, $reversal);

        Event::assertDispatched(
            PaymentReversed::class,
            function (PaymentReversed $event) use ($original, $reversal): bool {
                self::assertSame($original->id, $event->paymentId);
                self::assertSame('1000.000', $event->amount, 'the frozen field still reports the ORIGINAL amount');
                self::assertSame($reversal->id, $event->reversalPaymentId);
                // Both money fields pass through the model's `decimal:3` cast, so
                // the payload is shape-consistent — `'600.000'` beside
                // `'1000.000'`, not `'600.00'` beside `'1000.000'`. A payload diff
                // or a lexical comparator sees one convention, not two.
                self::assertSame('600.000', $event->reversedAmount, 'the additive field reports the NET');
                self::assertSame(
                    0,
                    bccomp((string) $reversal->amount, '-600', 3),
                    'the persisted reversal row agrees with the event',
                );

                $payload = $event->getAuditPayload();
                self::assertSame('1000.000', $payload['amount']);
                self::assertSame($reversal->id, $payload['reversal_payment_id']);
                self::assertSame('600.000', $payload['reversed_amount']);
                self::assertSame(
                    strlen(substr(strrchr($payload['amount'], '.') ?: '', 1)),
                    strlen(substr(strrchr($payload['reversed_amount'], '.') ?: '', 1)),
                    'both money fields in the payload must carry the same decimal shape',
                );

                return true;
            },
        );
    }

    /** Pre-V4 construction sites omit both params and keep a string payload. */
    public function test_a_pre_v4_construction_still_yields_a_string_payload(): void
    {
        $event = new PaymentReversed(
            paymentId: 'pay-001',
            tenantId: 'tenant-001',
            companyId: 'company-001',
            amount: '200.00',
            currency: 'EUR',
            reversedAt: '2026-03-24T11:00:00+00:00',
        );

        self::assertNull($event->reversalPaymentId);
        self::assertNull($event->reversedAmount);
        self::assertSame('', $event->getAuditPayload()['reversal_payment_id']);
        self::assertSame('', $event->getAuditPayload()['reversed_amount']);
    }

    public function test_the_reverse_endpoint_returns_the_payment_and_its_reversing_document(): void
    {
        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo($invoice, '500.00');

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payments/{$original->id}/reverse", ['reason' => 'wire shape'])
            ->assertOk();

        $response->assertJsonPath('data.payment.id', $original->id);
        $response->assertJsonPath('data.payment.status', PaymentStatus::Reversed->value);
        $response->assertJsonPath('data.reversal.payment_type', PaymentType::Reversal->value);
        $response->assertJsonPath('data.reversal.original_payment_id', $original->id);
        $response->assertJsonPath('data.reversal.amount', '-500.000');
        // The reversal is returned with its relations loaded, as the payment is.
        self::assertNotNull($response->json('data.reversal.partner'));
    }

    /**
     * D-13 case 2 over HTTP: already unwound by a full refund. 200 with
     * `data.reversal === null` — NOT a 422. Turning a legitimate,
     * currently-successful caller path into an error would be a regression.
     */
    public function test_an_already_unwound_payment_returns_200_with_a_null_reversal(): void
    {
        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo($invoice, '500.00');
        $this->refundService->refundPayment($original, 'full refund', $this->user->id);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payments/{$original->id}/reverse", ['reason' => 'nothing to reverse'])
            ->assertOk();

        $response->assertJsonPath('data.payment.id', $original->id);
        self::assertNull($response->json('data.reversal'));
        self::assertSame(
            0,
            Payment::query()->where('payment_type', PaymentType::Reversal->value)->count(),
        );
    }

    private function paidInvoice(string $total): Document
    {
        return Document::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-'.Str::random(8),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Paid,
            'subtotal' => $total,
            'tax_amount' => '0.00',
            'total' => $total,
            'balance_due' => '0.00',
            'currency' => 'EUR',
        ]);
    }

    private function paymentAllocatedTo(Document $invoice, string $amount): Payment
    {
        $payment = Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PMT-'.Str::random(8),
            'created_by' => $this->user->id,
        ]);

        PaymentAllocation::query()->create([
            'id' => Str::uuid()->toString(),
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => $amount,
        ]);

        return $payment;
    }
}
