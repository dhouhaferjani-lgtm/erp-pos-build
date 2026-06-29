<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Company\Domain\Company;
use App\Modules\Loyalty\Application\Resolvers\MemberResolver;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MemberResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_by_contact_then_partner_then_null(): void
    {
        $tenantId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        $partnerId = (string) Str::uuid();

        $byContact = LoyaltyMember::factory()->create([
            'tenant_id' => $tenantId, 'loyaltyable_type' => 'contact', 'loyaltyable_id' => $contactId,
        ]);
        $byPartner = LoyaltyMember::factory()->create([
            'tenant_id' => $tenantId, 'loyaltyable_type' => 'partner', 'loyaltyable_id' => $partnerId,
        ]);

        $resolver = app(MemberResolver::class);

        self::assertSame($byContact->id, $resolver->resolveByContactOrPartner($tenantId, $contactId, null)?->id);
        self::assertSame($byPartner->id, $resolver->resolveByContactOrPartner($tenantId, null, $partnerId)?->id);
        self::assertNull($resolver->resolveByContactOrPartner($tenantId, (string) Str::uuid(), null));
    }

    public function test_customer_id_fallback_resolves_when_loyaltyable_points_elsewhere(): void
    {
        $tenantId = (string) Str::uuid();
        $otherId = (string) Str::uuid();    // where loyaltyable currently points

        // customer_id has a real FK to partners (company_id is also NOT NULL) —
        // create the minimal Tenant→Company→Partner chain so the insert succeeds.
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => 'customer',
        ]);
        $tenantId = $tenant->id; // override to use a real tenant_id for member lookup
        $customerId = (string) $customer->id;

        $member = LoyaltyMember::factory()->create([
            'tenant_id' => $tenantId,
            'loyaltyable_type' => 'partner',
            'loyaltyable_id' => $otherId,   // points elsewhere
            'customer_id' => $customerId,   // but customer_id matches
        ]);

        $resolver = app(MemberResolver::class);

        // resolveByContactOrPartner with (null, $customerId) hits the orWhere('customer_id', …) branch.
        self::assertSame($member->id, $resolver->resolveByContactOrPartner($tenantId, null, $customerId)?->id);
    }
}
