<?php

declare(strict_types=1);

namespace Tests\Unit\Company;

use App\Modules\Company\Application\Services\CountryTaxIdentityConfig;
use Tests\TestCase;

class CountryTaxIdentityConfigTest extends TestCase
{
    public function test_structural_countries_require_branch_tax_id(): void
    {
        $config = new CountryTaxIdentityConfig;

        $this->assertTrue($config->isBranchTaxIdRequired('FR'));
        $this->assertTrue($config->isBranchTaxIdRequired('TN'));
    }

    public function test_single_vat_countries_do_not_require_branch_tax_id(): void
    {
        $config = new CountryTaxIdentityConfig;

        $this->assertFalse($config->isBranchTaxIdRequired('IT'));
        $this->assertFalse($config->isBranchTaxIdRequired('AE'));
        $this->assertFalse($config->isBranchTaxIdRequired('XX'));
    }

    public function test_reads_country_mode_with_default_fallback(): void
    {
        $config = new CountryTaxIdentityConfig;

        $this->assertSame('structural', $config->mode('FR'));
        $this->assertSame('separate-linked', $config->mode('DZ'));
        $this->assertSame('none', $config->mode('XX'));
    }
}
