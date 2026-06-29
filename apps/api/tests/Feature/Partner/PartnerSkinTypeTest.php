<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\SkinType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PartnerSkinTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_skin_type_casts_to_skin_type_enum(): void
    {
        [$tenant, $company] = $this->tenantAndCompany();

        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'skin_type' => 'dry',
        ]);

        $partner->refresh();

        $this->assertInstanceOf(SkinType::class, $partner->skin_type);
        $this->assertSame(SkinType::Dry, $partner->skin_type);
    }

    public function test_partner_skin_advice_note_is_nullable(): void
    {
        [$tenant, $company] = $this->tenantAndCompany();

        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $this->assertNull($partner->skin_advice_note);
    }

    public function test_partner_skin_advice_note_accepts_text(): void
    {
        [$tenant, $company] = $this->tenantAndCompany();

        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'skin_advice_note' => 'Use moisturizer daily for dry skin',
        ]);

        $partner->refresh();

        $this->assertSame('Use moisturizer daily for dry skin', $partner->skin_advice_note);
    }

    /**
     * @return array{0: Tenant, 1: Company}
     */
    private function tenantAndCompany(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $company];
    }
}
