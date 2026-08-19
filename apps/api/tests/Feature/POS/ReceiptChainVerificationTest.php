<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ReceiptChainVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $user;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.view_reports', 'sanctum');
        $this->user->givePermissionTo('pos.view_reports');

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_verify_receipt_chain_returns_valid_for_empty_chain(): void
    {
        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_valid', true)
            ->assertJsonPath('data.chain_length', 0)
            ->assertJsonPath('data.first_receipt', null)
            ->assertJsonPath('data.last_receipt', null)
            ->assertJsonPath('data.broken_at_sequence', null)
            ->assertJsonStructure([
                'data' => [
                    'is_valid',
                    'chain_length',
                    'first_receipt',
                    'last_receipt',
                    'broken_at_sequence',
                    'verified_at',
                ],
            ]);
    }

    public function test_verify_receipt_chain_returns_chain_stats(): void
    {
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'chain_sequence' => 1,
            'receipt_number' => 'REC-001',
            'previous_hash' => null,
            'fiscal_hash' => hash('sha256', 'test'),
            'is_voided' => false,
            'cashier_id' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.chain_length', 1)
            ->assertJsonPath('data.first_receipt', 'REC-001')
            ->assertJsonPath('data.last_receipt', 'REC-001');

        $this->assertNotNull($response->json('data.verified_at'));
    }

    public function test_verify_receipt_chain_requires_authorization(): void
    {
        $userWithoutPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $userWithoutPermission->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        Sanctum::actingAs($userWithoutPermission);

        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_verify_receipt_chain_enforces_company_ownership(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        $otherTerminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $otherTerminal->id,
        ]);

        // Post api.pos-stabilization.018 fix: the FormRequest-tier
        // ScopedExists::tenantAndCompany on pos_terminals refuses cross-
        // company terminal_ids before the controller's manual company_id
        // check runs. 422 (validator) is now the correct contract; 403
        // (manual check) was the pre-fix behavior. Assert the new contract
        // using the project's `error.errors.<field>` envelope shape.
        $response->assertUnprocessable();
        $errors = $response->json('error.errors');
        $this->assertIsArray($errors, 'expected error.errors envelope, got: '.$response->getContent());
        $this->assertArrayHasKey('terminal_id', $errors);
    }

    public function test_verify_receipt_chain_requires_valid_terminal_id(): void
    {
        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => 'not-a-uuid',
        ]);

        $response->assertUnprocessable();
    }

    public function test_verify_receipt_chain_detects_broken_chain(): void
    {
        // The first receipt has a fabricated fiscal_hash that won't match
        // what ReceiptHashService::calculateHash() produces, so the chain
        // is detected as broken at sequence 1.
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'chain_sequence' => 1,
            'receipt_number' => 'REC-001',
            'previous_hash' => null,
            'fiscal_hash' => hash('sha256', 'fabricated-hash'),
            'is_voided' => false,
            'cashier_id' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_valid', false)
            ->assertJsonPath('data.chain_length', 1)
            ->assertJsonPath('data.broken_at_sequence', 1);
    }

    /**
     * M2-round1 finding 4. `ReceiptHashService::verifyTerminalChain()` gained a
     * projected-mirror arm (`pos_receipts.fiscal_hash` <-> the referenced
     * `fiscal_events.current_hash`), which WIDENS this live endpoint's
     * `is_valid`: a projection whose mirror hash diverges from its source
     * event now reports an invalid chain even though every canonical-bytes
     * rehash and every chain link is intact. That is a deliberate behaviour
     * change on a live endpoint and it is pinned here.
     */
    public function test_verify_receipt_chain_reports_invalid_when_the_projection_mirror_diverges(): void
    {
        $canonicalBytes = '{"event":"mirror_divergence","sequence_number":1}';
        $eventId = $this->insertFiscalEvent($canonicalBytes);

        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'chain_sequence' => 1,
            'receipt_number' => 'REC-MIRROR-001',
            'previous_hash' => $this->terminal->genesis_seed,
            // Diverges from the referenced event's current_hash — nothing else
            // about the chain is tampered.
            'fiscal_hash' => str_repeat('d', 64),
            'fiscal_event_id' => $eventId,
            'is_voided' => false,
            'is_training' => false,
            'cashier_id' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_valid', false)
            ->assertJsonPath('data.chain_length', 1);
    }

    /**
     * M2-round2 finding 10, swept in M3 — the `broken_at_sequence` coordinate
     * this endpoint returns is UNATTRIBUTABLE for any fiscal-era terminal,
     * and M2's mirror arm is now the route that reaches it. Pinned, not
     * fixed: the fix belongs to whichever lane owns the endpoint, and R-1 /
     * "do not refactor the controller" bound this sweep to recording the
     * truth with a failing-if-it-changes test.
     *
     * **Why it is unattributable.** `ReportController.php:466-490` recomputes
     * the LEGACY pipe hash (`ReceiptHashService::calculateHash()`) over ALL
     * receipts — including `fiscal_event_id`-linked ones, whose hash shape is
     * canonical bytes, not the pipe format — and it threads `$previousHash`
     * from `null` (`:473`). Every fiscal-era chain's first projected receipt
     * carries the terminal `genesis_seed` as its `previous_hash`, which is
     * never `null`, so the very first iteration takes the `:475` branch and
     * returns the LOWEST `chain_sequence` — before it ever inspects the row
     * that actually diverged.
     *
     * This test proves that concretely: receipt #1 mirrors its event
     * correctly and receipt #2 does not, so the honest coordinate is 2. The
     * endpoint answers 1. When someone fixes the controller this assertion
     * goes red, which is the point.
     *
     * NOT a regression from M2: before the mirror arm existed, the
     * context-flattening defect made `is_valid` false on every clean
     * two-context terminal, so this same wrong coordinate was returned far
     * more often.
     */
    public function test_broken_at_sequence_is_unattributable_on_a_fiscal_era_terminal(): void
    {
        $firstBytes = '{"event":"mirror_ok","sequence_number":1}';
        $firstEventId = $this->insertFiscalEvent($firstBytes);
        $secondBytes = '{"event":"mirror_divergence","sequence_number":2}';
        $secondEventId = $this->insertFiscalEvent(
            canonicalBytes: $secondBytes,
            sequenceNumber: 2,
            previousHash: hash('sha256', $firstBytes),
        );

        // Receipt #1 — mirrors its event exactly. Nothing is wrong here.
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'chain_sequence' => 1,
            'receipt_number' => 'REC-ATTRIB-001',
            'previous_hash' => $this->terminal->genesis_seed,
            'fiscal_hash' => hash('sha256', $firstBytes),
            'fiscal_event_id' => $firstEventId,
            'is_voided' => false,
            'is_training' => false,
            'cashier_id' => $this->user->id,
        ]);

        // Receipt #2 — the ONLY divergence on this terminal.
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'chain_sequence' => 2,
            'receipt_number' => 'REC-ATTRIB-002',
            'previous_hash' => hash('sha256', $firstBytes),
            'fiscal_hash' => str_repeat('d', 64),
            'fiscal_event_id' => $secondEventId,
            'is_voided' => false,
            'is_training' => false,
            'cashier_id' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_valid', false)
            ->assertJsonPath('data.chain_length', 2)
            // The honest answer is 2. The endpoint says 1, for the reason in
            // this test's docblock. Pinning the WRONG value on purpose.
            ->assertJsonPath('data.broken_at_sequence', 1);
    }

    /**
     * Insert one admissible `fiscal_events` row, anchored by default at the
     * terminal genesis seed, so the authoritative arm verifies clean and the
     * ONLY possible failure is the projection mirror.
     */
    private function insertFiscalEvent(
        string $canonicalBytes,
        int $sequenceNumber = 1,
        ?string $previousHash = null,
    ): string {
        $now = Carbon::now('UTC');
        $eventId = Str::uuid()->toString();

        DB::table('fiscal_events')->insert([
            'id' => $eventId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->user->id,
            'chain_context' => 'operational',
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $now,
            'business_date' => $now->copy()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => $now,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $previousHash ?? $this->terminal->genesis_seed,
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired->value,
            'integrity_status' => IntegrityStatus::Verified->value,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => null,
            'payload_parse_status' => PayloadParseStatus::Pending->value,
            'created_at' => $now,
        ]);

        return $eventId;
    }
}
