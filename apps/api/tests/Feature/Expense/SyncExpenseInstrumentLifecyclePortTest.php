<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Events\InstrumentCancelled;
use App\Modules\Treasury\Domain\Events\InstrumentCleared;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\DTOs\OutboundInstrumentPaymentLink;
use App\Shared\Contracts\Treasury\OutboundInstrumentPaymentLinkResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 3 (treasury burn-down) — proves the module-boundary seam: the Expense
 * listener `SyncExpenseOnInstrumentLifecycle` resolves an outbound instrument's
 * settlement linkage through the injected `OutboundInstrumentPaymentLinkResolver`
 * port, NOT by reading the Treasury `PaymentInstrument` model.
 *
 * Proof-by-value: a real instrument exists (its FK must resolve) carrying
 * `repository_id = null` + `payment_method_id = methodA`, but the bound fake port
 * returns a DIFFERENT `repository_id`/`payment_method_id`. The projected expense
 * metadata takes the PORT's values, which is impossible if the listener still
 * read the instrument model directly.
 */
final class SyncExpenseInstrumentLifecyclePortTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    /** Payment method carried by the real instrument row. */
    private PaymentMethod $methodOnInstrument;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->methodOnInstrument = $this->paymentMethod('EXP-CHK-INSTR');
    }

    public function test_clear_projects_the_port_linkage_not_the_instrument_model(): void
    {
        // The linkage the fake port advertises is deliberately DIFFERENT from
        // what the real instrument row carries.
        $portRepository = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => RepositoryType::BankAccount,
        ]);
        $portMethod = $this->paymentMethod('EXP-CHK-PORT');

        $instrument = $this->outboundInstrument(repositoryId: null, methodId: $this->methodOnInstrument->id);
        $metadata = $this->linkedExpense($instrument->id, $this->methodOnInstrument->id);

        $this->bindPort($instrument->id, new OutboundInstrumentPaymentLink(
            repositoryId: $portRepository->id,
            paymentMethodId: $portMethod->id,
        ));

        Event::dispatch(new InstrumentCleared(
            $instrument->id,
            $this->tenant->id,
            $this->company->id,
            '125.000',
            '2026-07-18',
        ));

        $metadata->refresh();
        self::assertTrue($metadata->is_paid);
        self::assertSame('2026-07-18', $metadata->paid_at?->toDateString());
        self::assertSame('2026-07-18', $metadata->payment_date?->toDateString());
        // Port values win — the instrument itself has repository_id null / methodOnInstrument.
        self::assertSame($portRepository->id, $metadata->payment_repository_id);
        self::assertSame($portMethod->id, $metadata->payment_method_id);
        self::assertNotSame($this->methodOnInstrument->id, $metadata->payment_method_id);
    }

    public function test_clear_fails_loud_when_the_port_resolves_no_outbound_link(): void
    {
        $instrument = $this->outboundInstrument(repositoryId: null, methodId: $this->methodOnInstrument->id);
        $metadata = $this->linkedExpense($instrument->id, $this->methodOnInstrument->id);

        $this->bindPort($instrument->id, null);

        try {
            Event::dispatch(new InstrumentCleared(
                $instrument->id,
                $this->tenant->id,
                $this->company->id,
                '125.000',
                '2026-07-18',
            ));
            $this->fail('An unresolved outbound link must fail loudly.');
        } catch (\DomainException $exception) {
            self::assertSame(
                'The cleared expense instrument could not be resolved as outbound.',
                $exception->getMessage(),
            );
        }

        $metadata->refresh();
        self::assertFalse($metadata->is_paid);
        self::assertNull($metadata->paid_at);
        self::assertSame($instrument->id, $metadata->payment_instrument_id);
    }

    public function test_cancel_unlinks_without_consulting_the_read_port(): void
    {
        $repository = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => RepositoryType::BankAccount,
        ]);
        $instrument = $this->outboundInstrument(repositoryId: $repository->id, methodId: $this->methodOnInstrument->id);
        $metadata = $this->linkedExpense(
            $instrument->id,
            $this->methodOnInstrument->id,
            isPaid: true,
            repositoryId: $repository->id,
        );

        // A port that explodes if touched proves cancel never reads the instrument.
        $this->app->instance(
            OutboundInstrumentPaymentLinkResolver::class,
            new class implements OutboundInstrumentPaymentLinkResolver
            {
                public function resolveOutboundLink(
                    string $instrumentId,
                    string $tenantId,
                    string $companyId,
                ): ?OutboundInstrumentPaymentLink {
                    throw new \LogicException('cancel must not consult the read port');
                }
            },
        );

        Event::dispatch(new InstrumentCancelled(
            $instrument->id,
            $this->tenant->id,
            $this->company->id,
            '125.000',
            'Cheque voided',
            '2026-07-18',
        ));

        $metadata->refresh();
        self::assertFalse($metadata->is_paid);
        self::assertNull($metadata->payment_instrument_id);
        self::assertNull($metadata->payment_repository_id);
        self::assertNull($metadata->payment_method_id);
    }

    private function bindPort(string $expectedInstrumentId, ?OutboundInstrumentPaymentLink $link): void
    {
        $this->app->instance(
            OutboundInstrumentPaymentLinkResolver::class,
            new class($expectedInstrumentId, $link) implements OutboundInstrumentPaymentLinkResolver
            {
                public function __construct(
                    private readonly string $expectedInstrumentId,
                    private readonly ?OutboundInstrumentPaymentLink $link,
                ) {}

                public function resolveOutboundLink(
                    string $instrumentId,
                    string $tenantId,
                    string $companyId,
                ): ?OutboundInstrumentPaymentLink {
                    if ($instrumentId !== $this->expectedInstrumentId) {
                        throw new \LogicException('unexpected instrument id at the read port');
                    }

                    return $this->link;
                }
            },
        );
    }

    private function paymentMethod(string $codePrefix): PaymentMethod
    {
        return PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => $codePrefix.'-'.Str::upper(Str::random(6)),
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
    }

    private function outboundInstrument(?string $repositoryId, string $methodId): PaymentInstrument
    {
        return PaymentInstrument::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $methodId,
            'reference' => 'CHK-'.Str::upper(Str::random(6)),
            'amount' => '125.000',
            'currency' => 'TND',
            'received_date' => '2026-07-18',
            'status' => InstrumentStatus::Received,
            'direction' => InstrumentDirection::Outbound,
            'kind' => InstrumentKind::Cheque,
            'repository_id' => $repositoryId,
        ]);
    }

    private function linkedExpense(
        string $instrumentId,
        string $methodId,
        bool $isPaid = false,
        ?string $repositoryId = null,
    ): ExpenseMetadata {
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Posted,
            'document_number' => 'EXP-'.Str::upper(Str::random(8)),
            'document_date' => '2026-07-18',
            'currency' => 'TND',
            'subtotal' => '125.000',
            'tax_amount' => '0.000',
            'total' => '125.000',
            'balance_due' => '125.000',
        ]);

        return ExpenseMetadata::create([
            'document_id' => $document->id,
            'payment_instrument_id' => $instrumentId,
            'is_paid' => $isPaid,
            'paid_at' => $isPaid ? '2026-07-18 00:00:00' : null,
            'payment_repository_id' => $isPaid ? $repositoryId : null,
            'payment_method_id' => $isPaid ? $methodId : null,
            'payment_date' => $isPaid ? '2026-07-18' : null,
        ]);
    }
}
