<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 20 — `POST /api/v1/pos/sync/fiscal-events` (spec v7 §7.1, plan §1484).
 *
 * The single device→server fiscal-event ingestion endpoint. Wire envelope
 * per spec §7.1: outer
 * `{ envelope_id, type: 'FISCAL_EVENT', payload_version, idempotency_key, payload }`
 * where `payload` carries the inner spec §4 envelope plus the
 * server-readable transport fields (`canonical_bytes`, `current_hash`).
 *
 * Middleware tuple matches every existing POS surface verbatim
 * (`['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`)
 * — this endpoint writes fiscal chain truth, so a weaker boundary than the
 * rest of POS would allow envelopes under the wrong tenant context to
 * corrupt the chain.
 *
 * Two layers of tenant boundary:
 *  - `EnforceTokenTenantClaim` (middleware) — token's `tenant:` ability vs
 *    live `User::tenant_id`.
 *  - The controller — every envelope's `tenant_id` vs the authenticated
 *    user's `tenant_id`. A single cross-tenant envelope in the batch is a
 *    security event, not a partial-batch outcome → 403 + zero rows.
 *
 * Per-envelope shape validation runs through
 * `FiscalEventEnvelope::fromArray()` at the controller boundary (round-2
 * T19-B4 — regex-validate hashes / UUIDs / timestamps at the typed
 * boundary). A malformed field anywhere in the batch → 422 + zero rows
 * persisted (those envelopes cannot be safely quarantined either; the
 * device retries once the offending field is fixed).
 */
