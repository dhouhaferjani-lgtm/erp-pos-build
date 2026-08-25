<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Modules\Treasury\Application\Services\TenderRepositoryResolver;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Campaign lane N-12 — a branch's POS cash must land in the BRANCH's drawer.
 *
 * Reproduced in the Playwright first-tenant campaign (wave 1 §N-12, wave 4 and
 * both re-runs): the `Boutique Ariana` terminal's 200.000 TND cash sale landed
 * in `CASH-01`, the MAIN location's drawer, because
 * {@see TenderRepositoryResolver} scoped only on tenant+company and then broke
 * ties on the stable UUID — so every branch in the company resolved to whichever
 * cash register was seeded first. Two branches' takings commingled in one
 * balance and a per-branch cash count could not reconcile against anything.
 *
 * The rule this file pins — location is a TIER, not a tie-breaker:
 *
 *   tier 1  `location_id = <the receipt's terminal location>`
 *   tier 2  `location_id IS NULL`  — the legacy, never-attributed drawer, and
 *           ONLY when tier 1 is empty (pre-N-12 tenants keep working unchanged)
 *   never   another location's drawer
 *
 * A POS-enabled location with no drawer of its own therefore REFUSES rather
 * than silently borrowing Main's: loudly at the projection (the money has
 * nowhere honest to go), and — before any money exists — with a typed 422 at
 * terminal claim.
 *
 * Rule 20: the bridge runs on a Horizon worker with NO `CompanyContext` bound,
 * so `setUp()` clears the context it needed for chart seeding.
 */
final class BranchCashRepositoryRoutingTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $mainLocationId;

    private string $branchLocationId;

    private string $branchTerminalId;

    private string $operatorId;

    private string $cashAccountId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
        $this->companyId = $company->id;
        app(CompanyContext::class)->setCompanyId($this->companyId);

        // Created FIRST, so under `HasUuids` time-ordered uuid7 the Main drawer
        // also sorts first by id — i.e. the pre-fix `orderBy('id')` fallback
        // picks Main. Without that ordering the red test could pass by luck.
        $this->mainLocationId = Location::factory()->create([
            'company_id' => $this->companyId,
            'name' => 'Main Location',
            'type' => 'shop',
            'pos_enabled' => true,
            'is_default' => true,
        ])->id;

        $this->branchLocationId = Location::factory()->create([
            'company_id' => $this->companyId,
            'name' => 'Boutique Ariana',
            'type' => 'shop',
            'pos_enabled' => true,
        ])->id;

        $this->branchTerminalId = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->branchLocationId,
            'genesis_seed' => str_repeat('0', 64),
        ])->id;

        $this->user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Branch Cashier']);
        $this->operatorId = $this->user->id;
        UserCompanyMembership::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->companyId,
            'role' => MembershipRole::Cashier,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
        ]);

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_cash_tender' => true,
            'has_maturity' => false,
            'instrument_kind' => null,
        ]);

        $this->app->make(ChartOfAccountsService::class)->seedForCompany($company);
        $this->cashAccountId = Account::query()
            ->where('company_id', $this->companyId)
            ->where('code', '53')
            ->firstOrFail()
            ->id;

        app(CompanyContext::class)->clear();
    }

    // =================================================================
    // The bug: a branch sale in the Main drawer
    // =================================================================

    public function test_branch_cash_sale_lands_in_the_branch_drawer_not_main(): void
    {
        $main = $this->drawer('CASH-01', $this->mainLocationId);
        $branch = $this->drawer('CASH-02', $this->branchLocationId);

        $event = $this->storeCashReceiptFiscalEvent('200.000');
        $this->seedPosReceiptRowFor($event);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(
            $branch->id,
            $payment->repository_id,
            "Boutique Ariana's cash must land in Boutique Ariana's drawer, never in Main's.",
        );

        $this->assertSame(0, bccomp($this->numeric($branch->fresh()?->balance), '200.000', 3));
        $this->assertSame(0, bccomp($this->numeric($main->fresh()?->balance), '0.000', 3), 'Main must be untouched.');

        $this->assertSame(1, DB::table('repository_movements')
            ->where('repository_id', $branch->id)
            ->where('source_id', $event->id)
            ->count());
        $this->assertSame(0, DB::table('repository_movements')->where('repository_id', $main->id)->count());
    }

    public function test_branch_cash_refund_debits_the_branch_drawer_not_main(): void
    {
        $main = $this->drawer('CASH-01', $this->mainLocationId);
        $branch = $this->drawer('CASH-02', $this->branchLocationId);

        $sale = $this->storeCashReceiptFiscalEvent('200.000');
        $this->seedPosReceiptRowFor($sale);
        $bridge = $this->app->make(TreasuryReceiptBridge::class);
        $bridge->apply($sale);

        $refund = $this->storeCashReceiptFiscalEvent(
            '42.800',
            sequenceNumber: 2,
            invoiceTypeCode: 'REFUND',
            originalReceiptReference: [
                'fiscal_event_id' => $sale->id,
                'original_business_date' => $sale->business_date->toDateString(),
                'original_receipt_uuid' => '00000000-0000-4000-8000-000000000001',
                'refund_reason' => 'Retour client',
            ],
        );
        $this->seedPosReceiptRowFor($refund);
        $bridge->apply($refund);

        $refundPayment = Payment::query()->where('fiscal_event_id', $refund->id)->firstOrFail();
        $this->assertSame($branch->id, $refundPayment->repository_id);

        $this->assertSame(0, bccomp($this->numeric($branch->fresh()?->balance), '157.200', 3));
        $this->assertSame(0, bccomp($this->numeric($main->fresh()?->balance), '0.000', 3), 'Main must be untouched.');
    }

    public function test_a_company_wide_mapped_repository_cannot_override_the_branch_drawer(): void
    {
        $main = $this->drawer('CASH-01', $this->mainLocationId);
        $branch = $this->drawer('CASH-02', $this->branchLocationId);

        // Operator policy maps the CASH method at company level to Main's till.
        // Location attribution is not a tie-breaker it can outrank.
        PaymentMethod::query()
            ->where('company_id', $this->companyId)
            ->where('code', 'CASH')
            ->update(['default_repository_id' => $main->id]);

        $event = $this->storeCashReceiptFiscalEvent('200.000');
        $this->seedPosReceiptRowFor($event);
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame($branch->id, $payment->repository_id);
        $this->assertSame(0, bccomp($this->numeric($main->fresh()?->balance), '0.000', 3));
    }

    public function test_a_location_without_a_drawer_refuses_instead_of_borrowing_main(): void
    {
        $main = $this->drawer('CASH-01', $this->mainLocationId);

        $event = $this->storeCashReceiptFiscalEvent('200.000');
        $this->seedPosReceiptRowFor($event);

        try {
            $this->app->make(TreasuryReceiptBridge::class)->apply($event);
            $this->fail('The bridge must refuse a branch receipt with no drawer at its location.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($this->branchLocationId, $e->getMessage());
        }

        $this->assertSame(0, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $this->assertSame(0, DB::table('repository_movements')->where('repository_id', $main->id)->count());
        $this->assertSame(0, bccomp($this->numeric($main->fresh()?->balance), '0.000', 3));
    }

    public function test_a_legacy_unattributed_drawer_still_serves_every_terminal(): void
    {
        // Pre-N-12 tenants: both seeded repositories carry `location_id = NULL`.
        // Tier 2 keeps them working EXACTLY as before — this fix is forward-only.
        $legacy = $this->drawer('CASH-01', null);

        $event = $this->storeCashReceiptFiscalEvent('200.000');
        $this->seedPosReceiptRowFor($event);
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame($legacy->id, $payment->repository_id);
        $this->assertSame(0, bccomp($this->numeric($legacy->fresh()?->balance), '200.000', 3));
    }

    // =================================================================
    // The resolver itself
    // =================================================================

    public function test_resolver_never_returns_another_locations_drawer(): void
    {
        $main = $this->drawer('CASH-01', $this->mainLocationId);
        $resolver = $this->app->make(TenderRepositoryResolver::class);

        $this->assertNull(
            $resolver->resolve($this->tenantId, $this->companyId, null, $this->branchLocationId),
            "Main's drawer is not a candidate for a receipt authored at the branch.",
        );
        $this->assertSame(
            $main->id,
            $resolver->resolve($this->tenantId, $this->companyId, null, $this->mainLocationId)?->id,
        );
        // A caller with no location (server-authored flows) keeps the historical
        // company-wide rule verbatim.
        $this->assertSame(
            $main->id,
            $resolver->resolve($this->tenantId, $this->companyId, null, null)?->id,
        );
    }

    public function test_resolver_prefers_the_locations_cash_register_over_its_safe(): void
    {
        $safe = $this->drawer('SAFE-02', $this->branchLocationId, RepositoryType::Safe);
        $till = $this->drawer('CASH-02', $this->branchLocationId);
        $this->assertNotSame($safe->id, $till->id);

        $this->assertSame(
            $till->id,
            $this->app->make(TenderRepositoryResolver::class)
                ->resolve($this->tenantId, $this->companyId, null, $this->branchLocationId)?->id,
        );
    }

    // =================================================================
    // Refuse before the money exists — terminal claim
    // =================================================================

    public function test_terminal_claim_refuses_when_the_location_has_no_cash_register(): void
    {
        $this->drawer('CASH-01', $this->mainLocationId);
        $this->actAsOperator();

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->branchLocationId,
            'type' => TerminalType::Physical,
            'is_active' => true,
            'hardware_identifier' => null,
        ]);

        $response = $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $terminal->id,
            'hardware_identifier' => 'HW-ARIANA-1',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'LOCATION_HAS_NO_CASH_REGISTER');
        $this->assertNull($terminal->fresh()?->hardware_identifier);
    }

    public function test_terminal_claim_succeeds_once_the_location_has_its_own_drawer(): void
    {
        $this->drawer('CASH-01', $this->mainLocationId);
        $this->drawer('CASH-02', $this->branchLocationId);
        $this->actAsOperator();

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->branchLocationId,
            'type' => TerminalType::Physical,
            'is_active' => true,
            'hardware_identifier' => null,
        ]);

        $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $terminal->id,
            'hardware_identifier' => 'HW-ARIANA-2',
        ])->assertStatus(200);
    }

    public function test_terminal_claim_still_succeeds_for_a_legacy_unattributed_drawer(): void
    {
        $this->drawer('CASH-01', null);
        $this->actAsOperator();

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->branchLocationId,
            'type' => TerminalType::Physical,
            'is_active' => true,
            'hardware_identifier' => null,
        ]);

        $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $terminal->id,
            'hardware_identifier' => 'HW-LEGACY-1',
        ])->assertStatus(200);
    }

    // =================================================================
    // Provisioning: a POS-enabled location is born with a drawer
    // =================================================================

    public function test_creating_a_pos_enabled_location_provisions_its_cash_register(): void
    {
        $this->actAsAdmin();

        $response = $this->postJson('/api/v1/locations', [
            'name' => 'Boutique Lac 2',
            'code' => 'LAC2',
            'type' => 'shop',
            'pos_enabled' => true,
        ]);

        $response->assertStatus(201);
        $locationId = $response->json('data.id');
        $this->assertIsString($locationId);

        $repository = PaymentRepository::query()
            ->where('company_id', $this->companyId)
            ->where('location_id', $locationId)
            ->where('type', RepositoryType::CashRegister)
            ->first();

        $this->assertNotNull($repository, 'A POS-enabled location must be born with its own drawer.');
        $this->assertSame($this->cashAccountId, $repository->gl_account_id);
        $this->assertTrue($repository->is_active);
    }

    public function test_a_non_pos_location_gets_no_drawer(): void
    {
        $this->actAsAdmin();

        $response = $this->postJson('/api/v1/locations', [
            'name' => 'Dépôt central',
            'code' => 'DEP1',
            'type' => 'warehouse',
            'pos_enabled' => false,
        ]);

        $response->assertStatus(201);

        $this->assertSame(0, PaymentRepository::query()
            ->where('company_id', $this->companyId)
            ->where('location_id', $response->json('data.id'))
            ->count());
    }

    public function test_enabling_pos_on_an_existing_location_provisions_its_drawer_once(): void
    {
        $this->actAsAdmin();

        $location = Location::factory()->create([
            'company_id' => $this->companyId,
            'name' => 'Boutique Menzah',
            'type' => 'shop',
            'pos_enabled' => false,
        ]);

        $this->patchJson("/api/v1/locations/{$location->id}", ['pos_enabled' => true])->assertStatus(200);
        $this->patchJson("/api/v1/locations/{$location->id}", ['pos_enabled' => true])->assertStatus(200);

        $this->assertSame(1, PaymentRepository::query()
            ->where('company_id', $this->companyId)
            ->where('location_id', $location->id)
            ->where('type', RepositoryType::CashRegister)
            ->count(), 'Provisioning must be idempotent.');
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function drawer(
        string $code,
        ?string $locationId,
        RepositoryType $type = RepositoryType::CashRegister,
    ): PaymentRepository {
        return PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => $code,
            'type' => $type,
            'location_id' => $locationId,
            'gl_account_id' => $this->cashAccountId,
            'currency' => 'TND',
            'balance' => '0.000',
        ]);
    }

    private function actAsOperator(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantId);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');
        Sanctum::actingAs($this->user);
    }

    private function actAsAdmin(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantId);
        foreach (['inventory.view', 'inventory.adjust'] as $permission) {
            Permission::findOrCreate($permission, 'sanctum');
            $this->user->givePermissionTo($permission);
        }
        Sanctum::actingAs($this->user);
    }

    /**
     * @return numeric-string
     */
    private function numeric(mixed $value): string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            $this->fail(sprintf('Expected a numeric value, got %s.', var_export($value, true)));
        }

        return (string) $value;
    }

    private function seedPosReceiptRowFor(FiscalEvent $event): Receipt
    {
        return Receipt::factory()
            ->withTotal('200.000', '0.000')
            ->create([
                'tenant_id' => $event->tenant_id,
                'company_id' => $event->company_id,
                'location_id' => $this->branchLocationId,
                'terminal_id' => $event->terminal_id,
                'cashier_id' => $event->operator_id,
                'currency' => 'TND',
                'fiscal_event_id' => $event->id,
                'fiscal_hash' => $event->current_hash,
                'previous_hash' => $event->previous_hash,
                'chain_sequence' => $event->sequence_number,
            ]);
    }

    private function storeCashReceiptFiscalEvent(
        string $total,
        int $sequenceNumber = 1,
        FiscalEventType $eventType = FiscalEventType::SALE_RECEIPT,
        string $invoiceTypeCode = 'SALE',
        /** @var array<string, string>|null */
        ?array $originalReceiptReference = null,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Branch Cashier',
            'consumption_mode' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => '2026-08-25T10:30:00.000Z',
            'invoice_type_code' => $invoiceTypeCode,
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => $total,
                'line_vat' => '0.000',
                'name' => 'Article',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.0000',
                'sku' => 'X',
                'tax_category_code' => 'Z',
                'unit_price' => $total,
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => $originalReceiptReference,
            'payments' => [[
                'amount' => $total,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => Str::uuid()->toString(),
            'seller' => [
                'address' => ['city' => 'Ariana', 'country_code' => 'TN', 'postal_code' => '2080', 'street' => '1 rue de Tunis'],
                'name' => 'Parapharmacie Nour',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AAM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $total,
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => $total,
            'training_flag' => false,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => $total,
                'net_amount' => $total,
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.000',
            ]],
            'vat_total' => '0.000',
            'vouchers_redeemed' => [],
        ];

        $canonicalArray = [
            'business_date' => $businessDate->toDateString(),
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'event_type' => $eventType->value,
            'event_version' => 2,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => $sequenceNumber,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->branchTerminalId,
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->branchTerminalId,
            'operator_id' => $this->operatorId,
            'event_type' => $eventType,
            'event_version' => 2,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'server_received_at' => $eventTime,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $previousHash,
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $json = json_encode($this->sortRecursive($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed');
        }

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
    }
}
