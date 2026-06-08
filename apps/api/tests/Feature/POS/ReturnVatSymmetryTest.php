<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Application\Services\ReceiptCreationService;
use App\Modules\POS\Application\Services\ReceiptReturnService;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 3.11 — VAT symmetry between creation and return paths.
 *
 * The fiscal hash on a POS device is computed from canonicalized amounts. The
 * server-side return path MUST round VAT the EXACT same way as the creation
 * path, or a refund VAT line could drift from the original sale's VAT line and
 * break the accounting identity / fiscal export. Both services delegate to one
 * shared `RoundsVat::roundVat()` helper so they can never diverge.
 *
 * This test pins that invariant: 100 random (netAmount, taxRate) pairs must
 * produce IDENTICAL VAT from both the creation and the return service.
 */
final class ReturnVatSymmetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind a TND company (scale 3) so getScale() resolves deterministically.
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
        ]);

        /** @var CompanyContext $ctx */
        $ctx = app(CompanyContext::class);
        $ctx->setCompanyId($company->id);
    }

    public function test_creation_and_return_round_vat_identically(): void
    {
        $creation = app(ReceiptCreationService::class);
        $return = app(ReceiptReturnService::class);

        $creationRound = new ReflectionMethod($creation, 'roundVat');
        $returnRound = new ReflectionMethod($return, 'roundVat');

        // Deterministic seed so failures reproduce.
        mt_srand(20260530);

        for ($i = 0; $i < 100; $i++) {
            // Random net amount in [0, 100000) with 3 decimals (TND scale).
            $netAmount = sprintf('%d.%03d', mt_rand(0, 99_999), mt_rand(0, 999));
            // Random tax rate in [0, 30] with 2 decimals.
            $taxRate = sprintf('%d.%02d', mt_rand(0, 30), mt_rand(0, 99));

            $fromCreation = $creationRound->invoke($creation, $netAmount, $taxRate);
            $fromReturn = $returnRound->invoke($return, $netAmount, $taxRate);

            $this->assertSame(
                $fromCreation,
                $fromReturn,
                "VAT drift for net={$netAmount} rate={$taxRate}: "
                ."creation={$fromCreation} return={$fromReturn}",
            );
        }
    }

    public function test_known_half_away_from_zero_rounding_is_shared(): void
    {
        $creation = app(ReceiptCreationService::class);
        $return = app(ReceiptReturnService::class);

        $creationRound = new ReflectionMethod($creation, 'roundVat');
        $returnRound = new ReflectionMethod($return, 'roundVat');

        // net=10.000 @ 19% = 1.9000 -> 1.900 at scale 3.
        $this->assertSame('1.900', $creationRound->invoke($creation, '10.000', '19.00'));
        $this->assertSame('1.900', $returnRound->invoke($return, '10.000', '19.00'));

        // A value that exercises the half-away-from-zero boundary at scale 3:
        // net=1.005 @ 50% = 0.5025 -> 0.503 (half up away from zero).
        $this->assertSame('0.503', $creationRound->invoke($creation, '1.005', '50.00'));
        $this->assertSame('0.503', $returnRound->invoke($return, '1.005', '50.00'));
    }
}
