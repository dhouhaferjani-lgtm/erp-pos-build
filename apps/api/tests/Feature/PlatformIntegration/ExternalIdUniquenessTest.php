<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformIntegration;

use App\Enums\Vertical;
use App\Modules\Billing\Domain\Enums\PaymentProviderCode;
use App\Modules\Billing\Domain\Enums\PaymentStatus;
use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\Invoice;
use App\Modules\Billing\Domain\Payment;
use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Billing\Presentation\Controllers\StripeWebhookController;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
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
        $listener = new ProcessEnrichmentEventListener($reviewService, app(CompanyContext::class));

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
        $listener = new ProcessEnrichmentEventListener($reviewService, app(CompanyContext::class));

        $event = new EnrichmentWebhookReceived(
            (string) Str::uuid(),  // unknown tracking id
            'enriching',
            null,
            false,
            'automotive',
        );

        $this->expectNotToPerformAssertions();

        // Must not throw. If implementation regresses to throwing
        // ModelNotFoundException for zero results, this fails.
        $listener->handle($event);
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

    public function test_stripe_invoice_paid_handler_filters_by_provider_in_lookup(): void
    {
        // Codex round-1 BLOCK-NOVEL coverage: handleInvoicePaid uses
        // Payment::updateOrCreate() — the FIRST array is the lookup
        // attribute set, and pre-fix it filtered only by
        // provider_payment_id, so a foreign-provider row sharing the
        // same `pi_*` would be matched and OVERWRITTEN as Stripe.
        // Post-fix, the lookup pins (provider, provider_payment_id),
        // so the foreign row is never touched and a new Stripe row is
        // created (or an existing Stripe row is updated).

        $stripeInvoiceId = 'in_test_'.Str::random(8);
        $stripeSubId = 'sub_test_'.Str::random(8);
        $sharedPi = 'pi_invoice_paid_filter_'.Str::random(8);

        // Tenant subscription anchored on stripe_subscription_id —
        // handleInvoicePaid resolves $subscription via this anchor.
        $subscription = TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $this->seedPlan()->id,
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => 'monthly',
            'price' => '99.000',
            'currency' => 'EUR',
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->addDays(15),
            'stripe_subscription_id' => $stripeSubId,
        ]);

        $invoice = Invoice::create([
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $subscription->id,
            'number' => 'INV-'.Str::random(6),
            'status' => 'pending',
            'subtotal' => '99.000',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '99.000',
            'amount_paid' => '0.000',
            'amount_due' => '99.000',
            'currency' => 'EUR',
            'invoice_date' => now(),
            'due_date' => now()->addDays(7),
            'stripe_invoice_id' => $stripeInvoiceId,
        ]);

        // Foreign-provider row pre-existing with same provider_payment_id.
        // Without the lookup-side provider filter, updateOrCreate would
        // MATCH this row and overwrite its `provider` field as 'stripe'.
        $foreignPayment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'provider' => PaymentProviderCode::PayPal->value,
            'provider_payment_id' => $sharedPi,
            'status' => PaymentStatus::Pending,
            'amount' => '777.000',  // distinct so we can detect overwrite
            'fee' => '0.000',
            'net_amount' => '777.000',
            'currency' => 'EUR',
            'refunded_amount' => '0.000',
        ]);

        $controller = $this->app->make(StripeWebhookController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('handleInvoicePaid');
        $method->setAccessible(true);
        $method->invoke($controller, [
            'id' => $stripeInvoiceId,
            'subscription' => $stripeSubId,
            'payment_intent' => $sharedPi,
            'amount_paid' => 9900,
            'currency' => 'eur',
        ]);

        $foreignPayment->refresh();

        // Foreign-provider row must remain a PayPal row, untouched.
        $this->assertSame(
            PaymentProviderCode::PayPal->value,
            $foreignPayment->provider,
            'Foreign-provider row must NOT be matched by the Stripe lookup'
        );
        $this->assertSame(
            PaymentStatus::Pending,
            $foreignPayment->status,
            'Foreign-provider row must NOT be transitioned by Stripe handler'
        );
        $this->assertSame(
            '777.000',
            $foreignPayment->amount,
            'Foreign-provider row amount must NOT be overwritten'
        );

        // A new Stripe-provider row should exist for the same external id.
        $stripeRow = Payment::query()
            ->where('provider', PaymentProviderCode::Stripe->value)
            ->where('provider_payment_id', $sharedPi)
            ->sole();

        $this->assertSame(PaymentStatus::Succeeded, $stripeRow->status);
        $this->assertSame($invoice->id, $stripeRow->invoice_id);
        $this->assertNotSame($foreignPayment->id, $stripeRow->id);
    }

    public function test_stripe_invoice_payment_failed_handler_filters_by_provider_in_lookup(): void
    {
        // Mirror of test_stripe_invoice_paid_handler — same provider-filter
        // discipline applies to handleInvoicePaymentFailed at the same
        // controller. Asserts the FOREIGN row is never overwritten when
        // a Stripe-side payment_intent shares the foreign id.

        $stripeInvoiceId = 'in_test_failed_'.Str::random(8);
        $stripeSubId = 'sub_test_failed_'.Str::random(8);
        $sharedPi = 'pi_invoice_failed_filter_'.Str::random(8);

        $subscription = TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $this->seedPlan()->id,
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => 'monthly',
            'price' => '99.000',
            'currency' => 'EUR',
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->addDays(15),
            'stripe_subscription_id' => $stripeSubId,
        ]);

        Invoice::create([
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $subscription->id,
            'number' => 'INV-'.Str::random(6),
            'status' => 'pending',
            'subtotal' => '99.000',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '99.000',
            'amount_paid' => '0.000',
            'amount_due' => '99.000',
            'currency' => 'EUR',
            'invoice_date' => now(),
            'due_date' => now()->addDays(7),
            'stripe_invoice_id' => $stripeInvoiceId,
        ]);

        $foreignPayment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'provider' => PaymentProviderCode::PayPal->value,
            'provider_payment_id' => $sharedPi,
            'status' => PaymentStatus::Succeeded,  // distinct from "Failed" target
            'amount' => '555.000',
            'fee' => '0.000',
            'net_amount' => '555.000',
            'currency' => 'EUR',
            'refunded_amount' => '0.000',
            'paid_at' => now(),
        ]);

        $controller = $this->app->make(StripeWebhookController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('handleInvoicePaymentFailed');
        $method->setAccessible(true);
        $method->invoke($controller, [
            'id' => $stripeInvoiceId,
            'subscription' => $stripeSubId,
            'payment_intent' => $sharedPi,
            'amount_due' => 9900,
            'currency' => 'eur',
            'last_payment_error' => ['message' => 'Card declined'],
        ]);

        $foreignPayment->refresh();

        // Foreign-provider row must remain a PayPal Succeeded row.
        $this->assertSame(
            PaymentProviderCode::PayPal->value,
            $foreignPayment->provider,
            'Foreign-provider row must NOT be matched by the Stripe lookup'
        );
        $this->assertSame(
            PaymentStatus::Succeeded,
            $foreignPayment->status,
            'Foreign-provider row Succeeded status must NOT be overwritten as Failed'
        );
        $this->assertSame(
            '555.000',
            $foreignPayment->amount,
            'Foreign-provider row amount must NOT be overwritten (Codex round-2 NICE-TO-HAVE: symmetry with paid-path coverage)'
        );

        // A new Stripe-provider Failed row should exist.
        $stripeRow = Payment::query()
            ->where('provider', PaymentProviderCode::Stripe->value)
            ->where('provider_payment_id', $sharedPi)
            ->sole();

        $this->assertSame(PaymentStatus::Failed, $stripeRow->status);
        $this->assertNotSame($foreignPayment->id, $stripeRow->id);
        $this->assertSame('Card declined', $stripeRow->error_message);
    }

    /**
     * Seed a minimal Plan row that satisfies TenantSubscription's
     * non-null plan_id FK. The plan code is randomized per test so
     * RefreshDatabase + parallel test runs don't collide on the
     * unique index.
     */
    private function seedPlan(): Plan
    {
        return Plan::create([
            'code' => 'test-plan-'.Str::random(6),
            'name' => 'Test Plan',
            'limits' => [],
            'price_monthly' => '99.000',
            'price_yearly' => '999.000',
            'currency' => 'EUR',
            'trial_days' => 0,
            'is_active' => true,
            'is_public' => true,
            'display_order' => 1,
        ]);
    }
}
