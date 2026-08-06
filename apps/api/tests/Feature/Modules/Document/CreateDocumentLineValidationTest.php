<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Document;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Feature tests for CreateDocumentRequest line-level validation.
 *
 * Tests enforce:
 * - lines.*.description: required, min:1, max:500
 * - lines.*.notes: nullable, max:1000
 * - designation_default_snapshot: stripped from client payload (server-only)
 */
class CreateDocumentLineValidationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant Validation',
            'slug' => 'test-tenant-validation',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'VALIDTAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'validation-test@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => 'customer',
            'code' => 'CUST001',
        ]);
    }

    /**
     * Helper: base valid line data.
     *
     * @return array<string, mixed>
     */
    private function validLine(): array
    {
        return [
            'description' => 'Oil Filter Part',
            'quantity' => 1,
            'unit_price' => 25.00,
        ];
    }

    /**
     * Helper: base valid invoice payload.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function invoicePayload(array $lines): array
    {
        return [
            'partner_id' => $this->partner->id,
            'document_date' => now()->format('Y-m-d'),
            'lines' => $lines,
        ];
    }

    #[Test]
    public function empty_description_fails_validation(): void
    {
        $line = $this->validLine();
        $line['description'] = '';

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->invoicePayload([$line]));

        $this->assertApiValidationErrors($response, ['lines.0.description']);
    }

    #[Test]
    public function whitespace_only_description_fails_validation(): void
    {
        $line = $this->validLine();
        $line['description'] = '   ';

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->invoicePayload([$line]));

        $this->assertApiValidationErrors($response, ['lines.0.description']);
    }

    #[Test]
    public function description_over_500_chars_fails_validation(): void
    {
        $line = $this->validLine();
        $line['description'] = str_repeat('a', 501);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->invoicePayload([$line]));

        $this->assertApiValidationErrors($response, ['lines.0.description']);
    }

    #[Test]
    public function description_of_exactly_500_chars_passes_validation(): void
    {
        $line = $this->validLine();
        $line['description'] = str_repeat('a', 500);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->invoicePayload([$line]));

        // Should not be 422 due to description (may succeed or create the invoice)
        $errors = $response->json('error.errors') ?? [];
        $this->assertArrayNotHasKey(
            'lines.0.description',
            $errors,
            'A 500-char description must not fail validation'
        );
    }

    #[Test]
    public function notes_over_1000_chars_fails_validation(): void
    {
        $line = $this->validLine();
        $line['notes'] = str_repeat('n', 1001);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->invoicePayload([$line]));

        $this->assertApiValidationErrors($response, ['lines.0.notes']);
    }

    // ── W-3 negative-net guard (2026-08-03 ticket) ──────────────────────────
    //
    // discount_amount must be rejected when negative, and rejected when it
    // exceeds the line gross (qty × unit_price). These rules must apply to
    // ALL document types, not only invoices/orders (the tolerance-gated
    // routes) — so each case is proven against BOTH `/quotes` (base rule
    // only, AppliesDiscountToleranceRule does not fire on quotes.store) and
    // `/invoices` (base rule merged with the tolerance rule).

    #[Test]
    public function negative_discount_amount_fails_validation_on_quotes(): void
    {
        $line = $this->validLine();
        $line['quantity'] = 10;
        $line['unit_price'] = 12.500;
        $line['discount_amount'] = -5.000;

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', $this->invoicePayload([$line]));

        $this->assertApiValidationErrors($response, ['lines.0.discount_amount']);
    }

    #[Test]
    public function negative_discount_amount_fails_validation_on_invoices(): void
    {
        $line = $this->validLine();
        $line['quantity'] = 10;
        $line['unit_price'] = 12.500;
        $line['discount_amount'] = -5.000;

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->invoicePayload([$line]));

        $this->assertApiValidationErrors($response, ['lines.0.discount_amount']);
    }

    #[Test]
    public function discount_amount_above_line_gross_fails_validation_on_quotes(): void
    {
        // qty 10 x 12.500 = 125.000 gross; 200.000 discount is above gross.
        $line = $this->validLine();
        $line['quantity'] = 10;
        $line['unit_price'] = 12.500;
        $line['discount_amount'] = 200.000;

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', $this->invoicePayload([$line]));

        $this->assertApiValidationErrors($response, ['lines.0.discount_amount']);
    }

    #[Test]
    public function discount_amount_above_line_gross_fails_validation_on_invoices(): void
    {
        // qty 10 x 12.500 = 125.000 gross; 200.000 discount is above gross.
        $line = $this->validLine();
        $line['quantity'] = 10;
        $line['unit_price'] = 12.500;
        $line['discount_amount'] = 200.000;

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->invoicePayload([$line]));

        $this->assertApiValidationErrors($response, ['lines.0.discount_amount']);
    }

    #[Test]
    public function discount_amount_exceeding_line_gross_by_one_thousandth_fails_validation(): void
    {
        // The 0.001 excess is only observable at a 3-decimal currency scale
        // (money's own storage precision, CLAUDE.md rule 19). The suite's
        // default fixture company is EUR (scale 2), where bcsub/bccomp at
        // company scale would silently absorb a 0.001 excess without ever
        // going negative — a real behaviour, not a test bug, but it can't
        // exercise THIS boundary. Use a dedicated TND (scale 3) company so
        // the excess is actually comparable.
        [$user, $partner] = $this->makeTndFixture();

        // qty 1 x 10.000 = 10.000 gross; 10.001 is 0.001 above gross.
        $line = $this->validLine();
        $line['quantity'] = 1;
        $line['unit_price'] = 10.000;
        $line['discount_amount'] = 10.001;

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $partner->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [$line],
            ]);

        $this->assertApiValidationErrors($response, ['lines.0.discount_amount']);
    }

    /**
     * Builds a second tenant/company/user/partner fixture on a 3-decimal
     * currency (TND), for boundary cases that only manifest at money's own
     * storage precision. Not seeded as the default fixture because most of
     * this class's assertions are currency-scale-agnostic.
     *
     * @return array{0: User, 1: Partner}
     */
    private function makeTndFixture(): array
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant Validation TND',
            'slug' => 'test-tenant-validation-tnd',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company TND',
            'legal_name' => 'Test Company TND SARL',
            'tax_id' => 'VALIDTAXTND',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User TND',
            'email' => 'validation-test-tnd@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);

        $partner = Partner::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Test Partner TND',
            'type' => 'customer',
            'code' => 'CUSTTND001',
        ]);

        return [$user, $partner];
    }

    #[Test]
    public function discount_amount_exactly_equal_to_line_gross_is_accepted(): void
    {
        // qty 1 x 10.000 = 10.000 gross; discount == gross is the boundary,
        // must be ACCEPTED (line net floors at 0, not rejected). Uses
        // /invoices (not /quotes) because InvoiceController::store() is the
        // endpoint that actually applies computeLineTotal() to the stored
        // line_total — QuoteController::store() does not apply the line
        // discount to line_total at all (pre-existing, out of this fix's
        // scope). The test company currency is EUR (scale 2).
        $line = $this->validLine();
        $line['quantity'] = 1;
        $line['unit_price'] = 10.000;
        $line['discount_amount'] = 10.000;

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->invoicePayload([$line]));

        $errors = $response->json('error.errors') ?? [];
        $this->assertArrayNotHasKey(
            'lines.0.discount_amount',
            $errors,
            'discount_amount == line gross must be accepted. Response: '.json_encode($response->json())
        );
        $response->assertCreated();
        $this->assertSame('0.00', $response->json('data.lines.0.line_total'));
    }

    #[Test]
    public function discount_percent_path_is_unchanged_by_the_new_amount_guard(): void
    {
        // A pure-percent line (no discount_amount at all) must not be
        // affected by the new gross-comparison guard on discount_amount.
        $line = $this->validLine();
        $line['quantity'] = 10;
        $line['unit_price'] = 12.500;
        $line['discount_percent'] = 10.00;

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->invoicePayload([$line]));

        $response->assertCreated();
        $this->assertSame('112.50', $response->json('data.lines.0.line_total'));
    }

    #[Test]
    public function designation_default_snapshot_sent_by_client_is_stripped(): void
    {
        $line = $this->validLine();
        $line['designation_default_snapshot'] = 'Client-supplied snapshot that must be ignored';

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->invoicePayload([$line]));

        // The request should not fail due to designation_default_snapshot being present.
        // It should be silently stripped. If 201, verify DB does not have the client value.
        if ($response->status() === 201) {
            $invoiceId = $response->json('data.id');
            $this->assertDatabaseMissing('document_lines', [
                'document_id' => $invoiceId,
                'designation_default_snapshot' => 'Client-supplied snapshot that must be ignored',
            ]);
        } else {
            // Any 422 must not contain designation_default_snapshot as a validation key
            $errors = $response->json('error.errors') ?? [];
            $this->assertArrayNotHasKey(
                'lines.0.designation_default_snapshot',
                $errors,
                'designation_default_snapshot must not cause a validation error — it should be silently stripped'
            );
        }
    }
}