final class FiscalEventIngestionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $user;

    private string $operatorId;

    private string $genesisSeed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Genesis seed matches the OutboxIngestor's first-event linkage
        // contract (T19-B3) — previous_hash on a first event MUST equal the
        // terminal's `pos_terminals.genesis_seed`.
        $this->genesisSeed = str_repeat('0', 64);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'genesis_seed' => $this->genesisSeed,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // CompanyContextMiddleware (in the `api` middleware group) returns
        // 403 NO_COMPANY_ACCESS unless the authenticated user is a member
        // of at least one company before hitting fiscal-event ingestion.
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // operator_id has no FK constraint from fiscal_events — a raw UUID
        // matches the OutboxIngestorTest convention.
        $this->operatorId = Str::uuid()->toString();

        // The OutboxIngestor wires projection jobs via DB::afterCommit. Task
        // 23's job class doesn't exist yet; Queue::fake() lets us assert no
        // job is pushed even when a row commits.
        Queue::fake();
    }

    /**
     * Plan §1494 Step 1 case — a valid envelope lands in the ledger and the
     * endpoint returns a 200 with per-envelope `stored=true`.
     */
    public function test_endpoint_ingests_a_valid_fiscal_event_envelope(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', [
            'envelopes' => [$this->validEnvelopeWire()],
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'results' => [
                ['stored', 'fiscal_event_id', 'sequence_conflict', 'exception_class'],
            ],
        ]);
        $response->assertJsonPath('results.0.stored', true);
        $response->assertJsonPath('results.0.sequence_conflict', false);
        $response->assertJsonPath('results.0.exception_class', null);

        $this->assertSame(1, DB::table('fiscal_events')->count());
    }

    /**
     * Plan §1508 Step 1 case — unauthenticated requests must be rejected by
     * `auth:sanctum` before the controller is reached.
     */
    public function test_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => []])
            ->assertStatus(401);
    }

    /**
     * Plan §1513 Step 1 case — a token belonging to tenant A cannot ingest
     * an envelope claiming tenant B. The controller-layer envelope-vs-user
     * tenant check is the active gate here (the middleware only checks
     * token-vs-user, not envelope-vs-user).
     */
    public function test_endpoint_rejects_envelope_with_mismatched_tenant(): void
    {
        Sanctum::actingAs($this->user); // user.tenant_id == $this->tenant->id (tenantA)
        $tenantB = Tenant::factory()->create();

        $envWire = $this->validEnvelopeWire(['tenant_id' => $tenantB->id]);

        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', [
            'envelopes' => [$envWire],
        ]);

        $response->assertStatus(403);
        $this->assertSame(
            0,
            DB::table('fiscal_events')->count(),
            'cross-tenant envelope must not enter the ledger',
        );
        $this->assertSame(
            0,
            DB::table('fiscal_event_quarantine')->count(),
            'cross-tenant envelope must not enter the quarantine table either — controller aborts pre-ingest',
        );
    }

    /**
     * Plan §1524 Step 1 case — a second envelope claiming the same
     * `(tenant, terminal, sequence_number)` slot but with a different id
     * routes to `fiscal_event_quarantine` and the per-envelope response
     * carries `sequence_conflict=true`.
     */
    public function test_endpoint_returns_per_envelope_sequence_conflict(): void
    {
        Sanctum::actingAs($this->user);

        $first = $this->validEnvelopeWire(['sequence_number' => 1]);
        $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$first]])
            ->assertOk()
            ->assertJsonPath('results.0.stored', true);

        // Second call: same slot, different id → §7.2 Step 4 sequence_conflict.
        $conflict = $this->validEnvelopeWire(['sequence_number' => 1]);
        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', [
            'envelopes' => [$conflict],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.0.sequence_conflict', true);
        $response->assertJsonPath('results.0.stored', false);
        $response->assertJsonPath('results.0.fiscal_event_id', null);
        $response->assertJsonPath('results.0.exception_class', 'sequence_conflict');

        $this->assertSame(
            1,
            DB::table('fiscal_events')->count(),
            'conflicting envelope physically cannot enter fiscal_events',
        );
        $this->assertSame(
            1,
            DB::table('fiscal_event_quarantine')->count(),
            'conflicting envelope is preserved verbatim in fiscal_event_quarantine',
        );
    }

    /**
     * Round-2 T19-B4 boundary parity at the HTTP layer — a malformed UUID
     * at the inner `id` field must surface as 422 + zero rows persisted.
     * The controller invokes `FiscalEventEnvelope::fromArray()` which
     * regex-validates each free-form field BEFORE the ingestor sees the
     * envelope (the quarantine table's `envelope_event_id` is uuid-typed,
     * so even the quarantine row could not be persisted for this case).
     */
    public function test_endpoint_returns_422_on_malformed_envelope_field(): void
    {
        Sanctum::actingAs($this->user);

        $envWire = $this->validEnvelopeWire();
        $envWire['payload']['id'] = 'not-a-uuid-at-all';

        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', [
            'envelopes' => [$envWire],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('fiscal_events')->count());
        $this->assertSame(0, DB::table('fiscal_event_quarantine')->count());
    }

    /**
     * Mixed-batch tenant-boundary check — even when only ONE envelope in a
     * batch claims a foreign tenant, the entire batch is rejected with 403
     * and zero rows persist. Cross-tenant intent is treated as a security
     * event, not partial-batch noise.
     */
    public function test_mixed_batch_with_one_foreign_tenant_envelope_rejects_entire_batch(): void
    {
        Sanctum::actingAs($this->user);
        $tenantB = Tenant::factory()->create();

        $valid = $this->validEnvelopeWire(['sequence_number' => 1]);
        $foreign = $this->validEnvelopeWire([
            'sequence_number' => 2,
            'tenant_id' => $tenantB->id,
        ]);

        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', [
            'envelopes' => [$valid, $foreign],
        ]);

        $response->assertStatus(403);
        $this->assertSame(
            0,
            DB::table('fiscal_events')->count(),
            'no envelope from a batch containing a cross-tenant envelope enters the ledger',
        );
    }

    /**
     * Wire-shape validation — missing outer `envelopes` array → 422
     * from `IngestFiscalEventsRequest` before the controller body runs.
     */
    public function test_endpoint_rejects_empty_request_body(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/pos/sync/fiscal-events', [])
            ->assertStatus(422);
    }

    /**
     * Round-2 P2 (dual-review convergent) — pin `IngestionResult::idempotent`
     * at the HTTP boundary. Spec v7 §7.2 Step 4: an exact-byte re-delivery
     * must return the existing event (no re-dispatch). The controller maps
     * that to `stored=false, fiscal_event_id=<existing>, sequence_conflict=false,
     * exception_class=null`.
     */
    public function test_endpoint_returns_idempotent_redelivery_for_byte_identical_repost(): void
    {
        Sanctum::actingAs($this->user);

        $envelope = $this->validEnvelopeWire(['sequence_number' => 1]);

        $first = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$envelope]])
            ->assertOk()
            ->assertJsonPath('results.0.stored', true);
        $firstId = $first->json('results.0.fiscal_event_id');
        $this->assertIsString($firstId);

        // Identical envelope (same id, hash, canonical_bytes, source_event_*).
        $second = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$envelope]]);

        $second->assertOk();
        $second->assertJsonPath('results.0.stored', false);
        $second->assertJsonPath('results.0.fiscal_event_id', $firstId);
        $second->assertJsonPath('results.0.sequence_conflict', false);
        $second->assertJsonPath('results.0.exception_class', null);

        // The ledger still carries exactly one row; no quarantine row was added.
        $this->assertSame(1, DB::table('fiscal_events')->count());
        $this->assertSame(0, DB::table('fiscal_event_quarantine')->count());
    }

    /**
     * Round-2 P2 (dual-review convergent) — pin `IngestionResult::quarantined`
     * (the in-table quarantine path) at the HTTP boundary. Spec v7 §7.2:
     * `canonical_hash_mismatch` is admitted to `fiscal_events` with
     * `integrity_status='quarantined'` and `integrity_exception_class='canonical_hash_mismatch'`;
     * the row IS persisted (the device is never blocked) and the per-envelope
     * response carries `stored=true, exception_class='canonical_hash_mismatch'`.
     */
    public function test_endpoint_returns_quarantined_in_table_for_canonical_hash_mismatch(): void
    {
        Sanctum::actingAs($this->user);

        $envelope = $this->validEnvelopeWire(['sequence_number' => 1]);
        // Replace current_hash with a syntactically valid but wrong 64-hex
        // value. assertWireShape() still passes (hex format ok); the hash
        // verification inside the ingestor fails → in-table quarantine.
        $envelope['payload']['current_hash'] = str_repeat('f', 64);

        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', [
            'envelopes' => [$envelope],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.0.stored', true);
        $response->assertJsonPath('results.0.sequence_conflict', false);
        $response->assertJsonPath('results.0.exception_class', 'canonical_hash_mismatch');
        $fiscalEventId = $response->json('results.0.fiscal_event_id');
        $this->assertIsString($fiscalEventId);

        $row = DB::table('fiscal_events')->where('id', $fiscalEventId)->first();
        $this->assertNotNull($row);
        $this->assertSame('quarantined', $row->integrity_status);
        $this->assertSame('canonical_hash_mismatch', $row->integrity_exception_class);

        $this->assertSame(0, DB::table('fiscal_event_quarantine')->count(), 'hash-mismatch is in-table quarantine — fiscal_event_quarantine is reserved for non-admissible classes');
    }

    /**
     * Round-2 P3-F3 — pin the precedence between the two pre-flight stages.
     * An envelope that is BOTH malformed (UUID at `id`) AND cross-tenant
     * must return 422 (Stage 1 — malformed) rather than 403 (Stage 2 —
     * tenant). The controller runs `FiscalEventEnvelope::fromArray()` for
     * ALL envelopes before ANY tenant check, so a malformed field aborts
     * the entire batch before tenant evaluation begins.
     */
    public function test_envelope_with_both_malformed_field_and_cross_tenant_returns_422_not_403(): void
    {
        Sanctum::actingAs($this->user);
        $tenantB = Tenant::factory()->create();

        $envWire = $this->validEnvelopeWire([
            'tenant_id' => $tenantB->id,
            // Same envelope is also malformed at `id` — both pre-flight checks
            // would otherwise reject it.
        ]);
        $envWire['payload']['id'] = 'not-a-uuid-at-all';

        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', [
            'envelopes' => [$envWire],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'MALFORMED_ENVELOPE');
        $this->assertSame(0, DB::table('fiscal_events')->count());
        $this->assertSame(0, DB::table('fiscal_event_quarantine')->count());
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Build the outer wire envelope shape per spec §7.1 carrying a valid
     * SALE_RECEIPT inner payload. `$payloadOverrides` keys map to the inner
     * `payload` envelope fields (e.g. `sequence_number`, `tenant_id`,
     * `previous_hash`) — used by individual tests to provoke specific
     * §7.2 outcomes.
     *
     * @param  array<string, mixed>  $payloadOverrides
     * @return array<string, mixed>
     */
    private function validEnvelopeWire(array $payloadOverrides = []): array
    {
        $eventTimeDevice = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $businessDate = now()->utc()->toDateString();

        $base = [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => $eventTimeDevice,
            'business_date' => $businessDate,
            'last_server_time_seen' => null,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'previous_hash' => $this->genesisSeed,
        ];

        foreach ($payloadOverrides as $k => $v) {
            $base[$k] = $v;
        }

        // Canonical envelope per spec §4 — alphabetical keys at every depth,
        // no whitespace, ASCII-safe encoding. Same shape the OutboxIngestor
        // test uses; the StrictCanonicalParser (Task 16) accepts this.
        $canonicalArray = [
            'business_date' => $base['business_date'],
            'company_id' => $base['company_id'],
            'event_time_device' => $base['event_time_device'],
            'event_type' => $base['event_type'],
            'event_version' => $base['event_version'],
            'operator_id' => $base['operator_id'],
            'payload' => $this->minimalSaleReceiptPayload(),
            'previous_hash' => $base['previous_hash'],
            'reference_document_id' => $base['reference_document_id'],
            'reference_event_id' => $base['reference_event_id'],
            'sequence_number' => $base['sequence_number'],
            'signature_version' => $base['signature_version'],
            'tenant_id' => $base['tenant_id'],
            'terminal_id' => $base['terminal_id'],
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);

        $base['canonical_bytes'] = $canonicalBytes;
        $base['current_hash'] = hash('sha256', $canonicalBytes);

        return [
            'envelope_id' => Str::uuid()->toString(),
            'type' => 'FISCAL_EVENT',
            'payload_version' => 1,
            'idempotency_key' => $base['terminal_id'].':'.$base['sequence_number'],
            'payload' => $base,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Pass 2A.PHP.2 — 27-key Candidate C-v3 SALE_RECEIPT payload per
     * synthesis v5 §3. Hand-balanced totals: subtotal 10.00 + vat_total
     * 0.00 = total 10.00 + transaction_discount_amount 0.00.
     *
     * @return array<string, mixed>
     */
    private function minimalSaleReceiptPayload(): array
    {
        return [
            'business_date' => '2026-05-20',
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => '10.00',
                'line_vat' => '0.00',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'X',
                'tax_category_code' => 'Z',
                'unit_price' => '10.00',
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '10.00',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '10.00',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '10.00',
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '10.00',
                'net_amount' => '10.00',
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.00',
            ]],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $sorted = $this->sortRecursive($value);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed in test fixture');
        }

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v): mixed => $this->sortRecursive($v), $value);
        }
        ksort($value);

        return array_map(fn ($v): mixed => $this->sortRecursive($v), $value);
    }
}
