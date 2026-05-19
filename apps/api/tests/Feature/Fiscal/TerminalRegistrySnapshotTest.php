<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Application\Services\DefaultModuleActivationResolver;
use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Application\Services\TerminalRegistrySnapshotService;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Exceptions\FiscalEventTypeNotImplemented;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 26 — TERMINAL_REGISTRY_SNAPSHOT (implemented) + COMPANY_DAY_CLOSURE_MANIFEST
 * (reserved) + FiscalServiceProvider cumulative wiring verification.
 *
 * Spec v7 §11: company-level integrity record types.
 *   - `TERMINAL_REGISTRY_SNAPSHOT` is implemented in Phase 1. The snapshot
 *     service composes an authoritative terminal list for a company,
 *     hashes the canonical bytes of that list, links to the prior
 *     snapshot via the prior snapshot's `snapshot_hash`, and emits the
 *     event as a first-class row in `fiscal_events` (server-authored —
 *     the spec carves company-integrity events out of the device-authority
 *     pattern because they are operator-level facts, not per-device
 *     transactions).
 *   - `COMPANY_DAY_CLOSURE_MANIFEST` is reserved at the enum + DTO level
 *     in Phase 1; `FiscalEventPayloadRegistry::dtoClassFor()` throws
 *     `FiscalEventTypeNotImplemented` for it until the day-closure phase.
 *
 * The Step-1 provider verification test asserts the **cumulative** wiring
 * available by Task 26 — bindings from Tasks 17/18 plus the projector
 * tags from Tasks 21/22. Task 31's `fiscal:verify-event-chain` is NOT
 * asserted here (it hasn't been wired yet — Task 26 plan note).
 */
final class TerminalRegistrySnapshotTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $this->terminalId = $terminal->id;

        // operator_id has no FK constraint on fiscal_events; a real User row
        // is still seeded so audit trails downstream find a matching actor.
        $user = User::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Operator',
        ]);
        $this->operatorId = $user->id;
    }

    // =================================================================
    // Plan §1963 — emitInitialSnapshot happy path
    // =================================================================

    public function test_terminal_registry_snapshot_can_be_emitted(): void
    {
        $svc = $this->app->make(TerminalRegistrySnapshotService::class);

        $event = $svc->emitInitialSnapshot(
            $this->tenantId,
            $this->companyId,
            $this->terminalId,
            $this->operatorId,
        );

        $this->assertSame('TERMINAL_REGISTRY_SNAPSHOT', $event->event_type->value);
        $payload = $this->payloadOf($event);
        $this->assertArrayHasKey('terminals', $payload);
        $this->assertArrayHasKey('snapshot_hash', $payload);
        $this->assertArrayHasKey('prior_snapshot_link', $payload);
    }

    public function test_emitted_snapshot_persists_a_fiscal_events_row_with_verified_integrity(): void
    {
        $svc = $this->app->make(TerminalRegistrySnapshotService::class);

        $event = $svc->emitInitialSnapshot(
            $this->tenantId,
            $this->companyId,
            $this->terminalId,
            $this->operatorId,
        );

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('TERMINAL_REGISTRY_SNAPSHOT', $row->event_type);
        $this->assertSame(IntegrityStatus::Verified->value, $row->integrity_status);
        $this->assertSame(PayloadParseStatus::Parsed->value, $row->payload_parse_status);
        $this->assertSame(SignatureStatus::NotRequired->value, $row->signature_status);
        $this->assertSame($this->tenantId, $row->tenant_id);
        $this->assertSame($this->companyId, $row->company_id);
        $this->assertSame($this->terminalId, $row->terminal_id);
        $this->assertSame($this->operatorId, $row->operator_id);
        // Hash + chain invariants enforced by the row's CHECK constraints.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->current_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->previous_hash);
        $this->assertGreaterThan(0, (int) $row->sequence_number);
    }

    public function test_first_snapshot_links_previous_hash_to_terminal_genesis_seed(): void
    {
        // T19-B3 invariant carry-forward — the first event on a terminal
        // chain (no prior fiscal_events row) MUST set previous_hash =
        // pos_terminals.genesis_seed. Server-authored events follow the
        // same chain invariants as device-authored events.
        $svc = $this->app->make(TerminalRegistrySnapshotService::class);
        $event = $svc->emitInitialSnapshot(
            $this->tenantId,
            $this->companyId,
            $this->terminalId,
            $this->operatorId,
        );

        $terminal = Terminal::query()->findOrFail($this->terminalId);
        $this->assertSame($terminal->genesis_seed, $event->previous_hash);
        $this->assertSame(1, $event->sequence_number);
        $payload = $this->payloadOf($event);
        // prior_snapshot_link is null on the first snapshot for the company.
        $this->assertNull($payload['prior_snapshot_link']);
    }

    public function test_terminals_payload_includes_authoritative_identity_columns(): void
    {
        // The snapshot must enumerate the company's terminals with their
        // canonical identity columns from pos_terminals — at minimum
        // terminal_id + code + genesis_seed so a verifier can re-prove the
        // company's terminal roster from the snapshot bytes alone.
        $svc = $this->app->make(TerminalRegistrySnapshotService::class);
        $event = $svc->emitInitialSnapshot(
            $this->tenantId,
            $this->companyId,
            $this->terminalId,
            $this->operatorId,
        );

        $payload = $this->payloadOf($event);
        $terminals = $payload['terminals'];
        $this->assertIsArray($terminals);
        $this->assertCount(1, $terminals);

        $entry = $terminals[0];
        $this->assertIsArray($entry);
        $this->assertSame($this->terminalId, $entry['terminal_id']);
        $this->assertArrayHasKey('code', $entry);
        $this->assertArrayHasKey('genesis_seed', $entry);
        $this->assertArrayHasKey('is_active', $entry);
    }

    public function test_snapshot_hash_is_sha256_of_canonical_terminals_list(): void
    {
        // The snapshot_hash field must be a deterministic SHA-256 over the
        // canonical-serialized terminals list — that's the verifiable
        // anchor a downstream auditor uses to confirm the snapshot wasn't
        // re-authored after the fact.
        $svc = $this->app->make(TerminalRegistrySnapshotService::class);
        $event = $svc->emitInitialSnapshot(
            $this->tenantId,
            $this->companyId,
            $this->terminalId,
            $this->operatorId,
        );

        $payload = $this->payloadOf($event);
        $snapshotHash = $payload['snapshot_hash'];
        $this->assertIsString($snapshotHash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $snapshotHash);

        // Recompute over the canonical terminals list and confirm.
        /** @var list<array<string, mixed>> $terminals */
        $terminals = $payload['terminals'];
        $recomputed = hash('sha256', $this->canonicalEncode($terminals));
        $this->assertSame($recomputed, $snapshotHash);
    }

    public function test_multiple_company_terminals_appear_in_snapshot_sorted_by_code(): void
    {
        // Deterministic enumeration — a snapshot of N terminals must order
        // them by `code` so two calls produce byte-identical terminals
        // lists regardless of which row the query returned first.
        Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'code' => 'POS09',
            'genesis_seed' => str_repeat('a', 64),
        ]);
        Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'code' => 'POS02',
            'genesis_seed' => str_repeat('b', 64),
        ]);

        $svc = $this->app->make(TerminalRegistrySnapshotService::class);
        $event = $svc->emitInitialSnapshot(
            $this->tenantId,
            $this->companyId,
            $this->terminalId,
            $this->operatorId,
        );

        $payload = $this->payloadOf($event);
        /** @var list<array<string, mixed>> $terminals */
        $terminals = $payload['terminals'];
        $this->assertCount(3, $terminals);

        // Codes ascending — pulled from the snapshot list in order.
        $codes = array_map(static fn (array $t): string => (string) $t['code'], $terminals);
        $sorted = $codes;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $codes);
    }

    public function test_soft_deleted_terminals_are_excluded_from_snapshot(): void
    {
        // A hard-deleted terminal is no longer in the roster — the spec's
        // "authoritative list of terminals expected for a company" excludes
        // it. Inactive (is_active=false) terminals are still included
        // (covered separately via the is_active payload field). The
        // service explicitly filters `deleted_at IS NULL` so removing the
        // soft-delete column or the filter surfaces immediately.
        $extra = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'code' => 'POS08',
            'genesis_seed' => str_repeat('d', 64),
        ]);
        $extra->delete();

        $svc = $this->app->make(TerminalRegistrySnapshotService::class);
        $event = $svc->emitInitialSnapshot(
            $this->tenantId,
            $this->companyId,
            $this->terminalId,
            $this->operatorId,
        );

        $payload = $this->payloadOf($event);
        /** @var list<array<string, mixed>> $terminals */
        $terminals = $payload['terminals'];
        $ids = array_map(static fn (array $t): string => (string) $t['terminal_id'], $terminals);
        $this->assertNotContains($extra->id, $ids);
        $this->assertContains($this->terminalId, $ids);
    }

    public function test_other_company_terminals_are_excluded_from_snapshot(): void
    {
        // A company-integrity snapshot is scoped to (tenant, company) —
        // foreign-tenant terminals must never bleed into the list, even
        // when they share the same Eloquent table.
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        Terminal::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
            'genesis_seed' => str_repeat('c', 64),
        ]);

        $svc = $this->app->make(TerminalRegistrySnapshotService::class);
        $event = $svc->emitInitialSnapshot(
            $this->tenantId,
            $this->companyId,
            $this->terminalId,
            $this->operatorId,
        );

        $payload = $this->payloadOf($event);
        /** @var list<array<string, mixed>> $terminals */
        $terminals = $payload['terminals'];
        $this->assertCount(1, $terminals);
        $this->assertSame($this->terminalId, $terminals[0]['terminal_id']);
    }

    public function test_second_snapshot_links_to_prior_via_prior_snapshot_link_and_advances_sequence(): void
    {
        // Per spec §11, snapshots form a sub-chain — the second snapshot
        // carries `prior_snapshot_link` = first snapshot's `snapshot_hash`.
        // The terminal chain also advances: sequence_number increments and
        // previous_hash links to the prior event's current_hash.
        $svc = $this->app->make(TerminalRegistrySnapshotService::class);

        $first = $svc->emitInitialSnapshot(
            $this->tenantId,
            $this->companyId,
            $this->terminalId,
            $this->operatorId,
        );

        $second = $svc->emitInitialSnapshot(
            $this->tenantId,
            $this->companyId,
            $this->terminalId,
            $this->operatorId,
        );

        $firstPayload = $this->payloadOf($first);
        $secondPayload = $this->payloadOf($second);
        $this->assertSame(
            $firstPayload['snapshot_hash'],
            $secondPayload['prior_snapshot_link'],
        );

        $this->assertSame(2, $second->sequence_number);
        $this->assertSame($first->current_hash, $second->previous_hash);
    }

    public function test_unknown_terminal_id_throws_at_service_boundary(): void
    {
        // The service uses the supplied terminal_id as the authoring chain
        // anchor for the emitted event — a non-existent terminal_id is a
        // programming error (the caller is responsible for resolving a
        // real terminal before emitting). Fail closed at the service
        // boundary rather than write a fiscal_events row whose
        // previous_hash cannot be reconciled with a chain head.
        $orphan = Str::uuid()->toString();
        $svc = $this->app->make(TerminalRegistrySnapshotService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($orphan);

        $svc->emitInitialSnapshot(
            $this->tenantId,
            $this->companyId,
            $orphan,
            $this->operatorId,
        );
    }

    // =================================================================
    // Plan §1975 — COMPANY_DAY_CLOSURE_MANIFEST reserved
    // =================================================================

    public function test_company_day_closure_manifest_throws_not_implemented_on_append(): void
    {
        $this->expectException(FiscalEventTypeNotImplemented::class);

        $this->app->make(FiscalEventPayloadRegistry::class)
            ->dtoClassFor(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST);
    }

    // =================================================================
    // Plan §1981 — FiscalServiceProvider cumulative wiring
    // =================================================================

    public function test_fiscal_service_provider_binds_the_seam_interfaces(): void
    {
        // Bindings from Task 17.
        $this->assertInstanceOf(
            DefaultModuleActivationResolver::class,
            $this->app->make(ModuleActivationResolver::class),
        );

        // Registry singleton + projector tags from Tasks 18/21/22.
        $registry = $this->app->make(FiscalEventProjectionRegistry::class);
        /** @var list<FiscalEventProjector> $projectors */
        $projectors = iterator_to_array($registry->all(), false);
        $names = array_map(static fn ($p): string => $p->name(), $projectors);

        $this->assertContains('pos_core_receipt', $names);
        $this->assertContains('treasury_receipt_bridge', $names);
    }

    public function test_fiscal_service_provider_does_not_yet_wire_verify_event_chain_command(): void
    {
        // Task 31 plan note — `fiscal:verify-event-chain` is wired in
        // Task 31 only. A premature wiring here would cumulatively over-
        // assert against the incremental-wiring convention; this guard
        // asserts the OMISSION so a future task that registers the command
        // here without updating Task 26 surfaces immediately.
        $allCommands = array_keys($this->app->make(Kernel::class)->all());
        $this->assertNotContains('fiscal:verify-event-chain', $allCommands);
    }

    public function test_pos_core_receipt_projection_is_resolvable_from_registry(): void
    {
        // Cumulative wiring check — Task 21's projector is not only tagged
        // but also resolvable by name via the registry's runtime lookup.
        $registry = $this->app->make(FiscalEventProjectionRegistry::class);
        $projector = $registry->byName('pos_core_receipt');
        $this->assertInstanceOf(PosCoreReceiptProjection::class, $projector);
    }

    public function test_treasury_receipt_bridge_is_resolvable_from_registry(): void
    {
        // Cumulative wiring check — Task 22's projector is tagged + named-
        // resolvable. The registry's runtime lookup IGNORES module
        // activation gating, so this passes whether or not Treasury is
        // active for any specific (tenant, company) pair.
        $registry = $this->app->make(FiscalEventProjectionRegistry::class);
        $projector = $registry->byName('treasury_receipt_bridge');
        $this->assertInstanceOf(TreasuryReceiptBridge::class, $projector);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Narrow `FiscalEvent::$payload` from `array<string, mixed>|null` to
     * `array<string, mixed>` so PHPStan-level-8 type-checks pass on
     * `$payload['key']` subscripts. A verified TERMINAL_REGISTRY_SNAPSHOT
     * event must always carry a parsed payload; a null at this point is
     * a service-layer bug, so the assertion serves both the static and
     * runtime contract.
     *
     * @return array<string, mixed>
     */
    private function payloadOf(FiscalEvent $event): array
    {
        $payload = $event->payload;
        $this->assertNotNull($payload, 'Verified TERMINAL_REGISTRY_SNAPSHOT must carry a parsed payload');

        return $payload;
    }

    /**
     * Spec §4 JCS-shape canonical encoding for the terminals list — used
     * only to recompute snapshot_hash inside the test. Sorts keys at every
     * object depth, preserves list ordering, no insignificant whitespace.
     * Mirrors the convention used by PosCoreReceiptProjectionTest's
     * `canonicalEncode()` helper.
     *
     * @param  list<array<string, mixed>>  $list
     */
    private function canonicalEncode(array $list): string
    {
        $sorted = $this->sortRecursive($list);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('canonical encode failed in test helper');
        }

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->sortRecursive($v), $value);
        }
        ksort($value);

        return array_map(fn ($v) => $this->sortRecursive($v), $value);
    }
}
