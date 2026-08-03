<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\Services\CreditNoteService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RULING A (2026-08-03 re-gate, A1 blocker) -- exhaustive characterisation.
 * The gate's own probe found `RuntimeException` on 1.68% (single line) to
 * 15.97% (2 lines, first zero-priced) of every millime target between
 * 0.001 and 120.000. `allocateGroupExactly()` no longer throws at all --
 * this test proves it by calling the REAL private method directly (no HTTP,
 * no per-iteration DB write -- fast in-process loop) across EVERY millime
 * from 0.001 up to the fixture invoice's own total, for the exact line
 * shapes the gate's probe used: a single real line, two real lines (the
 * MTP-DOC-16 fixture), and two lines with the FIRST zero-priced (A2's
 * specific inert-knob repro).
 */
class CreditNoteAllocationExhaustiveProbeTest extends TestCase
{
    use RefreshDatabase;

    private CreditNoteService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Country::create([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'currency_symbol' => 'د.ت',
        ]);

        $tenant = Tenant::create(['name' => 'Probe Tenant', 'slug' => 'probe-tenant']);
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Probe Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);
        app(CompanyContext::class)->setCompanyId($company->id);

        $this->service = app(CreditNoteService::class);
    }

    /**
     * @return array{0: \ReflectionMethod, 1: \ReflectionMethod}
     */
    private function reflectAllocator(): array
    {
        $allocate = new \ReflectionMethod($this->service, 'allocateGroupExactly');
        $allocate->setAccessible(true);
        $scaleFor = new \ReflectionMethod($this->service, 'scaleFor');
        $scaleFor->setAccessible(true);

        return [$allocate, $scaleFor];
    }

    /**
     * @param  Collection<int, DocumentLine>  $lines
     */
    private function probeEveryMillime(Collection $lines, string $ratePercent, int $scale, string $upTo, string $label): void
    {
        [$allocate] = $this->reflectAllocator();

        /** @var numeric-string $tick */
        $tick = '0.001';
        $violations = [];
        $exactCount = 0;
        $floorCount = 0;
        $target = $tick;

        while (bccomp($target, $upTo, 3) <= 0) {
            try {
                /** @var array{net: string, vat: string, lines: array, exact: bool} $result */
                $result = $allocate->invoke($this->service, $lines, $target, $ratePercent, $scale);
            } catch (\Throwable $e) {
                $violations[] = "target={$target}: threw ".get_class($e).': '.$e->getMessage();
                $target = bcadd($target, $tick, 3);

                continue;
            }

            $actual = bcadd($result['net'], $result['vat'], $scale);

            // RULING A invariant: NEVER above the requested target.
            if (bccomp($actual, $target, $scale) > 0) {
                $violations[] = "target={$target}: effective={$actual} EXCEEDS target (must never drift upward)";
            }

            if ($result['exact']) {
                $exactCount++;
            } else {
                $floorCount++;
                // Bounded deviation: the floor must be close (a handful of
                // ticks), never a wild drop to zero for a target well within
                // the invoice's means.
                $deviation = bcsub($target, $actual, $scale);
                if (bccomp($deviation, '0.010', $scale) > 0) {
                    $violations[] = "target={$target}: effective={$actual} deviates by {$deviation} (> 0.010, suspiciously large floor)";
                }
            }

            $target = bcadd($target, $tick, 3);
        }

        $this->assertSame(
            [],
            $violations,
            "{$label}: found ".count($violations)." violation(s) across the exhaustive millime sweep up to {$upTo} "
            ."(exact={$exactCount}, floored={$floorCount}). First few:\n".implode("\n", array_slice($violations, 0, 10))
        );
    }

    public function test_single_real_line_never_throws_across_every_millime(): void
    {
        [, $scaleFor] = $this->reflectAllocator();
        $line = new DocumentLine(['quantity' => '1.0000', 'unit_price' => '99.000', 'tax_rate' => '19.00']);
        $lines = new Collection([$line]);
        $scale = $scaleFor->invoke($this->service, new Document(['currency' => 'TND']));

        $this->probeEveryMillime($lines, '19.00', $scale, '118.810', '1 real line @19% (INV-2026-0507 shape)');
    }

    public function test_two_real_lines_never_throws_across_every_millime(): void
    {
        [, $scaleFor] = $this->reflectAllocator();
        $line1 = new DocumentLine(['quantity' => '10.0000', 'unit_price' => '12.500', 'tax_rate' => '19.00']);
        $line2 = new DocumentLine(['quantity' => '4.0000', 'unit_price' => '25.000', 'tax_rate' => '19.00']);
        $lines = new Collection([$line1, $line2]);
        $scale = $scaleFor->invoke($this->service, new Document(['currency' => 'TND']));

        $this->probeEveryMillime($lines, '19.00', $scale, '268.750', '2 real lines @19% (MTP-DOC-16 fixture)');
    }

    /**
     * A2's specific repro shape: the FIRST line in the group is zero-priced
     * (a bonus/freebie line), which used to disable the sub-tick knob
     * entirely (it was hard-coded to index 0) and drove the gate's worst
     * failure rate, 15.97%.
     */
    public function test_two_lines_first_zero_priced_never_throws_across_every_millime(): void
    {
        [, $scaleFor] = $this->reflectAllocator();
        $bonusLine = new DocumentLine(['quantity' => '1.0000', 'unit_price' => '0.000', 'tax_rate' => '19.00']);
        $paidLine = new DocumentLine(['quantity' => '2.0000', 'unit_price' => '30.000', 'tax_rate' => '19.00']);
        $lines = new Collection([$bonusLine, $paidLine]);
        $scale = $scaleFor->invoke($this->service, new Document(['currency' => 'TND']));

        $this->probeEveryMillime($lines, '19.00', $scale, '60.000', '2 lines, first zero-priced @19%');
    }
}
