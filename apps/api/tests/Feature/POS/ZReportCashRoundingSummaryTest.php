<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\ZReportProjection;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 11 — server-derived Z `cash_rounding_summary` + additive hash normalization.
 *
 * The summary is DERIVED on the server inside {@see ZReportProjection}: the
 * projection rebuilds `report_data` from the canonical Z payload with a fixed
 * key list, so a device-authored `cash_rounding_summary` could never reach it
 * anyway. Aggregation is over PROJECTED `pos_receipts`, v3-gated by joining
 * `fiscal_events.event_version`, windowed by terminal + `posted_at` (receipts
 * carry no `shift_id` — same derivation as
 * `PaymentToleranceQueryService::shiftReceiptsQuery`).
 *
 * The hash side is ADDITIVE: an isset-guarded per-key block, with NO
 * `schema_version` bump, so legacy `report_data` hashes byte-identically.
 */
final class ZReportCashRoundingSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD_START = '2026-05-24T08:00:00.000Z';

    private const PERIOD_END = '2026-05-24T18:00:00.000Z';

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private User $cashier;

    private Terminal $terminal;

    private Shift $shift;

    private int $nextSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'Default Cashier',
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'code' => 'T001',
        ]);

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opening_cash' => '100.00',
            'status' => ShiftStatus::Open,
            'opened_at' => '2026-05-24 08:00:00',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Derivation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_summary_sums_only_v3_receipts_in_the_z_window(): void
    {
        // Two v3 rounded receipts (-0.023 and +0.027) plus one v2 receipt in
        // the same terminal/time window. Mixed signs: the sum must be signed.
        $event = $this->projectZReportOverReceipts([
            ['adjustment' => '-0.023', 'event_version' => 3],
            ['adjustment' => '0.027', 'event_version' => 3],
            ['adjustment' => null, 'event_version' => 2],
        ]);

        $reportData = $this->projectedReportData($event);

        $this->assertArrayHasKey('cash_rounding_summary', $reportData);
        $summary = $reportData['cash_rounding_summary'];
        $this->assertIsArray($summary);
        $this->assertSame('0.004', $summary['total_adjustment']);
        $this->assertSame(2, $summary['receipt_count']);
    }

    public function test_summary_total_can_be_negative(): void
    {
        $event = $this->projectZReportOverReceipts([
            ['adjustment' => '-0.023', 'event_version' => 3],
            ['adjustment' => '-0.017', 'event_version' => 3],
        ]);

        $summary = $this->projectedReportData($event)['cash_rounding_summary'];

        $this->assertIsArray($summary);
        $this->assertSame('-0.040', $summary['total_adjustment']);
        $this->assertSame(2, $summary['receipt_count']);
    }

    public function test_summary_is_canonical_zero_when_no_receipt_rounded(): void
    {
        $event = $this->projectZReportOverReceipts([
            ['adjustment' => null, 'event_version' => 2],
        ]);

        $summary = $this->projectedReportData($event)['cash_rounding_summary'];

        $this->assertIsArray($summary);
        $this->assertSame('0.000', $summary['total_adjustment']);
        $this->assertSame(0, $summary['receipt_count']);
    }

    public function test_v3_receipts_with_a_zero_adjustment_do_not_inflate_the_count(): void
    {
        // A v3 non-rounded receipt stores '0.000' (NOT null) — receipt_count
        // must count only rows whose adjustment is actually non-zero.
        $event = $this->projectZReportOverReceipts([
            ['adjustment' => '0.000', 'event_version' => 3],
            ['adjustment' => '-0.023', 'event_version' => 3],
        ]);

        $summary = $this->projectedReportData($event)['cash_rounding_summary'];

        $this->assertIsArray($summary);
        $this->assertSame('-0.023', $summary['total_adjustment']);
        $this->assertSame(1, $summary['receipt_count']);
    }

    public function test_voided_receipts_are_excluded(): void
    {
        $event = $this->projectZReportOverReceipts([
            ['adjustment' => '-0.023', 'event_version' => 3],
            ['adjustment' => '-0.023', 'event_version' => 3, 'is_voided' => true],
        ]);

        $summary = $this->projectedReportData($event)['cash_rounding_summary'];

        $this->assertIsArray($summary);
        $this->assertSame('-0.023', $summary['total_adjustment']);
        $this->assertSame(1, $summary['receipt_count']);
    }

    public function test_training_receipts_are_excluded(): void
    {
        $event = $this->projectZReportOverReceipts([
            ['adjustment' => '-0.023', 'event_version' => 3],
            ['adjustment' => '-0.023', 'event_version' => 3, 'is_training' => true],
        ]);

        $summary = $this->projectedReportData($event)['cash_rounding_summary'];

        $this->assertIsArray($summary);
        $this->assertSame('-0.023', $summary['total_adjustment']);
        $this->assertSame(1, $summary['receipt_count']);
    }

    public function test_receipts_outside_the_z_window_are_excluded(): void
    {
        $event = $this->projectZReportOverReceipts([
            ['adjustment' => '-0.023', 'event_version' => 3],
            ['adjustment' => '-0.100', 'event_version' => 3, 'posted_at' => '2026-05-24 19:30:00'],
            ['adjustment' => '-0.200', 'event_version' => 3, 'posted_at' => '2026-05-24 07:30:00'],
        ]);

        $summary = $this->projectedReportData($event)['cash_rounding_summary'];

        $this->assertIsArray($summary);
        $this->assertSame('-0.023', $summary['total_adjustment']);
        $this->assertSame(1, $summary['receipt_count']);
    }

    public function test_receipts_on_another_terminal_are_excluded(): void
    {
        $otherTerminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'code' => 'T002',
        ]);

        $event = $this->projectZReportOverReceipts([
            ['adjustment' => '-0.023', 'event_version' => 3],
            ['adjustment' => '-0.500', 'event_version' => 3, 'terminal_id' => $otherTerminal->id],
        ]);

        $summary = $this->projectedReportData($event)['cash_rounding_summary'];

        $this->assertIsArray($summary);
        $this->assertSame('-0.023', $summary['total_adjustment']);
        $this->assertSame(1, $summary['receipt_count']);
    }

    public function test_projection_rebuild_keeps_the_full_legacy_key_list(): void
    {
        $event = $this->projectZReportOverReceipts([
            ['adjustment' => '-0.023', 'event_version' => 3],
        ]);

        $reportData = $this->projectedReportData($event);

        // The summary is emitted by the projection's fixed-key rebuild, next to
        // the sibling blocks — not merged in from the device payload.
        $this->assertArrayHasKey('tolerance_summary', $reportData);
        $this->assertArrayHasKey('cash_rounding_summary', $reportData);
        $this->assertArrayHasKey('canonical_z_report', $reportData);
        $this->assertArrayNotHasKey(
            'cash_rounding_summary',
            $reportData['canonical_z_report'],
            'Z_REPORT stays v1 — the canonical payload must NOT gain a key.',
        );
    }

    public function test_schema_version_is_unchanged_by_this_task(): void
    {
        $event = $this->projectZReportOverReceipts([
            ['adjustment' => '-0.023', 'event_version' => 3],
        ]);

        $reportData = $this->projectedReportData($event);

        // Pinned so a future edit cannot silently re-normalize refunds_amount.
        $this->assertSame(3, $reportData['schema_version']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Hash normalization — additive, legacy-safe
    // ─────────────────────────────────────────────────────────────────────────

    public function test_hash_normalization_is_additive_and_legacy_safe(): void
    {
        $service = $this->app->make(ZReportHashService::class);

        $legacy = ['schema_version' => 2, 'expected_cash' => '10.5', 'actual_cash' => '10.5'];
        $this->assertSame($service->normalizeForHash($legacy), $service->normalizeForHash($legacy));
        $this->assertArrayNotHasKey('cash_rounding_summary', $service->normalizeForHash($legacy));

        $withSummary = [
            'schema_version' => 3,
            'cash_rounding_summary' => ['total_adjustment' => '-0.0230', 'receipt_count' => 1],
        ];
        $normalized = $service->normalizeForHash($withSummary);
        $this->assertIsArray($normalized['cash_rounding_summary']);
        $this->assertSame('-0.023', $normalized['cash_rounding_summary']['total_adjustment']);
        $this->assertSame(1, $normalized['cash_rounding_summary']['receipt_count']);
    }

    public function test_the_summary_participates_in_the_hash(): void
    {
        $service = $this->app->make(ZReportHashService::class);

        $base = [
            'schema_version' => 3,
            'expected_cash' => '100.000',
            'cash_rounding_summary' => ['total_adjustment' => '0.000', 'receipt_count' => 0],
        ];
        $mutated = $base;
        $mutated['cash_rounding_summary'] = ['total_adjustment' => '-0.023', 'receipt_count' => 1];

        $this->assertNotSame(
            $this->digest($service->normalizeForHash($base)),
            $this->digest($service->normalizeForHash($mutated)),
            'Mutating cash_rounding_summary.total_adjustment MUST change the hash.',
        );
    }

    public function test_legacy_report_data_without_the_key_hashes_byte_identically(): void
    {
        $service = $this->app->make(ZReportHashService::class);

        // Frozen pre-change fixture. The digest below was computed against the
        // normalizer as it stood BEFORE cash_rounding_summary existed; the new
        // block is isset-guarded, so it must not move. It also pins the r2
        // hazard: at schema_version 2, refunds_amount is NOT normalized.
        $legacy = [
            'schema_version' => 2,
            'business_date' => '2026-05-24',
            'expected_cash' => '100.5',
            'actual_cash' => '100.50',
            'variance' => '0',
            'refunds_amount' => '12.5',
            'payment_methods' => [['payment_type' => 'CASH', 'total_amount' => '50.1']],
        ];

        $normalized = $service->normalizeForHash($legacy);

        $this->assertArrayNotHasKey('cash_rounding_summary', $normalized);
        $this->assertSame('12.5', $normalized['refunds_amount']);
        $this->assertSame(
            '5d45c2d7d9b9bf314bbc822a0c9fbc62c597fcdb98bd102eeca11f1aa808a5dc',
            $this->digest($normalized),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Seed the given receipts inside (or deliberately outside) the Z window,
     * then project the Z_REPORT fiscal event over them.
     *
     * @param  list<array{adjustment: numeric-string|null, event_version: int, is_voided?: bool, is_training?: bool, posted_at?: string, terminal_id?: string}>  $receipts
     */
    private function projectZReportOverReceipts(array $receipts): FiscalEvent
    {
        foreach ($receipts as $spec) {
            $this->seedReceipt($spec);
        }

        $event = $this->storeZReportFiscalEvent();
        $this->app->make(ZReportProjection::class)->apply($event);

        return $event;
    }

    /**
     * @param  array{adjustment: numeric-string|null, event_version: int, is_voided?: bool, is_training?: bool, posted_at?: string, terminal_id?: string}  $spec
     */
    private function seedReceipt(array $spec): void
    {
        $terminalId = $spec['terminal_id'] ?? $this->terminal->id;
        $adjustment = $spec['adjustment'];
        $isV3 = $spec['event_version'] >= 3;

        $saleEvent = $this->storeSaleReceiptFiscalEvent($spec['event_version'], $terminalId);

        $subtotal = '10.000';
        $taxAmount = '1.900';
        $total = bcadd(bcadd($subtotal, $taxAmount, 3), $adjustment ?? '0', 3);

        $attributes = [
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'terminal_id' => $terminalId,
            'cashier_id' => $this->cashier->id,
            'posted_at' => $spec['posted_at'] ?? '2026-05-24 12:00:00',
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => '0.000',
            'total' => $total,
            'currency' => 'TND',
            'is_voided' => $spec['is_voided'] ?? false,
            'is_training' => $spec['is_training'] ?? false,
            'fiscal_event_id' => $saleEvent->id,
        ];

        if ($isV3) {
            // Mirrors PosCoreReceiptProjection: the rounding columns are
            // written ONLY on v3+ rows, and a non-rounded v3 row stores
            // '0.000' rather than NULL.
            $attributes['cash_rounding_adjustment'] = $adjustment ?? '0.000';
            $attributes['cash_rounding_denomination'] = '0.0500';
        }

        if ($attributes['is_voided'] === true) {
            // pos_receipts_void_logic CHECK: a voided row must carry both
            // voided_at and voided_by.
            $attributes['voided_at'] = '2026-05-24 12:30:00';
            $attributes['voided_by'] = $this->cashier->id;
            $attributes['void_reason'] = 'test void';
        }

        Receipt::factory()->create($attributes);
    }

    private function storeSaleReceiptFiscalEvent(int $eventVersion, string $terminalId): FiscalEvent
    {
        $sourceId = Str::uuid()->toString();

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $terminalId,
            'operator_id' => $this->cashier->id,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => $eventVersion,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $this->nextSequence++,
            'event_time_device' => '2026-05-24 12:00:00',
            'business_date' => '2026-05-24',
            'chain_context' => 'z_session',
            'last_server_time_seen' => null,
            'server_received_at' => '2026-05-24 12:00:01',
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'pos_receipt',
            'source_event_id' => $sourceId,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => '{}',
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', $sourceId),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => [],
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }

    private function storeZReportFiscalEvent(): FiscalEvent
    {
        $payload = $this->zReportPayload();
        $canonicalBytes = $this->canonicalEncode($payload);

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->cashier->id,
            'event_type' => FiscalEventType::Z_REPORT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 9000,
            'event_time_device' => '2026-05-24 18:00:00',
            'business_date' => '2026-05-24',
            'chain_context' => 'z_session',
            'last_server_time_seen' => null,
            'server_received_at' => '2026-05-24 18:00:01',
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'z_report',
            'source_event_id' => $payload['z_report_uuid'],
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', $canonicalBytes),
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
    private function projectedReportData(FiscalEvent $event): array
    {
        $report = ZReport::query()->where('fiscal_event_id', $event->id)->firstOrFail();

        /** @var array<string, mixed> $reportData */
        $reportData = $report->report_data;

        return $reportData;
    }

    /**
     * @return array<string, mixed>
     */
    private function zReportPayload(): array
    {
        return [
            'business_date' => '2026-05-24',
            'cash_count' => [
                'counted_cash' => '150.000',
                'expected_cash' => '150.000',
                'lines' => [],
                'variance_amount' => '0.000',
                'variance_direction' => 'balanced',
                'variance_reason' => null,
                'variance_severity' => 'balanced',
            ],
            'cash_drawer_totals' => ['opening_cash' => '100.000'],
            'closed_at_device' => '2026-05-24T18:00:00.000Z',
            'company_snapshot' => ['company_id' => $this->companyId],
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'formatted_z_number' => 'Z0003',
            'grand_totals_after' => [
                'cumulative_refunds' => '0.000',
                'cumulative_sales' => '550.000',
                'cumulative_tax' => '88.000',
                'perpetual_grand_total' => '550.000',
                'receipt_count_lifetime' => 11,
            ],
            'grand_totals_before' => [
                'cumulative_refunds' => '0.000',
                'cumulative_sales' => '500.000',
                'cumulative_tax' => '80.000',
                'perpetual_grand_total' => '500.000',
                'receipt_count_lifetime' => 10,
            ],
            'legacy_report_reference' => null,
            'operational_event_range' => ['receipt_count' => 1],
            'operator_id' => $this->cashier->id,
            'operator_name' => 'Default Cashier',
            'payment_method_totals' => [['payment_type' => 'CASH', 'total_amount' => '50.000', 'transaction_count' => 1]],
            'period_end' => self::PERIOD_END,
            'period_start' => self::PERIOD_START,
            'period_type' => 'DAY',
            'receipt_totals' => ['count' => 1, 'gross_sales' => '50.000', 'net_sales' => '42.000', 'tax_amount' => '8.000'],
            'refunds_totals' => ['amount' => '0.000', 'count' => 0],
            'seller' => null,
            'session_event_range' => ['first_sequence' => 1, 'last_sequence' => 3],
            'session_id' => '44444444-4444-4444-8444-444444444444',
            'shift_id' => $this->shift->id,
            'terminal_id' => $this->terminal->id,
            'terminal_label' => 'T001',
            'tolerance_summary' => null,
            'training_flag' => false,
            'vat_breakdown' => [['gross_amount' => '50.000', 'net_amount' => '42.000', 'tax_rate' => 19, 'vat_amount' => '8.000']],
            'voids_totals' => ['count' => 0],
            'z_number' => 3,
            'z_report_uuid' => '66666666-6666-4666-8666-666666666666',
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function digest(array $normalized): string
    {
        return hash(
            'sha256',
            json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
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
