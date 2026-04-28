<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Document;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression tests for description/notes immutability on posted documents.
 *
 * Verifies that:
 * - PATCH with description change on a posted invoice returns 422 (DOCUMENT_NOT_EDITABLE)
 * - PATCH with notes change on a posted invoice returns 422 (DOCUMENT_NOT_EDITABLE)
 * - After a rejected update, description and notes remain unchanged in the DB
 *
 * These tests lock in the guard at InvoiceController::update() lines 302-308.
 * Rule 8 (Events are Immutable Forever) mandates this guard must never be removed.
 */
class PostedDocumentImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Immutability Test Tenant',
            'slug' => 'immutability-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Immutability Test Company',
            'legal_name' => 'Immutability Test Company LLC',
            'tax_id' => 'IMTAX123',
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
            'name' => 'Immutability Test User',
            'email' => 'immutability-test@example.com',
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
            'name' => 'Immutability Test Partner',
            'type' => 'customer',
            'code' => 'IMTST001',
        ]);
    }

    #[Test]
    public function patch_description_on_posted_invoice_is_rejected_with_422(): void
    {
        $invoice = $this->createPostedInvoiceWithLine(
            description: 'Original Description',
            notes: 'Original note',
        );

        $lineId = $invoice->lines->first()->id;

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/invoices/{$invoice->id}", [
                'lines' => [
                    [
                        'id' => $lineId,
                        'description' => 'Mutated description',
                        'quantity' => 1,
                        'unit_price' => 100.00,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_EDITABLE');
    }

    #[Test]
    public function patch_notes_on_posted_invoice_is_rejected_with_422(): void
    {
        $invoice = $this->createPostedInvoiceWithLine(
            description: 'Line Description',
            notes: 'Original tech note',
        );

        $lineId = $invoice->lines->first()->id;

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/invoices/{$invoice->id}", [
                'lines' => [
                    [
                        'id' => $lineId,
                        'description' => 'Line Description',
                        'quantity' => 1,
                        'unit_price' => 100.00,
                        'notes' => 'Mutated note',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_EDITABLE');
    }

    #[Test]
    public function description_and_notes_remain_unchanged_after_rejected_update_on_posted_invoice(): void
    {
        $invoice = $this->createPostedInvoiceWithLine(
            description: 'Preserved Description',
            notes: 'Preserved note',
        );

        $lineId = $invoice->lines->first()->id;

        // Attempt to mutate both fields
        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/invoices/{$invoice->id}", [
                'lines' => [
                    [
                        'id' => $lineId,
                        'description' => 'Should not be saved',
                        'quantity' => 1,
                        'unit_price' => 100.00,
                        'notes' => 'Should not be saved either',
                    ],
                ],
            ])
            ->assertStatus(422);

        // Verify DB is unchanged
        $this->assertDatabaseHas('document_lines', [
            'id' => $lineId,
            'description' => 'Preserved Description',
            'notes' => 'Preserved note',
        ]);

        $this->assertDatabaseMissing('document_lines', [
            'id' => $lineId,
            'description' => 'Should not be saved',
        ]);
    }

    /**
     * Create a posted invoice with a single line directly in the DB.
     *
     * Bypasses the service layer to set status=Posted directly,
     * which the API would never allow via normal flow.
     */
    private function createPostedInvoiceWithLine(string $description, ?string $notes = null): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-IMMUT-'.Str::uuid()->toString(),
            'document_date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
            'currency' => 'EUR',
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => $description,
            'notes' => $notes,
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '20',
            'line_total' => '100.00',
        ]);

        return $invoice->fresh(['lines']);
    }
}
