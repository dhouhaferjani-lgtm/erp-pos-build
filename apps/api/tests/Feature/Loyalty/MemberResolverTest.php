<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Loyalty\Application\Resolvers\MemberResolver;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
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
}
