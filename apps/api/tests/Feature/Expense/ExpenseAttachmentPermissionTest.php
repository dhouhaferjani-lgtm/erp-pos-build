<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 7: Verify that expense-capable roles (accountant) can upload receipts
 * to expense documents via POST /api/v1/documents/{id}/attachments.
 *
 * The attachment endpoint is gated by `can:documents.update`. Expense roles
 * must carry documents.view + documents.update so the receipt upload flow
 * works. An Expense IS a Document (unified documents table), so reusing the
 * existing endpoint is the correct design — no new /expenses/{id}/attachments
 * route is needed.
 *
 * Permission names `documents.view` and `documents.update` exist in
 * RolesAndPermissionsSeeder (lines 96–97) and are assigned to the accountant
 * role (line 619), manager role (line 406), cashier role (lines 491–492),
 * and operator role (lines 590–591). This test LOCKS that invariant.
 */
final class ExpenseAttachmentPermissionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An accountant (who carries expenses.* + documents.view + documents.update)
     * must be able to upload a receipt PDF to an Expense document.
     *
     * The POST /api/v1/documents/{id}/attachments route is gated by
     * `can:documents.update`. The accountant role has this permission, so
     * the request should return 201.
     */
    public function test_accountant_can_upload_receipt_to_expense_document(): void
    {
        Storage::fake('s3');
        Queue::fake();

        [$user, $expense] = $this->makeAccountantWithExpense();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/documents/{$expense->id}/attachments", [
                'file' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
                'role' => 'SOURCE_DOCUMENT',
            ])
            ->assertCreated();
    }

    /**
     * A user who carries expenses.view but NOT documents.update must get a 403
     * when attempting to upload a receipt to an Expense document.
     *
     * This confirms the `can:documents.update` gate on the route is real and
     * that the previous test is not a false positive.
     */
    public function test_user_without_documents_update_cannot_upload_to_expense_document(): void
    {
        Storage::fake('s3');
        Queue::fake();

        [$user, $expense] = $this->makeUserWithPermissionsAndExpense(['expenses.view']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/documents/{$expense->id}/attachments", [
                'file' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
                'role' => 'SOURCE_DOCUMENT',
            ])
            ->assertForbidden();
    }

    /**
     * An accountant must also be able to list attachments on an Expense document
     * (gated by `can:documents.view`).
     */
    public function test_accountant_can_list_attachments_on_expense_document(): void
    {
        [$user, $expense] = $this->makeAccountantWithExpense();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/documents/{$expense->id}/attachments")
            ->assertOk();
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Create an accountant user + expense Document in a fresh tenant/company.
     *
     * The accountant role carries expenses.* + documents.view + documents.update
     * (as seeded by RolesAndPermissionsSeeder). CompanyContextMiddleware (wired
     * into the global api group) auto-resolves the company from the user's
     * first Active membership.
     *
     * @return array{0: User, 1: Document}
     */
    private function makeAccountantWithExpense(): array
    {
        $tenant = $this->makeTenant();
        $company = $this->makeCompany($tenant->id);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Accountant',
            'email' => 'accountant-'.Str::random(6).'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->assignRole('accountant');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'accountant',
            'status' => MembershipStatus::Active,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $expense = $this->makeExpense($tenant->id, $company->id);

        return [$user, $expense];
    }

    /**
     * Create a user with custom direct permissions (no role) + expense Document.
     *
     * Used for the negative test to assert that missing `documents.update`
     * results in a 403.
     *
     * @param  list<string>  $permissions
     * @return array{0: User, 1: Document}
     */
    private function makeUserWithPermissionsAndExpense(array $permissions): array
    {
        $tenant = $this->makeTenant();
        $company = $this->makeCompany($tenant->id);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'user-'.Str::random(6).'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'viewer',
            'status' => MembershipStatus::Active,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $expense = $this->makeExpense($tenant->id, $company->id);

        return [$user, $expense];
    }

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.Str::random(6),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);
    }

    private function makeCompany(string $tenantId): Company
    {
        return Company::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX-'.Str::random(6),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function makeExpense(string $tenantId, string $companyId): Document
    {
        return Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'partner_id' => null,
            'type' => DocumentType::Expense,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'EXP-'.Str::random(6),
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'currency' => 'EUR',
            'subtotal' => '100.000',
            'discount_amount' => '0.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'balance_due' => '100.000',
            'is_historical' => false,
        ]);
    }
}
