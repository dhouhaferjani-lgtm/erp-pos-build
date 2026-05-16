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
        // of at least one company. Mirrors OfflineV3CutoverSyncTest's
        // setup pattern (apps/api/tests/Feature/POS/OfflineV3CutoverSyncTest.php:559).
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
    private function minimalSaleReceiptPayload(): array
    {
        return [
            'currency' => 'EUR',
            'currency_scale' => 2,
            'discount_total' => '0.00',
            'lines' => [
                ['sku' => 'X', 'unit_price' => '10.00', 'line_total' => '10.00'],
            ],
            'payment_lines' => [
                ['method' => 'CASH', 'amount' => '10.00', 'tendered' => '10.00', 'change' => '0.00'],
            ],
            'subtotal' => '10.00',
            'tax_total' => '0.00',
            'total' => '10.00',
            'vat_breakdown' => [
                ['rate' => '0', 'base' => '10.00', 'amount' => '0.00'],
            ],
            'voucher_redemptions' => [],
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
