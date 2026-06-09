<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Services\VirtualAdminFiscalEventService;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 2 — `VirtualAdminFiscalEventService::appendDepositReceipt()`.
 *
 * The server-authored back-office counterpart of the device-authored
 * `ACCOUNT_PAYMENT` flow. Authors a `DEPOSIT_RECEIPT` fiscal event through the
 * virtual-admin terminal + hash chain, exactly like the shipped
 * `appendAccountStatusChanged()` path, but carries the lean 16-key
 * DEPOSIT_RECEIPT canonical payload.
 */
final class DepositReceiptAppendTest extends TestCase
{
    use RefreshDatabase;

    public function test_append_deposit_receipt_creates_server_authored_virtual_admin_event(): void
    {
        [$tenant, $company, $actor, $partner] = $this->fixtureWithLocation();

        $event = app(VirtualAdminFiscalEventService::class)->appendDepositReceipt(
            partner: $partner,
            actorUserId: $actor->id,
            actorName: $actor->name,
            currencyCode: 'EUR',
            amount: '125.50',
            methodCode: 'cash',
            repositoryId: null,
            notes: 'On-the-road payment',
        );

        $terminal = Terminal::query()
            ->where('tenant_id', $tenant->id)
            ->where('company_id', $company->id)
            ->where('type', TerminalType::VirtualAdmin)
            ->sole();

        $this->assertSame(FiscalEventType::DEPOSIT_RECEIPT, $event->event_type);
        $this->assertSame($terminal->id, $event->terminal_id);
        $this->assertSame($actor->id, $event->operator_id);
        $this->assertSame(1, $event->sequence_number);
        $this->assertSame($terminal->genesis_seed, $event->previous_hash);
        $this->assertSame(SignatureStatus::NotRequired, $event->signature_status);
        $this->assertSame(IntegrityStatus::Verified, $event->integrity_status);
        $this->assertSame(PayloadParseStatus::Parsed, $event->payload_parse_status);
        $this->assertSame($partner->id, $event->partner_id);

        $payload = $event->payload;
        $this->assertIsArray($payload);

        // 16-key lean server contract, lexicographically sorted.
        $this->assertSame([
            'actor_name',
            'actor_user_id',
            'business_date',
            'company_id',
            'currency_code',
            'currency_scale',
            'customer',
            'deposit_receipt_uuid',
            'event_time_device',
            'notes',
            'partner_id',
            'payment',
            'tenant_id',
            'terminal_id',
            'training_flag',
            'treasury_allocation_policy',
        ], array_keys($payload));

        $this->assertSame($actor->name, $payload['actor_name']);
        $this->assertSame($actor->id, $payload['actor_user_id']);
        $this->assertSame($event->business_date->toDateString(), $payload['business_date']);
        $this->assertSame($company->id, $payload['company_id']);
        $this->assertSame('EUR', $payload['currency_code']);
        $this->assertSame(2, $payload['currency_scale']);
        $this->assertSame(
            $event->event_time_device->utc()->format('Y-m-d\TH:i:s.v\Z'),
            $payload['event_time_device'],
        );
        $this->assertSame('On-the-road payment', $payload['notes']);
        $this->assertSame($partner->id, $payload['partner_id']);
        $this->assertSame($tenant->id, $payload['tenant_id']);
        $this->assertSame($terminal->id, $payload['terminal_id']);
        $this->assertFalse($payload['training_flag']);
        $this->assertSame('FIFO', $payload['treasury_allocation_policy']);

        // deposit_receipt_uuid is server-generated, lowercase-hex UUID.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $payload['deposit_receipt_uuid'],
        );

        // customer snapshot — exact key set + partner identity invariant.
        $this->assertSame([
            'customer_category' => CustomerCategory::Business->value,
            'customer_id' => $partner->id,
            'email' => $partner->email,
            'name' => $partner->name,
            'phone' => $partner->phone,
        ], $payload['customer']);
        $this->assertSame($payload['partner_id'], $payload['customer']['customer_id']);

