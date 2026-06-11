<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Projections\DepositReceiptProjection;
use App\Modules\POS\Application\Services\VirtualAdminFiscalEventService;
use App\Modules\POS\Domain\DepositReceipt;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 — `DepositReceiptProjection` (POS-core, priority 50).
 *
 * The always-active printable projection for server-authored DEPOSIT_RECEIPT
 * events: it reads only the verified canonical payload snapshot on
 * `fiscal_events` and writes one idempotent `pos_deposit_receipts` row. Treasury
 * payment creation + FIFO allocation are the later gated bridge (Phase 4).
 */
final class DepositReceiptProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_projection_writes_one_deposit_receipt_row_from_the_canonical_payload(): void
    {
        [$tenant, $company, $actor, $partner] = $this->fixture();
        $event = $this->authorDeposit($partner, $actor, '125.50', 'EUR');

        app(DepositReceiptProjection::class)->apply($event);

        $receipt = DepositReceipt::query()->where('fiscal_event_id', $event->id)->sole();

        $this->assertSame($tenant->id, $receipt->tenant_id);
        $this->assertSame($company->id, $receipt->company_id);
        $this->assertSame($event->payload['deposit_receipt_uuid'], $receipt->deposit_receipt_uuid);
        $this->assertSame($partner->id, $receipt->customer_id);
        $this->assertSame($partner->name, $receipt->customer_name);
        $this->assertSame('125.50', $receipt->amount);
        $this->assertSame('EUR', $receipt->currency_code);
        $this->assertSame($event->payload, $receipt->payload_snapshot);
    }

    public function test_projection_is_idempotent_on_repeated_apply(): void
    {
        [, , $actor, $partner] = $this->fixture();
        $event = $this->authorDeposit($partner, $actor, '40.00', 'EUR');
        $projection = app(DepositReceiptProjection::class);

        $projection->apply($event);
        $projection->apply($event);

        $this->assertSame(
            1,
            DB::table('pos_deposit_receipts')->where('fiscal_event_id', $event->id)->count(),
        );
    }

    public function test_projector_is_registered_and_active_for_deposit_receipt_events(): void
    {
        [, , $actor, $partner] = $this->fixture();
        $event = $this->authorDeposit($partner, $actor, '10.00', 'EUR');

        $names = array_map(
            static fn (object $p): string => $p->name(),
            app(FiscalEventProjectionRegistry::class)->activeProjectorsFor($event),
        );

        $this->assertContains('pos_core_deposit_receipt', $names);
    }

    public function test_projection_fails_loud_on_a_non_deposit_receipt_event(): void
    {
        [$tenant, $company] = $this->fixture();
        $event = $this->rawFiscalEvent($tenant->id, $company->id, [
            'event_type' => FiscalEventType::ACCOUNT_STATUS_CHANGED,
            'payload' => ['stub' => true],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        try {
            app(DepositReceiptProjection::class)->apply($event);
        } finally {
            $this->assertSame(0, DB::table('pos_deposit_receipts')->count());
        }
    }

    public function test_projection_fails_loud_on_a_null_payload(): void
    {
        [$tenant, $company] = $this->fixture();
        $event = $this->rawFiscalEvent($tenant->id, $company->id, [
            'event_type' => FiscalEventType::DEPOSIT_RECEIPT,
            'payload' => null,
            'payload_parse_status' => PayloadParseStatus::Failed,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        try {
            app(DepositReceiptProjection::class)->apply($event);
        } finally {
            $this->assertSame(0, DB::table('pos_deposit_receipts')->count());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function rawFiscalEvent(string $tenantId, string $companyId, array $overrides): FiscalEvent
    {
        return FiscalEvent::query()->create(array_merge([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'operator_id' => Str::uuid()->toString(),
            'event_type' => FiscalEventType::DEPOSIT_RECEIPT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => '2026-06-09 10:15:30',
            'business_date' => '2026-06-09',
            'last_server_time_seen' => null,
            'server_received_at' => '2026-06-09 10:15:31',
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => '{}',
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => ['stub' => true],
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ], $overrides))->refresh();
    }

    private function authorDeposit(Partner $partner, User $actor, string $amount, string $currency): FiscalEvent
    {
        return app(VirtualAdminFiscalEventService::class)->appendDepositReceipt(
            partner: $partner,
            actorUserId: $actor->id,
            actorName: $actor->name,
            currencyCode: $currency,
            amount: $amount,
            methodCode: 'cash',
            repositoryId: null,
            notes: null,
        );
    }

    /**
     * @return array{0: Tenant, 1: Company, 2: User, 3: Partner}
     */
    private function fixture(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        Location::factory()->create(['company_id' => $company->id]);
        $actor = User::factory()->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'customer_category' => CustomerCategory::Business,
            'account_status' => CustomerAccountStatus::Active,
            'account_status_version' => 1,
        ]);

        return [$tenant, $company, $actor, $partner];
    }
}
