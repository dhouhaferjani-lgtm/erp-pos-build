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
