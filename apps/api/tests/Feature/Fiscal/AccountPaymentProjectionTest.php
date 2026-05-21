<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\AccountPaymentReceiptProjection;
use App\Modules\POS\Domain\AccountPaymentReceipt;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class AccountPaymentProjectionTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Default Cashier']);
        $this->operatorId = $user->id;
    }

    public function test_account_payment_projects_printable_receipt_from_payload(): void
    {
        $event = $this->storeAccountPaymentFiscalEvent();

        $this->app->make(AccountPaymentReceiptProjection::class)->apply($event);

        $receipt = AccountPaymentReceipt::query()->first();
        $this->assertNotNull($receipt);
        $this->assertSame($this->tenantId, $receipt->tenant_id);
        $this->assertSame($this->companyId, $receipt->company_id);
        $this->assertSame($event->id, $receipt->fiscal_event_id);
        $this->assertSame('44444444-4444-4444-8444-444444444444', $receipt->account_payment_uuid);
        $this->assertSame('55555555-5555-4555-8555-555555555555', $receipt->customer_id);
        $this->assertSame('Mariam Ben Ali', $receipt->customer_name);
        $this->assertSame('100.000', $receipt->amount);
        $this->assertSame('TND', $receipt->currency_code);
        $this->assertSame($event->payload, $receipt->payload_snapshot);
    }

    public function test_projection_is_idempotent_by_fiscal_event_id(): void
    {
        $event = $this->storeAccountPaymentFiscalEvent();
        $projector = $this->app->make(AccountPaymentReceiptProjection::class);

        $projector->apply($event);
        $projector->apply($event);

        $this->assertSame(1, AccountPaymentReceipt::query()->count());
    }

    public function test_projection_fails_loud_on_missing_customer_snapshot(): void
    {
        $payload = $this->accountPaymentPayload();
        unset($payload['customer']);
        $event = $this->storeAccountPaymentFiscalEvent($payload);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('customer');

        $this->app->make(AccountPaymentReceiptProjection::class)->apply($event);
    }

    public function test_pos_only_projection_does_not_require_treasury(): void
    {
        $event = $this->storeAccountPaymentFiscalEvent();
        $projector = $this->app->make(AccountPaymentReceiptProjection::class);

        $this->assertSame('pos_core_account_payment_receipt', $projector->name());
        $this->assertTrue($projector->handlesEventType(FiscalEventType::ACCOUNT_PAYMENT));
        $this->assertFalse($projector->handlesEventType(FiscalEventType::SALE_RECEIPT));
        $this->assertNull($projector->requiresModule());
        $this->assertSame(50, $projector->priority());

        $projector->apply($event);

        $this->assertSame(1, AccountPaymentReceipt::query()->count());
        $this->assertSame(0, DB::table('payments')->count());
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function storeAccountPaymentFiscalEvent(?array $payload = null): FiscalEvent
    {
        $payload ??= $this->accountPaymentPayload();
        $eventId = Str::uuid()->toString();

        return FiscalEvent::query()->create([
            'id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::ACCOUNT_PAYMENT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 7,
            'event_time_device' => '2026-05-21 10:15:30',
            'business_date' => '2026-05-21',
            'last_server_time_seen' => null,
            'server_received_at' => '2026-05-21 10:15:31',
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'account_payments',
            'source_event_id' => $payload['account_payment_uuid'] ?? null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $this->canonicalEncode($payload),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function accountPaymentPayload(): array
    {
        return [
            'account_payment_uuid' => '44444444-4444-4444-8444-444444444444',
            'business_date' => '2026-05-21',
            'cashier_id' => $this->operatorId,
            'cashier_name' => 'Default Cashier',
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'address' => null,
                'customer_category' => 'retail',
                'customer_id' => '55555555-5555-4555-8555-555555555555',
                'customer_sync_status' => 'synced',
                'email' => null,
                'name' => 'Mariam Ben Ali',
                'phone' => '+21611111111',
                'tax_number' => null,
            ],
            'event_time_device' => '2026-05-21T10:15:30.000Z',
            'local_balance_snapshot' => [
                'balance_updated_at' => '2026-05-21T10:10:00.000Z',
                'credit_balance_before' => '0.000',
                'net_balance_before' => '300.000',
                'payment_amount' => '100.000',
                'projected_credit_balance_after' => '0.000',
                'projected_net_balance_after' => '200.000',
                'projected_receivable_balance_after' => '200.000',
                'receivable_balance_before' => '300.000',
            ],
            'notes' => null,
            'payment' => [
                'amount' => '100.000',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
                'repository_id' => null,
            ],
            'receipt_type_code' => 'ACCOUNT_PAYMENT',
            'references' => null,
            'regime_extensions' => null,
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
                'name' => 'Default Seller',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'staleness' => [
                'balance_snapshot_stale' => false,
                'customer_snapshot_stale' => false,
                'mirror_last_synced_at' => '2026-05-21T10:10:00.000Z',
                'staleness_reason' => null,
            ],
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'training_flag' => false,
            'treasury_allocation_policy' => 'FIFO',
        ];
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        return json_encode($this->sortRecursive($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortRecursive(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->sortRecursive($item) : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        return $value;
    }
}
