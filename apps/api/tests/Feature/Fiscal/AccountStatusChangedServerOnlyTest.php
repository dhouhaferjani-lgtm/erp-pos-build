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
use App\Modules\Partner\Application\Services\CustomerAccountStatusService;
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\ByteaBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AccountStatusChangedServerOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_status_change_appends_server_authored_virtual_admin_event(): void
    {
        [$tenant, $company, $actor, $partner] = $this->fixtureWithLocation();

        $changed = app(CustomerAccountStatusService::class)->transition(
            tenantId: $tenant->id,
            companyId: $company->id,
            partnerId: $partner->id,
            newStatus: CustomerAccountStatus::Suspended,
            actorUserId: $actor->id,
            reason: 'Credit control hold',
        );

        $this->assertSame(CustomerAccountStatus::Suspended, $changed->account_status);
        $this->assertSame(2, $changed->account_status_version);
        $this->assertSame($actor->id, $changed->account_status_changed_by);
        $this->assertSame('Credit control hold', $changed->account_status_reason);

        $terminal = Terminal::query()
            ->where('tenant_id', $tenant->id)
            ->where('company_id', $company->id)
            ->where('type', TerminalType::VirtualAdmin)
            ->sole();

        $event = FiscalEvent::query()
            ->where('event_type', FiscalEventType::ACCOUNT_STATUS_CHANGED)
            ->where('partner_id', $partner->id)
            ->sole();

        $this->assertSame($terminal->id, $event->terminal_id);
        $this->assertSame($actor->id, $event->operator_id);
        $this->assertSame(1, $event->sequence_number);
        $this->assertSame($terminal->genesis_seed, $event->previous_hash);
        $this->assertSame(SignatureStatus::NotRequired, $event->signature_status);
        $this->assertSame(IntegrityStatus::Verified, $event->integrity_status);
        $this->assertSame(PayloadParseStatus::Parsed, $event->payload_parse_status);

        $this->assertSame([
            'actor_user_id' => $actor->id,
            'company_id' => $company->id,
            'event_time_device' => $event->event_time_device->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'new_status' => 'suspended',
            'old_status' => 'active',
            'partner_id' => $partner->id,
            'partner_snapshot' => [
                'id' => $partner->id,
                'name' => $partner->name,
                'type' => 'customer',
            ],
            'reason' => 'Credit control hold',
            'status_version' => 2,
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'training_flag' => false,
        ], $event->payload);
    }

    /**
     * The partner NAME is carried verbatim into `payload.partner_snapshot.name`
     * and therefore into the canonical bytes. RFC 8785 canonical JSON escapes a
     * double quote as `\"`, so a partner called `Café "Le Bon" \ SARL` produces
     * canonical bytes containing `\"` and `\\` — the exact byte sequences
     * PostgreSQL's bytea *escape* input parser rejects when the value is bound
     * as `PDO::PARAM_STR`. Before the binding fix this threw
     * `SQLSTATE[22P02] invalid input syntax for type bytea`.
     */
    public function test_partner_name_with_quote_and_backslash_is_appended_byte_identically(): void
    {
        [$tenant, $company, $actor, $partner] = $this->fixtureWithLocation();

        $partner->forceFill(['name' => 'Café "Le Bon" \\ SARL — réf C:\\CLIENTS\\42'])->save();

        app(CustomerAccountStatusService::class)->transition(
            tenantId: $tenant->id,
            companyId: $company->id,
            partnerId: $partner->id,
            newStatus: CustomerAccountStatus::Suspended,
            actorUserId: $actor->id,
            reason: 'Credit control hold',
        );

        $event = FiscalEvent::query()
            ->where('event_type', FiscalEventType::ACCOUNT_STATUS_CHANGED)
            ->where('partner_id', $partner->id)
            ->sole();

        $bytes = $event->canonical_bytes;
        $this->assertStringContainsString('\\"', $bytes);
        $this->assertStringContainsString('\\\\', $bytes);
        $this->assertStringContainsString('é', $bytes);

        // The persisted bytes must match what was hashed — read the raw column
        // (PG hands a bytea back as a stream) and re-derive the chain hash.
        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $stored = ByteaBinding::read($row->canonical_bytes);
        $this->assertSame($bytes, $stored);
        $this->assertSame($event->current_hash, hash('sha256', $stored));
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
            'account_status' => CustomerAccountStatus::Active,
            'account_status_version' => 1,
        ]);

        return [$tenant, $company, $actor, $partner];
    }
}