        // payment object — money carried as numeric-string at currency_scale.
        $this->assertSame([
            'amount' => '125.50',
            'method_code' => 'cash',
            'repository_id' => null,
        ], $payload['payment']);
    }

    public function test_append_deposit_receipt_chains_sequence_on_the_virtual_admin_terminal(): void
    {
        [, , $actor, $partner] = $this->fixtureWithLocation();
        $service = app(VirtualAdminFiscalEventService::class);

        $first = $service->appendDepositReceipt(
            partner: $partner,
            actorUserId: $actor->id,
            actorName: $actor->name,
            currencyCode: 'EUR',
            amount: '10.00',
            methodCode: 'cash',
            repositoryId: null,
            notes: null,
        );
        $second = $service->appendDepositReceipt(
            partner: $partner,
            actorUserId: $actor->id,
            actorName: $actor->name,
            currencyCode: 'EUR',
            amount: '20.00',
            methodCode: 'cash',
            repositoryId: null,
            notes: null,
        );

        $this->assertSame(1, $first->sequence_number);
        $this->assertSame(2, $second->sequence_number);
        $this->assertSame($first->current_hash, $second->previous_hash);
    }

    public function test_append_deposit_receipt_formats_amount_at_currency_scale(): void
    {
        [, , $actor, $partner] = $this->fixtureWithLocation();

        $event = app(VirtualAdminFiscalEventService::class)->appendDepositReceipt(
            partner: $partner,
            actorUserId: $actor->id,
            actorName: $actor->name,
            currencyCode: 'TND',
            amount: '12.5',
            methodCode: 'cash',
            repositoryId: null,
            notes: null,
        );

        $payload = $event->payload;
        $this->assertIsArray($payload);
        $this->assertSame(3, $payload['currency_scale']);
        $this->assertSame('12.500', $payload['payment']['amount']);
    }

    public function test_append_deposit_receipt_rejects_non_positive_amount(): void
    {
        [, , $actor, $partner] = $this->fixtureWithLocation();

        $this->expectException(RuntimeException::class);

        app(VirtualAdminFiscalEventService::class)->appendDepositReceipt(
            partner: $partner,
            actorUserId: $actor->id,
            actorName: $actor->name,
            currencyCode: 'EUR',
            amount: '0',
            methodCode: 'cash',
            repositoryId: null,
            notes: null,
        );

        $this->assertSame(0, FiscalEvent::query()->where('event_type', FiscalEventType::DEPOSIT_RECEIPT)->count());
    }

    public function test_append_deposit_receipt_rejects_amount_more_precise_than_currency_scale(): void
    {
        [, , $actor, $partner] = $this->fixtureWithLocation();

        // EUR is scale 2; '10.009' carries a non-zero millicent. Silently
        // truncating to '10.00' would seal LESS money than supplied into the
        // fiscal chain — reject instead.
        $this->expectException(RuntimeException::class);

        try {
            app(VirtualAdminFiscalEventService::class)->appendDepositReceipt(
                partner: $partner,
                actorUserId: $actor->id,
                actorName: $actor->name,
                currencyCode: 'EUR',
                amount: '10.009',
                methodCode: 'cash',
                repositoryId: null,
                notes: null,
            );
        } finally {
            $this->assertSame(
                0,
                FiscalEvent::query()->where('event_type', FiscalEventType::DEPOSIT_RECEIPT)->count(),
            );
        }
    }

    public function test_append_deposit_receipt_rejects_amount_with_excess_precision_for_millime_currency(): void
    {
        [, , $actor, $partner] = $this->fixtureWithLocation();

        // TND is scale 3; '12.9999' exceeds it.
        $this->expectException(RuntimeException::class);

        app(VirtualAdminFiscalEventService::class)->appendDepositReceipt(
            partner: $partner,
            actorUserId: $actor->id,
            actorName: $actor->name,
            currencyCode: 'TND',
            amount: '12.9999',
            methodCode: 'cash',
            repositoryId: null,
            notes: null,
        );
    }

    public function test_append_deposit_receipt_rejects_tiny_excess_precision_beyond_scale(): void
    {
        [, , $actor, $partner] = $this->fixtureWithLocation();

        // A non-zero digit far beyond the scale must NOT be silently truncated.
        $this->expectException(RuntimeException::class);

        try {
            app(VirtualAdminFiscalEventService::class)->appendDepositReceipt(
                partner: $partner,
                actorUserId: $actor->id,
                actorName: $actor->name,
                currencyCode: 'EUR',
                amount: '10.000000001',
                methodCode: 'cash',
                repositoryId: null,
                notes: null,
            );
        } finally {
            $this->assertSame(
                0,
                FiscalEvent::query()->where('event_type', FiscalEventType::DEPOSIT_RECEIPT)->count(),
            );
        }
    }

    public function test_append_deposit_receipt_accepts_trailing_zero_padding_beyond_scale(): void
    {
        [, , $actor, $partner] = $this->fixtureWithLocation();

        // Trailing zeros past the scale are harmless padding — keep the value.
        $event = app(VirtualAdminFiscalEventService::class)->appendDepositReceipt(
            partner: $partner,
            actorUserId: $actor->id,
            actorName: $actor->name,
            currencyCode: 'EUR',
            amount: '10.5000',
            methodCode: 'cash',
            repositoryId: null,
            notes: null,
        );

        $payload = $event->payload;
        $this->assertIsArray($payload);
        $this->assertSame('10.50', $payload['payment']['amount']);
    }

    public function test_append_deposit_receipt_rejects_a_non_numeric_amount(): void
    {
        [, , $actor, $partner] = $this->fixtureWithLocation();

        $this->expectException(RuntimeException::class);

        app(VirtualAdminFiscalEventService::class)->appendDepositReceipt(
            partner: $partner,
            actorUserId: $actor->id,
            actorName: $actor->name,
            currencyCode: 'EUR',
            amount: '10,00',
            methodCode: 'cash',
            repositoryId: null,
            notes: null,
        );
    }

    /**
     * @return array{0: Tenant, 1: Company, 2: User, 3: Partner}
     */
    private function fixtureWithLocation(): array
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
