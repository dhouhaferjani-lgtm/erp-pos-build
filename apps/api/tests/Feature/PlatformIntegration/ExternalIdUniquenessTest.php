<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformIntegration;

use App\Enums\Vertical;
use App\Modules\Billing\Domain\Enums\PaymentProviderCode;
use App\Modules\Billing\Domain\Enums\PaymentStatus;
use App\Modules\Billing\Domain\Payment;
use App\Modules\Billing\Presentation\Controllers\StripeWebhookController;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Application\Listeners\ProcessEnrichmentEventListener;
use App\Modules\Product\Application\Services\EnrichmentReviewService;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\PlatformSubmissionInterface;
use App\Shared\Enums\EnrichmentStatus;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Concern 2 — External-id columns used as tenant anchors must be:
 *   (a) DB-UNIQUE so cross-tenant collisions are structurally impossible, AND
 *   (b) Resolved with ->sole() or count-guard so the code fails loud on
 *       collision even if the constraint is dropped in the future
 *       (defense-in-depth against migration regressions).
 *
 * Two columns in scope:
 *   - products.platform_submission_id (Finding A)
 *   - billing_payments.(provider, provider_payment_id) (Finding F)
 */
final class ExternalIdUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'External ID Tenant',
            'slug' => 'external-id-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'External ID Company',
            'legal_name' => 'External ID Co',
            'tax_id' => 'TAX-EXT-001',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
    }

    // -------------------------------------------------------------------
    // Schema assertions (post-migration constraint state)
    // -------------------------------------------------------------------

    public function test_products_platform_submission_id_is_unique_at_db_level(): void
    {
        $this->expectException(QueryException::class);

        $sharedSubmissionId = (string) Str::uuid();

        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'platform_submission_id' => $sharedSubmissionId,
            'enrichment_status' => EnrichmentStatus::Pending,
        ]);

        // Second product with the same platform_submission_id: must fail at DB level.
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'platform_submission_id' => $sharedSubmissionId,
            'enrichment_status' => EnrichmentStatus::Pending,
        ]);
    }

    public function test_billing_payments_provider_payment_id_is_unique_per_provider(): void
    {
        $this->expectException(QueryException::class);

        $sharedPi = 'pi_collision_test_123';

        DB::table('billing_payments')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'provider' => PaymentProviderCode::Stripe->value,
            'provider_payment_id' => $sharedPi,
            'status' => PaymentStatus::Succeeded->value,
            'amount' => '100.000',
            'fee' => '0.000',
            'net_amount' => '100.000',
            'currency' => 'EUR',
            'refunded_amount' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Second Stripe payment with the same provider_payment_id: must fail.
        DB::table('billing_payments')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'provider' => PaymentProviderCode::Stripe->value,
            'provider_payment_id' => $sharedPi,
            'status' => PaymentStatus::Succeeded->value,
            'amount' => '200.000',
            'fee' => '0.000',
            'net_amount' => '200.000',
            'currency' => 'EUR',
            'refunded_amount' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_billing_payments_unique_constraint_allows_same_id_across_different_providers(): void
    {
        // Same provider_payment_id is allowed when providers differ — the
        // UNIQUE constraint is on the (provider, provider_payment_id) tuple,
        // not on provider_payment_id alone. This test guards against a
        // future regression where the migration mistakenly applies UNIQUE
        // on the column instead of the tuple. Stripe + PayPal namespaces
        // are independent — `pi_*` IDs in Stripe and `PAYID-*` IDs in
        // PayPal — but a contrived collision (e.g., a partner reuses a
        // legacy customer-supplied identifier) must not be a UNIQUE
        // violation.

        $sharedPi = 'pi_cross_provider_namespace_collision';

        DB::table('billing_payments')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'provider' => PaymentProviderCode::Stripe->value,
            'provider_payment_id' => $sharedPi,
            'status' => PaymentStatus::Succeeded->value,
            'amount' => '100.000',
            'fee' => '0.000',
            'net_amount' => '100.000',
            'currency' => 'EUR',
            'refunded_amount' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('billing_payments')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'provider' => PaymentProviderCode::PayPal->value,
            'provider_payment_id' => $sharedPi,
            'status' => PaymentStatus::Succeeded->value,
            'amount' => '100.000',
            'fee' => '0.000',
            'net_amount' => '100.000',
            'currency' => 'EUR',
            'refunded_amount' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(2, DB::table('billing_payments')
            ->where('provider_payment_id', $sharedPi)
            ->count());
    }

    public function test_pg_constraint_exists_on_products_platform_submission_id(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_constraint query is PostgreSQL-only.');
        }

        $constraints = DB::select(
            "SELECT conname FROM pg_constraint
             WHERE conrelid = 'products'::regclass
               AND contype = 'u'"
        );

        $hasConstraint = false;
        foreach ($constraints as $c) {
            if (str_contains($c->conname, 'platform_submission_id')) {
                $hasConstraint = true;
                break;
            }
        }

        $this->assertTrue(
            $hasConstraint,
            'Expected a UNIQUE constraint on products.platform_submission_id; not found in pg_constraint.'
        );
    }

    public function test_pg_constraint_exists_on_billing_payments_provider_tuple(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_constraint query is PostgreSQL-only.');
        }

        $constraints = DB::select(
            "SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint
             WHERE conrelid = 'billing_payments'::regclass
               AND contype = 'u'"
        );

        $hasConstraint = false;
        foreach ($constraints as $c) {
            $def = (string) $c->def;
            if (str_contains($def, 'provider') && str_contains($def, 'provider_payment_id')) {
                $hasConstraint = true;
                break;
            }
        }

        $this->assertTrue(
            $hasConstraint,
            'Expected a UNIQUE constraint on billing_payments(provider, provider_payment_id); not found in pg_constraint.'
        );
    }

    // -------------------------------------------------------------------
    // Defense-in-depth: code-level collision guard via ->sole()
    // -------------------------------------------------------------------

    public function test_enrichment_listener_throws_on_collision_when_constraint_is_dropped(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Constraint drop/recreate flow is PostgreSQL-only.');
        }

        // Drop the UNIQUE constraint temporarily so the test can seed a
        // collision state that the DB would otherwise prevent. This
        // exercises the code-level defense-in-depth (->sole()) — if the
        // constraint were ever dropped in production (migration mistake,
        // emergency rollback, etc.), the resolver MUST fail loud rather
        // than silently resolve to a wrong tenant's product.
        $constraintRow = DB::selectOne(
            "SELECT conname FROM pg_constraint
             WHERE conrelid = 'products'::regclass
               AND contype = 'u'
               AND pg_get_constraintdef(oid) LIKE '%platform_submission_id%'"
        );

        if ($constraintRow !== null) {
            DB::statement('ALTER TABLE products DROP CONSTRAINT '.$constraintRow->conname);
        }

        $sharedSubmissionId = (string) Str::uuid();

        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'platform_submission_id' => $sharedSubmissionId,
            'enrichment_status' => EnrichmentStatus::Pending,
        ]);

        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'platform_submission_id' => $sharedSubmissionId,
            'enrichment_status' => EnrichmentStatus::Pending,
        ]);

        $mockSubmission = $this->createMock(PlatformSubmissionInterface::class);
        $reviewService = new EnrichmentReviewService($mockSubmission);
        $listener = new ProcessEnrichmentEventListener($reviewService);

        $event = new EnrichmentWebhookReceived(
            $sharedSubmissionId,
            'enriching',
            null,
            false,
            'automotive',
        );

        $this->expectException(MultipleRecordsFoundException::class);

        $listener->handle($event);
    }

    public function test_enrichment_listener_swallows_not_found_gracefully(): void
    {
        // The not-yet-arrived case: the platform webhook arrives BEFORE
        // the local Product row exists. The listener must treat zero
        // results as a graceful "skip" path (log + return), NOT throw.
        // Distinguishes "zero rows = legitimate not-yet-arrived" from
        // ">1 row = collision" — both can occur, only one is an error.

        $mockSubmission = $this->createMock(PlatformSubmissionInterface::class);
        $reviewService = new EnrichmentReviewService($mockSubmission);
        $listener = new ProcessEnrichmentEventListener($reviewService);

        $event = new EnrichmentWebhookReceived(
            (string) Str::uuid(),  // unknown tracking id
            'enriching',
            null,
            false,
            'automotive',
        );

        // Must not throw. If implementation regresses to throwing
        // ModelNotFoundException for zero results, this fails.
        $listener->handle($event);

        $this->assertTrue(true, 'Listener swallowed zero-result case gracefully.');
    }

    public function test_stripe_webhook_resolver_filters_by_provider(): void
    {
        // Provider-filter discipline: same provider_payment_id value can
        // legitimately appear under different providers (each provider has
        // its own ID namespace; the namespaces are not coordinated).
        // When the Stripe webhook resolver receives a `pi_*` value, it
        // must filter by `provider = stripe` so it never updates a
        // foreign-provider payment that happened to share the same ID.
        //
        // RED → GREEN: today the controller calls
        // Payment::where('provider_payment_id', $pi)->first(), which is
        // non-deterministic when two providers share the ID (returns
        // whichever row matches first). The fix adds the provider filter
        // and switches to ->sole() — defense-in-depth even if a future
        // migration drops the (provider, provider_payment_id) UNIQUE.

        $sharedPi = 'pi_test_provider_filter_'.Str::random(8);

        // Insert the FOREIGN-provider row FIRST so an unfiltered
        // `where('provider_payment_id', …)->first()` resolves to the
        // wrong row. This makes the missing provider-filter bug
        // observable: today's handler returns the foreign row and
        // updates IT instead of the Stripe row, leaving the Stripe row
        // (which the webhook is actually about) un-transitioned.
        $foreignPayment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'provider' => PaymentProviderCode::PayPal->value,
            'provider_payment_id' => $sharedPi,
            'status' => PaymentStatus::Pending,
            'amount' => '999.000',  // distinct so we can tell which row got resolved
            'fee' => '0.000',
            'net_amount' => '999.000',
            'currency' => 'EUR',
            'refunded_amount' => '0.000',
        ]);

        $stripePayment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'provider' => PaymentProviderCode::Stripe->value,
            'provider_payment_id' => $sharedPi,
            'status' => PaymentStatus::Pending,
            'amount' => '100.000',
            'fee' => '0.000',
            'net_amount' => '100.000',
            'currency' => 'EUR',
            'refunded_amount' => '0.000',
        ]);

        // Drive through the Stripe webhook handler — the
        // handlePaymentIntentSucceeded private method is the resolver
        // surface that needs the provider filter. Reflection bypasses
        // the public handle() method's signature-verification gate so
        // the test isolates the resolver behavior.
        $controller = $this->app->make(StripeWebhookController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('handlePaymentIntentSucceeded');
        $method->setAccessible(true);
        $method->invoke($controller, [
            'id' => $sharedPi,
            'amount' => 10000,
        ]);

        $stripePayment->refresh();
        $foreignPayment->refresh();

        // The Stripe-provider row should have been transitioned to
        // Succeeded. The PayPal-provider row must remain untouched.
        $this->assertSame(
            PaymentStatus::Succeeded,
            $stripePayment->status,
            'Stripe payment should be resolved + transitioned by the handler'
        );
        $this->assertSame(
            PaymentStatus::Pending,
            $foreignPayment->status,
            'Non-Stripe payment with same provider_payment_id must NOT be touched by the Stripe handler'
        );
    }
}
