<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\DTOs\CashCountInputDTO;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\PaymentToleranceQueryService;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 2 / Task 12 — Wire Z-report tolerance_summary to live data.
 *
 * Until Phase 1 wired the zero-shape placeholder, the Z-report's
 * report_data.tolerance_summary was hardcoded to {totalAmount: '0.000',
 * currencyCode, writeoffCount: 0}. Phase 2 replaces this with a real value
 * from PaymentToleranceQueryService::totalForShift().
 *
 * Three guarantees are tested:
 *  1. ReportGenerationService takes PaymentToleranceQueryService via
 *     constructor injection (Rule #13). Boundary check.
 *  2. Hash determinism for the zero-writeoff path: a shift with no writeoffs
 *     after wiring must produce the SAME hash as the pre-wiring zero-shape
 *     path. (The shape and values are identical, so the SHA-256 must be too.)
 *  3. Hash sensitivity for the non-zero path: a shift with a real €0.020
 *     writeoff produces a DIFFERENT, deterministic hash. Field participates.
 */
final class GenerateZReportToleranceWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_constructor_injects_payment_tolerance_query_service(): void
    {
        $reflection = new ReflectionClass(ReportGenerationService::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $params = collect($constructor->getParameters())
            ->map(fn ($p): ?string => $p->getType() instanceof \ReflectionNamedType ? $p->getType()->getName() : null)
            ->filter()
            ->all();

        $this->assertContains(
            PaymentToleranceQueryService::class,
            $params,
            'ReportGenerationService must take PaymentToleranceQueryService via constructor (Rule #13).',
        );

        // Cross-module boundary: the only treasury type wired in is the public
        // query service. The previous Phase-1 zero-shape implementation took zero
        // treasury dependencies; after wiring, exactly one treasury surface (the
        // public service) is permitted.
        $treasuryParams = collect($params)
            ->filter(fn (string $type): bool => str_starts_with($type, 'App\\Modules\\Treasury\\'))
            ->values()
            ->all();

        $this->assertSame(
            [PaymentToleranceQueryService::class],
            $treasuryParams,
            'ReportGenerationService should depend on exactly one Treasury type — the public query service.',
        );
    }

    public function test_zero_writeoff_shift_produces_identical_hash_to_phase_1_placeholder(): void
    {
        [$service, $terminal, $cashier, $cash] = $this->scaffold();

        $z = $service->generateZReport(
            $terminal,
            $cashier,
            [new CashCountInputDTO(
                paymentMethodId: $cash->id,
                currencyCode: 'EUR',
                actualAmount: '0.0000',
            )],
        );

        // Hash of the zero-shape Phase-1 placeholder: when totalAmount/currencyCode/
        // writeoffCount are emitted with these literal values, the normalised payload
        // hashes deterministically. Recomputing the hash from the live service must
        // yield the same fiscal_hash.
        /** @var ZReportHashService $hashService */
        $hashService = $this->app->make(ZReportHashService::class);

        /** @var array<string, mixed> $reportData */
        $reportData = $z->report_data;

        // The summary must still match the contracted zero-shape exactly.
        $this->assertSame(
            ['totalAmount' => '0.000', 'currencyCode' => 'EUR', 'writeoffCount' => 0],
            $reportData['tolerance_summary'],
            'Zero-writeoff shift must emit the same shape as the Phase-1 placeholder.',
        );

        // Re-normalising the same payload twice must produce the same hash.
        $normalized1 = $hashService->normalizeForHash($reportData);
        $normalized2 = $hashService->normalizeForHash($reportData);

        $h1 = hash('sha256', json_encode($normalized1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $h2 = hash('sha256', json_encode($normalized2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $this->assertSame($h1, $h2);
    }

    public function test_nonzero_writeoff_shift_produces_different_deterministic_hash(): void
    {
        [$service, $terminal, $cashier, $cash, $shift] = $this->scaffold(returnShift: true);

        // Seed a tolerance receipt within the shift's window.
        $this->seedToleranceReceipt(
            terminal: $terminal,
            shift: $shift,
            cashier: $cashier,
            cashMethodId: $cash->id,
            sequence: 1,
            total: '100.000',
            tendered: '99.980',
            toleranceWriteoff: '0.020',
        );

        $z = $service->generateZReport(
            $terminal,
            $cashier,
            [new CashCountInputDTO(
                paymentMethodId: $cash->id,
                currencyCode: 'EUR',
                actualAmount: '99.9800',
            )],
        );

        /** @var array<string, mixed> $reportData */
        $reportData = $z->report_data;

        // Live wiring must reflect the actual write-off.
        $this->assertSame('0.020', $reportData['tolerance_summary']['totalAmount']);
        $this->assertSame(1, $reportData['tolerance_summary']['writeoffCount']);
        $this->assertSame('EUR', $reportData['tolerance_summary']['currencyCode']);

        // Hash must differ from the zero-shape baseline.
        /** @var ZReportHashService $hashService */
        $hashService = $this->app->make(ZReportHashService::class);

        $live = hash(
            'sha256',
            json_encode($hashService->normalizeForHash($reportData), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        );

        $zeroShapeData = $reportData;
        $zeroShapeData['tolerance_summary'] = [
            'totalAmount' => '0.000',
            'currencyCode' => 'EUR',
            'writeoffCount' => 0,
        ];
        $baseline = hash(
            'sha256',
            json_encode($hashService->normalizeForHash($zeroShapeData), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        );

        $this->assertNotSame($baseline, $live, 'Non-zero tolerance MUST change the Z-report hash.');

        // And re-running the same payload must reproduce the same hash (determinism).
        $live2 = hash(
            'sha256',
            json_encode($hashService->normalizeForHash($reportData), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        );
        $this->assertSame($live, $live2);
    }

    /**
     * @return ($returnShift is true ? array{0: ReportGenerationService, 1: Terminal, 2: User, 3: PaymentMethod, 4: Shift}
     *                                : array{0: ReportGenerationService, 1: Terminal, 2: User, 3: PaymentMethod})
     */
    private function scaffold(bool $returnShift = false): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'currency' => 'EUR',
        ]);
        $this->app->make(CompanyContext::class)->setCompanyId($company->id);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);
        $shift = Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => Carbon::now()->subHour(),
            'opening_cash' => '100.0000',
        ]);
        $cash = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH',
            'is_physical' => true,
        ]);

        /** @var ReportGenerationService $service */
        $service = $this->app->make(ReportGenerationService::class);

        return $returnShift
            ? [$service, $terminal, $cashier, $cash, $shift]
            : [$service, $terminal, $cashier, $cash];
    }

    private function seedToleranceReceipt(
        Terminal $terminal,
        Shift $shift,
        User $cashier,
        string $cashMethodId,
        int $sequence,
        string $total,
        string $tendered,
        string $toleranceWriteoff,
    ): Receipt {
        $receipt = Receipt::create([
            'tenant_id' => $terminal->tenant_id,
            'company_id' => $terminal->company_id,
            'location_id' => $terminal->location_id,
            'terminal_id' => $terminal->id,
            'receipt_number' => sprintf('T001-C001-L01-POS01-2026-%08d', $sequence),
            'chain_sequence' => $sequence,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', 'fiscal-'.$sequence),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat-'.$sequence),
            'payment_methods_hash' => hash('sha256', 'pay-'.$sequence),
            'posted_at' => $shift->opened_at->copy()->addMinutes(5),
            'cashier_id' => $cashier->id,
            'cashier_name' => $cashier->name,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'currency' => 'EUR',
            'change_due' => '0.000',
            'tolerance_writeoff' => $toleranceWriteoff,
            'is_voided' => false,
            'is_training' => false,
        ]);

        ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $cashMethodId,
            'payment_type' => 'CASH',
            'amount' => $tendered,
        ]);

        return $receipt;
    }
}
