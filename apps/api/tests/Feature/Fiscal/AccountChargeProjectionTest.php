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
use App\Modules\POS\Application\Projections\AccountChargeReceiptProjection;
use App\Modules\POS\Domain\AccountChargeReceipt;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountChargeProjectionTest extends TestCase
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

    public function test_account_charge_projects_printable_receipt_from_canonical_payload(): void
    {
        $event = $this->storeAccountChargeFiscalEvent();

        $this->app->make(AccountChargeReceiptProjection::class)->apply($event);

        $receipt = AccountChargeReceipt::query()->first();
        $this->assertNotNull($receipt);
        $this->assertSame($this->tenantId, $receipt->tenant_id);
        $this->assertSame($this->companyId, $receipt->company_id);
        $this->assertSame($event->id, $receipt->fiscal_event_id);
        $this->assertSame('66666666-6666-4666-8666-666666666666', $receipt->account_charge_uuid);
        $this->assertSame('55555555-5555-4555-8555-555555555555', $receipt->customer_id);
        $this->assertSame('Mariam Ben Ali', $receipt->customer_name);
        $this->assertSame('119.0000', $receipt->amount_charged);
        $this->assertSame('TND', $receipt->currency_code);
        $this->assertSame($event->payload, $receipt->payload_snapshot);
    }

    public function test_account_charge_projection_is_idempotent_by_fiscal_event_id(): void
    {
        $event = $this->storeAccountChargeFiscalEvent();
        $projector = $this->app->make(AccountChargeReceiptProjection::class);

        $projector->apply($event);
        $projector->apply($event);

        $this->assertSame(1, AccountChargeReceipt::query()->count());
    }

    public function test_account_charge_pos_core_projection_runs_without_treasury_effects(): void
    {
        $event = $this->storeAccountChargeFiscalEvent();
        $projector = $this->app->make(AccountChargeReceiptProjection::class);

        $this->assertSame('pos_core_account_charge_receipt', $projector->name());
        $this->assertTrue($projector->handlesEventType(FiscalEventType::ACCOUNT_CHARGE));
        $this->assertFalse($projector->handlesEventType(FiscalEventType::SALE_RECEIPT));
        $this->assertFalse($projector->handlesEventType(FiscalEventType::ACCOUNT_PAYMENT));
        $this->assertNull($projector->requiresModule());
        $this->assertSame(50, $projector->priority());

        $projector->apply($event);

        $this->assertSame(1, AccountChargeReceipt::query()->count());
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_account_charge_projection_is_registered_as_fiscal_event_projector_tag(): void
    {
        /** @var list<FiscalEventProjector> $tagged */
        $tagged = iterator_to_array(
            $this->app->tagged(FiscalEventProjector::class),
            false,
        );
        $names = array_map(
            static fn (FiscalEventProjector $projector): string => $projector->name(),
            $tagged,
        );

        $this->assertContains('pos_core_account_charge_receipt', $names);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function storeAccountChargeFiscalEvent(?array $payload = null): FiscalEvent
    {
        $payload ??= $this->accountChargePayload();
        $eventId = Str::uuid()->toString();

        return FiscalEvent::query()->create([
            'id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::ACCOUNT_CHARGE,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 9,
            'event_time_device' => '2026-05-21 10:15:30',
            'business_date' => '2026-05-21',
            'last_server_time_seen' => null,
            'server_received_at' => '2026-05-21 10:15:31',
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'account_charge',
            'source_event_id' => $payload['account_charge_uuid'] ?? null,
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
    private function accountChargePayload(): array
    {
        return [
            'account_charge_uuid' => '66666666-6666-4666-8666-666666666666',
            'business_date' => '2026-05-21',
            'buyer' => null,
            'cashier_id' => $this->operatorId,
            'cashier_name' => 'Default Cashier',
            'charge_terms' => [
                'due_date' => '2026-06-20',
                'payment_terms_days' => 30,
                'terms_label' => 'Net 30',
            ],
            'credit_decision' => [
                'credit_available_after' => '81.000',
                'credit_available_before' => '200.000',
                'credit_limit' => '500.000',
                'decision' => 'approved',
                'limit_exceeded' => false,
                'mirror_stale_at_authoring' => false,
                'policy_version' => 'phase3-default-v1',
                'stale_policy_action' => 'allow',
                'warnings' => [],
            ],
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'account_identifier' => 'CUST-0001',
                'address' => null,
                'customer_category' => 'individual',
                'customer_id' => '55555555-5555-4555-8555-555555555555',
                'customer_sync_status' => 'synced',
                'email' => null,
                'name' => 'Mariam Ben Ali',
                'phone' => '+21611111111',
                'tax_number' => null,
            ],
            'event_time_device' => '2026-05-21T10:15:30.000Z',
            'invoice_classification' => 'b2c_charge_receipt',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => '100.000',
                'line_uuid' => '77777777-7777-4777-8777-777777777777',
                'line_vat' => '19.000',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'SKU-DEFAULT',
                'tax_category_code' => '',
                'unit_price' => '100.000',
                'vat_rate' => '19.00',
            ]],
            'local_balance_snapshot' => [
                'balance_updated_at' => '2026-05-21T10:10:00.000Z',
                'charge_amount' => '119.000',
                'credit_balance_before' => '0.000',
                'net_balance_before' => '300.000',
                'projected_credit_balance_after' => '0.000',
                'projected_net_balance_after' => '419.000',
                'projected_receivable_balance_after' => '419.000',
                'receivable_balance_before' => '300.000',
            ],
            'notes' => null,
            'print_profile' => 'ACCOUNT_CHARGE_RECEIPT',
            'receipt_type_code' => 'ACCOUNT_CHARGE',
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
            'totals' => [
                'amount_charged_to_account' => '119.000',
                'grand_total_before_charge' => '119.000',
                'subtotal' => '100.000',
                'total' => '119.000',
                'vat_total' => '19.000',
            ],
            'training_flag' => false,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '119.000',
                'net_amount' => '100.000',
                'rate' => '19.00',
                'tax_category_code' => '',
                'vat_amount' => '19.000',
            ]],
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
