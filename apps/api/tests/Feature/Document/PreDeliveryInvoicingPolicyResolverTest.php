<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Application\Services\PreDeliveryInvoicingPolicyResolver;
use App\Modules\Document\Domain\Enums\PreDeliveryInvoicingPolicy;
use App\Modules\Document\Domain\Exceptions\PreDeliveryInvoicingNotSupportedException;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * DPA Wave 3 / sub-wave 3E — **T25a**: the seeded per-country pre-delivery
 * invoicing policy, its ladder, and its fail-closed refusal.
 *
 * The owner rider is binding and this class is what enforces it: the policy is
 * SEEDED DATA, never a constant; every rung of the ladder resolves to
 * `require_delivery_first`; and `allow` — admitted by the enum and by the CHECK
 * so it can be enabled later without DDL — is UNREACHABLE at the resolver until
 * the 472/419 deferred-revenue machinery exists.
 */
class PreDeliveryInvoicingPolicyResolverTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures();
        (new CountryDocumentSettingsSeeder)->run();
    }

    private function resolver(): PreDeliveryInvoicingPolicyResolver
    {
        return app(PreDeliveryInvoicingPolicyResolver::class);
    }

    // ── The ladder ───────────────────────────────────────────────────────────

    public function test_tunisia_resolves_to_require_delivery_first_from_the_country_row(): void
    {
        $resolved = $this->resolver()->resolveForCompany($this->dpCompany);

        $this->assertSame(PreDeliveryInvoicingPolicy::RequireDeliveryFirst, $resolved->policy);
        $this->assertSame('country', $resolved->source);
    }

    public function test_france_is_seeded_explicitly_and_also_requires_delivery_first(): void
    {
        $this->dpCompany->update(['country_code' => 'FR']);

        $resolved = $this->resolver()->resolveForCompany($this->dpCompany->refresh());

        $this->assertSame(PreDeliveryInvoicingPolicy::RequireDeliveryFirst, $resolved->policy);
        $this->assertSame(
            'country',
            $resolved->source,
            'FR must resolve from its OWN seeded row, not from the unknown-country fallback — '
            .'an implicit default is how a country silently acquires a policy nobody chose.',
        );
    }

    /**
     * "Generic" — every country with no seeded row, which is what the generic
     * (non-TN, non-FR) tenant profile is. Fail closed to the SAME policy.
     */
    public function test_an_unknown_country_falls_through_to_the_system_default(): void
    {
        $this->dpCompany->update(['country_code' => 'IT']);

        $resolved = $this->resolver()->resolveForCompany($this->dpCompany->refresh());

        $this->assertSame(PreDeliveryInvoicingPolicy::RequireDeliveryFirst, $resolved->policy);
        $this->assertSame('system', $resolved->source);
    }

    public function test_a_company_override_wins_over_the_country_row(): void
    {
        $this->dpCompany->update([
            'pre_delivery_invoicing_policy' => PreDeliveryInvoicingPolicy::RequireDeliveryFirst->value,
        ]);

        $resolved = $this->resolver()->resolveForCompany($this->dpCompany->refresh());

        $this->assertSame(PreDeliveryInvoicingPolicy::RequireDeliveryFirst, $resolved->policy);
        $this->assertSame('company', $resolved->source);
    }

    // ── The refusal ──────────────────────────────────────────────────────────

    public function test_an_out_of_band_allow_on_the_company_is_refused(): void
    {
        DB::table('companies')
            ->where('id', $this->dpCompany->id)
            ->update(['pre_delivery_invoicing_policy' => PreDeliveryInvoicingPolicy::Allow->value]);

        $this->expectException(PreDeliveryInvoicingNotSupportedException::class);
        $this->expectExceptionMessageMatches('/472/');

        $this->resolver()->resolveForCompany($this->dpCompany->refresh());
    }

    public function test_an_out_of_band_allow_on_the_country_row_is_refused(): void
    {
        DB::table('country_document_settings')
            ->where('country_code', 'TN')
            ->update(['pre_delivery_invoicing_policy' => PreDeliveryInvoicingPolicy::Allow->value]);

        $this->expectException(PreDeliveryInvoicingNotSupportedException::class);
        $this->expectExceptionMessageMatches('/country/');

        $this->resolver()->resolveForCompany($this->dpCompany->refresh());
    }

    // ── The seeder ───────────────────────────────────────────────────────────

    public function test_the_seeder_is_idempotent_and_repins_the_policy(): void
    {
        DB::table('country_document_settings')
            ->where('country_code', 'TN')
            ->update(['pre_delivery_invoicing_policy' => PreDeliveryInvoicingPolicy::Allow->value]);

        (new CountryDocumentSettingsSeeder)->run();
        (new CountryDocumentSettingsSeeder)->run();

        $rows = DB::table('country_document_settings')->get();

        $this->assertCount(2, $rows, 'TN + FR, and no duplicates across runs.');
        foreach ($rows as $row) {
            $this->assertSame(
                PreDeliveryInvoicingPolicy::RequireDeliveryFirst->value,
                $row->pre_delivery_invoicing_policy,
                'The policy is PINNED — it is a statutory control, not operator state.',
            );
        }
    }

    /**
     * `allow` must be admitted by the CHECK constraint even though the resolver
     * refuses it — that is the whole point of the three-layer shape: switching it
     * on later must not require DDL on a live tenant database.
     */
    public function test_the_check_constraint_admits_allow_but_rejects_a_third_value(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints are PostgreSQL-only in this schema.');
        }

        DB::table('country_document_settings')
            ->where('country_code', 'TN')
            ->update(['pre_delivery_invoicing_policy' => 'allow']);

        // Read it BACK. `assertTrue(true)` asserted only that the line above did
        // not throw, which a silently-ignored update would also satisfy.
        $this->assertSame(
            'allow',
            DB::table('country_document_settings')->where('country_code', 'TN')
                ->value('pre_delivery_invoicing_policy'),
            'allow is admitted by the CHECK and actually stored.',
        );

        $this->expectException(QueryException::class);

        DB::table('country_document_settings')
            ->where('country_code', 'TN')
            ->update(['pre_delivery_invoicing_policy' => 'whenever_you_like']);
    }

    public function test_the_company_check_constraint_rejects_a_third_value(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints are PostgreSQL-only in this schema.');
        }

        $this->expectException(QueryException::class);

        DB::table('companies')
            ->where('id', $this->dpCompany->id)
            ->update(['pre_delivery_invoicing_policy' => 'whenever_you_like']);
    }
}
