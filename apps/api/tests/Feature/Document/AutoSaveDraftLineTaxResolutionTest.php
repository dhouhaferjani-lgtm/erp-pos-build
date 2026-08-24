<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Campaign defect N-1 (P0), gate r1 finding 1 — the AUTOSAVE arm.
 *
 * The N-1 frontend fix made `buildLinePayload()` send `tax_configuration_id`
 * INSTEAD OF `tax_rate` for any line whose product carries a configuration
 * (which, on a correctly-configured TN tenant, is every product line). Both
 * document call sites route through that builder, and the autosave one is the
 * one that hits `POST /api/v1/documents/auto-save`.
 *
 * That endpoint could not read the field: `AutoSaveDraftRequest` declared no
 * `lines.*.tax_configuration_id` rule (so `validated()` dropped the key), and
 * `DraftPersistenceService` never called `DocumentLineTaxResolver` — it wrote
 * `$lineData['tax_rate'] ?? 0`. The result was an autosaved draft line at
 * **0 %**: strictly worse than the 19 % N-1 was about, and lane-introduced.
 *
 * The draft is a real `documents` row. Re-opening it hydrates the persisted
 * rate, and conversion copies `tax_rate` verbatim
 * (`Conversion/Concerns/CopiesDocumentData.php`), so a 0 % draft becomes a 0 %
 * invoice. This class pins the PERSISTED `document_lines.tax_rate` — the only
 * assertion that would have caught it; the lane's vitest file tests
 * `buildLinePayload` in isolation, which is exactly why it slipped.
 *
 * The contract pinned: the draft path resolves tax through the SAME
 * `DocumentLineTaxResolver` the manual create path uses, so a line resolves
 * identically whether the operator typed it into an autosaving editor or
 * submitted it.
 */
final class AutoSaveDraftLineTaxResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        (new CountriesSeeder)->run();

        $this->tenant = Tenant::create([
            'name' => 'N1 Autosave VAT Tenant',
            'slug' => 'n1-autosave-vat-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
            'default_tax_rate' => '19.00',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'N1 Autosave User',
            'email' => 'n1-autosave-vat@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->seed(TunisiaTaxConfigurationSeeder::class);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'N1 Autosave Customer',
            'type' => PartnerType::Customer,
        ]);
    }

    public function test_an_autosaved_line_resolves_seven_percent_from_the_tax_configuration_id(): void
    {
        // The payload buildLinePayload() emits after the N-1 frontend fix: the
        // configuration id, and NO tax_rate.
        //
        // The product sits on a DIFFERENT band (TVA 13, per-line override — the
        // editor's own line selector at DocumentLineEditor.tsx:871 produces
        // exactly this), and its denormalised rate is the stale 19.00. So 7.00
        // can only have come from the line's `tax_configuration_id`: drop that
        // rule from AutoSaveDraftRequest and this returns 13.00; drop the whole
        // resolution and it returns 0.00. Fiscal gate r2 finding 5 — the
        // earlier fixture let branches 2, 3 and 4 all answer 7.00, so it proved
        // nothing about which one did.
        $product = $this->productOn('TVA_13');

        $rates = $this->autoSaveAndReadRates([
            [
                'product_id' => $product->id,
                'description' => 'Sérum physiologique 5ml x20',
                'quantity' => '1.0000',
                'unit_price' => '6.200',
                'tax_configuration_id' => $this->config('TVA_7')->id,
            ],
        ]);

        $this->assertSame(
            ['7.00'],
            $rates,
            'The draft line must carry the line configuration\'s 7 % — not 0, not the product\'s band, not the company default.',
        );
    }

    public function test_an_autosaved_line_falls_back_to_the_products_own_configuration(): void
    {
        // A line the operator has not re-picked a configuration on: the id is
        // absent, but the product carries one. Resolver branch 3.
        $product = $this->productOn('TVA_13');

        $rates = $this->autoSaveAndReadRates([
            [
                'product_id' => $product->id,
                'description' => 'Lait infantile 1er âge 400g',
                'quantity' => '2.0000',
                'unit_price' => '32.000',
            ],
        ]);

        $this->assertSame(['13.00'], $rates);
    }

    public function test_the_line_configuration_answers_even_when_the_product_has_none(): void
    {
        // The sharpest form of the contract: nothing but the line's own
        // `tax_configuration_id` can produce 7.00 here. If the auto-save
        // request rule is dropped, `validated()` loses the field and this
        // returns the product's 19.00.
        $product = $this->productWithNoConfiguration();

        $rates = $this->autoSaveAndReadRates([
            [
                'product_id' => $product->id,
                'description' => 'Produit sans configuration, ligne sur TVA 7 %',
                'quantity' => '1.0000',
                'unit_price' => '6.200',
                'tax_configuration_id' => $this->config('TVA_7')->id,
            ],
        ]);

        $this->assertSame(['7.00'], $rates);
    }

    public function test_the_line_configuration_outranks_a_client_echoed_rate(): void
    {
        // Fiscal gate r2 finding 4. A caller that sends BOTH is stating two
        // things at once; the configuration is the one the operator clicked and
        // the rate is a copy that may be stale. Before the branch swap this
        // persisted 19.00 — N-1 arriving by a second route, reachable from any
        // integration client and from a browser still on a pre-deploy bundle.
        $product = $this->productWithNoConfiguration();

        $rates = $this->autoSaveAndReadRates([
            [
                'product_id' => $product->id,
                'description' => 'Configuration + stale rate on the same line',
                'quantity' => '1.0000',
                'unit_price' => '6.200',
                'tax_rate' => '19.00',
                'tax_configuration_id' => $this->config('TVA_7')->id,
            ],
        ]);

        $this->assertSame(
            ['7.00'],
            $rates,
            'The configuration the line names must outrank a rate echoed beside it.',
        );
    }

    public function test_the_manual_create_path_applies_the_same_precedence(): void
    {
        // One policy, one place: the swap lives in DocumentLineTaxResolver, so
        // it must hold on the submit path too — otherwise auto-saving and
        // submitting the same cart would price it differently.
        $product = $this->productWithNoConfiguration();

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/invoices', [
                'partner_id' => $this->customer->id,
                'document_date' => '2026-08-24',
                'lines' => [
                    [
                        'description' => 'Configuration + stale rate, manual submit',
                        'product_id' => $product->id,
                        'quantity' => '1.0000',
                        'unit_price' => '100.000',
                        'tax_rate' => '19.00',
                        'tax_configuration_id' => $this->config('TVA_7')->id,
                    ],
                ],
            ]);

        $response->assertCreated();

        $rates = Document::query()
            ->findOrFail((string) $response->json('data.id'))
            ->lines()
            ->pluck('tax_rate')
            ->map(static fn (mixed $rate): string => (string) $rate)
            ->all();

        $this->assertSame(['7.00'], $rates);
    }

    public function test_an_autosaved_line_still_honours_an_explicit_rate(): void
    {
        // Non-regression: a free-text/service line states its own rate, and the
        // draft path must treat it exactly as the manual create path does.
        $rates = $this->autoSaveAndReadRates([
            [
                'description' => 'Frais de livraison',
                'quantity' => '1.0000',
                'unit_price' => '10.000',
                'tax_rate' => '13.00',
            ],
        ]);

        $this->assertSame(['13.00'], $rates);
    }

    public function test_an_autosaved_line_that_states_nothing_gets_the_company_default_not_zero(): void
    {
        // Before this fix the draft path wrote `?? 0` here, so a line with no
        // stated tax silently became a 0 % line. The company default is what
        // the manual create path produces for the same payload.
        $rates = $this->autoSaveAndReadRates([
            [
                'description' => 'Ligne sans taxe déclarée',
                'quantity' => '1.0000',
                'unit_price' => '100.000',
            ],
        ]);

        $this->assertSame(['19.00'], $rates);
    }

    public function test_autosave_refuses_a_tax_configuration_from_another_country(): void
    {
        // The rule copied from CreateDocumentRequest is country-scoped; without
        // it the endpoint would accept any UUID and the resolver would silently
        // fall through to a different rate.
        $foreign = TaxConfiguration::create([
            'country_code' => 'FR',
            'tax_type' => 'PERCENTAGE',
            'name' => 'TVA FR 20',
            'code' => 'FR_TVA_20_N1',
            'percentage_rate' => '20.00',
            'fixed_amount' => null,
            'applies_to' => 'LINE_ITEMS',
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [
                    [
                        'description' => 'Foreign configuration',
                        'quantity' => '1.0000',
                        'unit_price' => '100.000',
                        'tax_configuration_id' => $foreign->id,
                    ],
                ],
            ]);

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------

    /**
     * POST one auto-save with the given lines and return the PERSISTED
     * `document_lines.tax_rate` values in line order. Reading the response is
     * not enough — the endpoint answers `{draft_id, saved_at, line_count}` and
     * its blanket catch answers 200 even on failure, so the database is the
     * only honest witness.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, string>
     */
    private function autoSaveAndReadRates(array $lines): array
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => $lines,
            ]);

        $response->assertOk();

        $draftId = (string) $response->json('draft_id');

        $document = Document::query()->findOrFail($draftId);

        return $document->lines()
            ->orderBy('line_number')
            ->pluck('tax_rate')
            ->map(static fn (mixed $rate): string => (string) $rate)
            ->all();
    }

    private function config(string $code): TaxConfiguration
    {
        return TaxConfiguration::query()
            ->where('country_code', 'TN')
            ->where('code', $code)
            ->firstOrFail();
    }

    /**
     * A product in the REAL N-1 shape: the operator picked `$code`, but the
     * denormalised column still holds the company default.
     *
     * The stale 19.00 is load-bearing, not scenery (fiscal gate r2 finding 5).
     * When the fixture set `tax_rate` to the configuration's own percentage,
     * resolver branches 2, 3 and 4 all returned the same number and the test
     * could not tell which one had answered — dropping the whole
     * `lines.*.tax_configuration_id` rule left it green. With 19.00 here, only
     * the configuration branches can produce 7.00.
     */
    private function productOn(string $code): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'default_tax_configuration_id' => $this->config($code)->id,
            'tax_rate' => '19.00',
        ]);
    }

    /**
     * A product with NO configuration at all and the company default on its
     * rate — so a 7.00 result can ONLY have come from the line's own
     * `tax_configuration_id`, never from the product.
     */
    private function productWithNoConfiguration(): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'default_tax_configuration_id' => null,
            'tax_rate' => '19.00',
        ]);
    }
}
