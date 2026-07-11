<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Application\Services\InstrumentRemittanceService;
use App\Modules\Treasury\Domain\Enums\DishonorRouting;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceType;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\InstrumentRemittance;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InstrumentRemittanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_effet_slip_posts_one_total_je_and_deposits_all_instruments(): void
    {
        $context = $this->context();
        app(ChartOfAccountsService::class)->seedForCompany($context['company']);
        $first = $this->instrument($context, InstrumentKind::Effet, '100.000');
        $second = $this->instrument($context, InstrumentKind::Effet, '50.000');
        $slip = $this->service()->createDraft(
            $context['company']->id,
            $context['tenant']->id,
            $context['bank']->id,
            RemittanceType::Collection,
            InstrumentKind::Effet,
            $context['user']->id,
        );
        $this->service()->addLine($slip->id, $second->id);
        $this->service()->addLine($slip->id, $first->id);

        $remitted = $this->service()->remit($slip->id, $context['user']->id);

        $this->assertSame(RemittanceStatus::Remitted, $remitted->status);
        $this->assertNotNull($remitted->journal_entry_id);
        $entry = JournalEntry::query()->with('lines.account')->findOrFail($remitted->journal_entry_id);
        $this->assertSame('instrument_remittance', $entry->source_type);
        $this->assertSame('EF', $entry->journal_code?->value);
        $this->assertSame('150.000', $entry->lines->firstWhere('account.code', '5313')?->debit);
        $this->assertSame('150.000', $entry->lines->firstWhere('account.code', '413')?->credit);
        $this->assertSame(2, PaymentInstrument::query()->where('status', InstrumentStatus::Deposited)->count());
        $this->assertSame(2, InstrumentEvent::query()->where('event_type', InstrumentEventType::Remitted)->count());
        $this->assertDatabaseCount('repository_movements', 0);
    }

    public function test_cheque_slip_moves_status_without_je_and_rejects_mixed_kind(): void
    {
        $context = $this->context();
        $cheque = $this->instrument($context, InstrumentKind::Cheque, '80.000');
        $effet = $this->instrument($context, InstrumentKind::Effet, '20.000');
        $slip = $this->draft($context, InstrumentKind::Cheque);
        $this->service()->addLine($slip->id, $cheque->id);

        try {
            $this->service()->addLine($slip->id, $effet->id);
            $this->fail('mixed-kind line should be rejected');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->service()->remit($slip->id, $context['user']->id);

        $this->assertSame(InstrumentStatus::Deposited, $cheque->fresh()?->status);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->expectException(DomainException::class);
        $this->service()->addLine($slip->id, $effet->id);
    }

    public function test_non_bank_repository_and_remitted_line_mutations_are_rejected(): void
    {
        $context = $this->context();

        $this->expectException(DomainException::class);
        $this->service()->createDraft(
            $context['company']->id,
            $context['tenant']->id,
            $context['safe']->id,
            RemittanceType::Collection,
            InstrumentKind::Cheque,
            $context['user']->id,
        );
    }

    public function test_bounced_cheque_re_presentation_posts_portfolio_redebit(): void
    {
        $context = $this->context();
        app(ChartOfAccountsService::class)->seedForCompany($context['company']);
        $instrument = $this->instrument($context, InstrumentKind::Cheque, '75.000', InstrumentStatus::Bounced);
        $instrument->update(['dishonor_routing' => DishonorRouting::RePresent]);
        $slip = $this->draft($context, InstrumentKind::Cheque);
        $this->service()->addLine($slip->id, $instrument->id);

        $this->service()->remit($slip->id, $context['user']->id);

        $entry = JournalEntry::query()->where('source_type', 'instrument')->where('source_id', $instrument->id)
            ->with('lines.account')->firstOrFail();
        $this->assertSame('75.000', $entry->lines->firstWhere('account.code', '5312')?->debit);
        $this->assertSame('75.000', $entry->lines->firstWhere('account.code', '411')?->credit);
        $this->assertDatabaseHas('instrument_events', [
            'instrument_id' => $instrument->id,
            'event_type' => InstrumentEventType::RePresented->value,
        ]);
    }

    public function test_bounced_effet_re_presentation_uses_one_normal_slip_entry(): void
    {
        $context = $this->context();
        app(ChartOfAccountsService::class)->seedForCompany($context['company']);
        $instrument = $this->instrument($context, InstrumentKind::Effet, '65.000', InstrumentStatus::Bounced);
        $instrument->update(['dishonor_routing' => DishonorRouting::RePresent]);
        $slip = $this->draft($context, InstrumentKind::Effet);
        $this->service()->addLine($slip->id, $instrument->id);

        $this->service()->remit($slip->id, $context['user']->id);

        $entries = JournalEntry::query()->where('source_type', 'instrument_remittance')->with('lines.account')->get();
        $this->assertCount(1, $entries);
        $this->assertSame('65.000', $entries->first()?->lines->firstWhere('account.code', '5313')?->debit);
        $this->assertSame('65.000', $entries->first()?->lines->firstWhere('account.code', '413')?->credit);
        $this->assertDatabaseHas('instrument_events', [
            'instrument_id' => $instrument->id,
            'event_type' => InstrumentEventType::RePresented->value,
        ]);
    }

    public function test_remove_line_from_draft_clears_current_remittance_link(): void
    {
        $context = $this->context();
        $instrument = $this->instrument($context, InstrumentKind::Cheque, '30.000');
        $slip = $this->draft($context, InstrumentKind::Cheque);
        $line = $this->service()->addLine($slip->id, $instrument->id);

        $this->service()->removeLine($slip->id, $line->id);

        $this->assertNull($instrument->fresh()?->remittance_id);
        $this->assertDatabaseMissing('instrument_remittance_lines', ['id' => $line->id]);
    }

    public function test_missing_gl_account_rolls_back_remit_mutations(): void
    {
        $context = $this->context();
        $instrument = $this->instrument($context, InstrumentKind::Effet, '90.000');
        $slip = $this->draft($context, InstrumentKind::Effet);
        $this->service()->addLine($slip->id, $instrument->id);

        try {
            $this->service()->remit($slip->id, $context['user']->id);
            $this->fail('missing portfolio accounts must fail closed');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->assertSame(RemittanceStatus::Draft, $slip->fresh()?->status);
        $this->assertSame(InstrumentStatus::Received, $instrument->fresh()?->status);
        $this->assertSame(0, InstrumentEvent::query()->where('event_type', InstrumentEventType::Remitted)->count());
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_single_instrument_deposit_wrapper_creates_and_remits_slip(): void
    {
        $context = $this->context();
        $instrument = $this->instrument($context, InstrumentKind::Cheque, '45.000');

        $this->lifecycle()->deposit($instrument->id, $context['bank']->id, $context['user']->id);

        $this->assertSame(InstrumentStatus::Deposited, $instrument->fresh()?->status);
        $this->assertNotNull($instrument->fresh()?->remittance_id);
        $this->assertDatabaseCount('instrument_remittances', 1);
    }

    private function service(): InstrumentRemittanceService
    {
        return app(InstrumentRemittanceService::class);
    }

    private function lifecycle(): InstrumentLifecycleService
    {
        return app(InstrumentLifecycleService::class);
    }

    /** @param array{tenant: Tenant, company: Company, user: User, bank: PaymentRepository, safe: PaymentRepository} $context */
    private function draft(array $context, InstrumentKind $kind): InstrumentRemittance
    {
        return $this->service()->createDraft(
            $context['company']->id,
            $context['tenant']->id,
            $context['bank']->id,
            RemittanceType::Collection,
            $kind,
            $context['user']->id,
        );
    }

    /** @param array{tenant: Tenant, company: Company, user: User, bank: PaymentRepository, safe: PaymentRepository} $context */
    private function instrument(
        array $context,
        InstrumentKind $kind,
        string $amount,
        InstrumentStatus $status = InstrumentStatus::Received,
    ): PaymentInstrument {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $context['tenant']->id,
            'company_id' => $context['company']->id,
            'code' => strtoupper($kind->value).'-'.Str::upper(Str::random(8)),
            'has_maturity' => true,
            'instrument_kind' => $kind,
        ]);

        return PaymentInstrument::query()->create([
            'tenant_id' => $context['tenant']->id,
            'company_id' => $context['company']->id,
            'payment_method_id' => $method->id,
            'reference' => 'REF-'.Str::upper(Str::random(8)),
            'amount' => $amount,
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => $status,
            'kind' => $kind,
            'direction' => InstrumentDirection::Inbound,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $context['safe']->id,
        ]);
    }

    /** @return array{tenant: Tenant, company: Company, user: User, bank: PaymentRepository, safe: PaymentRepository} */
    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $bank = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'bank_account',
            'code' => 'BANK-'.Str::upper(Str::random(6)),
        ]);
        $safe = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'safe',
            'code' => 'SAFE-'.Str::upper(Str::random(6)),
        ]);

        return compact('tenant', 'company', 'user', 'bank', 'safe');
    }
}
