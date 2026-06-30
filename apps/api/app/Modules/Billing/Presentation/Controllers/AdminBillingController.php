<?php

declare(strict_types=1);

namespace App\Modules\Billing\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Modules\Billing\Application\Services\InvoiceService;
use App\Modules\Billing\Application\Services\PaymentProviderManager;
use App\Modules\Billing\Domain\Enums\PaymentStatus;
use App\Modules\Billing\Domain\Invoice;
use App\Modules\Billing\Domain\Payment;
use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Billing\Domain\ValueObjects\Money;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Admin controller for billing management.
 */
final class AdminBillingController extends Controller
{
    public function __construct(
        private readonly PaymentProviderManager $providerManager,
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * Get billing dashboard stats.
     */
    #[CrossTenantRoute(reason: 'Super-admin billing dashboard: aggregates fleet-wide MRR/ARR/revenue, active+trial+past_due subscription counts, and outstanding/overdue invoice totals across all tenants for platform finance operations; mounted under auth:sanctum-admin + super_admin (EnsureSuperAdmin).')]
    public function dashboard(): JsonResponse
    {
        $mrr = $this->calculateMRR();
        $stats = [
            'mrr' => $mrr,
            'arr' => bcmul($mrr, '12', 2),
            'active_subscriptions' => TenantSubscription::whereIn('status', ['active', 'trial'])->count(),
            'trial_subscriptions' => TenantSubscription::where('status', 'trial')->count(),
            'past_due_subscriptions' => TenantSubscription::where('status', 'past_due')->count(),
            'revenue_this_month' => Payment::where('status', PaymentStatus::Succeeded)
                ->whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('amount'),
            'outstanding_invoices' => Invoice::whereIn('status', ['pending', 'sent', 'overdue'])
                ->sum('amount_due'),
            'overdue_invoices_count' => Invoice::where('status', 'overdue')->count(),
        ];

        return response()->json(['data' => $stats]);
    }

    /**
     * Get configured payment providers.
     */
    #[CrossTenantRoute(reason: 'Super-admin payment-provider config view: reads PaymentProviderManager::getProviderStatus — payment provider configuration (Stripe/PayPal/etc.) is fleet-wide platform infrastructure, not per-tenant.')]
    public function providers(): JsonResponse
    {
        $providers = $this->providerManager->getProviderStatus();

        return response()->json(['data' => $providers]);
    }

    /**
     * List all plans.
     */
    #[CrossTenantRoute(reason: 'Super-admin plan catalog: lists all subscription plans from the platform-level Plan catalog ordered by display_order; not tenant-scoped by design — every tenant chooses from the same shared plan catalog.')]
    public function listPlans(): JsonResponse
    {
        $plans = Plan::orderBy('display_order')->get();

        return response()->json(['data' => $plans]);
    }

    /**
     * List all subscriptions.
     */
    #[CrossTenantRoute(reason: 'Super-admin subscription directory: lists subscriptions across all tenants with status + plan_id filters for fleet-wide billing operations and renewal management; super_admin middleware gated.')]
    public function listSubscriptions(Request $request): JsonResponse
    {
        $query = TenantSubscription::with(['tenant', 'plan']);

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($planId = $request->get('plan_id')) {
            $query->where('plan_id', $planId);
        }

        $subscriptions = $query
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json($subscriptions);
    }

    /**
     * Get subscription details.
     */
    #[CrossTenantRoute(reason: 'Super-admin subscription detail view: reads any tenant\'s subscription by id with tenant + plan + invoices relations for fleet-wide support and dispute resolution; super_admin middleware gated.')]
    public function getSubscription(string $id): JsonResponse
    {
        $subscription = TenantSubscription::with(['tenant', 'plan', 'invoices'])
            ->findOrFail($id);

        return response()->json(['data' => $subscription]);
    }

    /**
     * List all invoices.
     */
    #[CrossTenantRoute(reason: 'Super-admin invoice directory: lists invoices across all tenants with status + tenant_id filters for fleet-wide billing reconciliation; super_admin middleware gated.')]
    public function listInvoices(Request $request): JsonResponse
    {
        $query = Invoice::with(['tenant', 'subscription.plan']);

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($tenantId = $request->get('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        $invoices = $query
            ->orderBy('invoice_date', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json($invoices);
    }

    /**
     * Get invoice details.
     */
    #[CrossTenantRoute(reason: 'Super-admin invoice detail view: reads any tenant\'s invoice by id with line items, payments, and subscription/plan join for fleet-wide billing operations and dispute investigation. Closes inventory row api.unmapped.020 (unscoped Invoice::with()->findOrFail; visitor honors this attribute).')]
    public function getInvoice(string $id): JsonResponse
    {
        $invoice = Invoice::with(['tenant', 'items', 'payments', 'subscription.plan'])
            ->findOrFail($id);

        return response()->json(['data' => $invoice]);
    }

    /**
     * Download invoice PDF.
     */
    #[CrossTenantRoute(reason: 'Super-admin invoice PDF download: generates and serves a PDF for any tenant\'s invoice via InvoiceService::generatePdf for the billing console. Closes inventory row api.unmapped.021.')]
    public function downloadInvoice(string $id): Response
    {
        $invoice = Invoice::findOrFail($id);
        $pdf = $this->invoiceService->generatePdf($invoice);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$invoice->number}.pdf\"",
        ]);
    }

    /**
     * Create a manual invoice.
     */
    #[CrossTenantRoute(reason: 'Super-admin manual invoice creation: creates an invoice for any tenant via InvoiceService::createManualInvoice (out-of-band charges, contractual adjustments) outside the automated subscription billing flow; super_admin middleware gated.')]
    public function createInvoice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|uuid|exists:tenants,id',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.amount' => 'required|numeric|min:0',
            'items.*.quantity' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'due_date' => 'nullable|date|after:today',
        ]);

        /** @var Tenant $tenant */
        $tenant = Tenant::findOrFail($validated['tenant_id']);

        $invoice = $this->invoiceService->createManualInvoice(
            tenant: $tenant,
            items: $validated['items'],
            notes: $validated['notes'] ?? null,
            dueDate: isset($validated['due_date']) ? new \DateTime($validated['due_date']) : null,
        );

        return response()->json(['data' => $invoice], 201);
    }

    /**
     * List all payments.
     */
    #[CrossTenantRoute(reason: 'Super-admin payment directory: lists payments across all tenants with status + provider + tenant_id filters for fleet-wide finance reconciliation; super_admin middleware gated.')]
    public function listPayments(Request $request): JsonResponse
    {
        $query = Payment::with(['tenant', 'invoice']);

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($provider = $request->get('provider')) {
            $query->where('provider', $provider);
        }

        if ($tenantId = $request->get('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        $payments = $query
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json($payments);
    }

    /**
     * Get payment details.
     */
    #[CrossTenantRoute(reason: 'Super-admin payment detail view: reads any tenant\'s payment by id with refunds + recorder relations for fleet-wide dispute investigation. Closes inventory row api.unmapped.022.')]
    public function getPayment(string $id): JsonResponse
    {
        $payment = Payment::with(['tenant', 'invoice', 'refunds', 'recorder'])
            ->findOrFail($id);

        return response()->json(['data' => $payment]);
    }

    /**
     * Record a manual payment.
     */
    #[CrossTenantRoute(reason: 'Super-admin manual payment record: records an out-of-band payment (manual / bank_transfer / cash / check) on any tenant\'s invoice; recorded_by stamps the super-admin\'s id and metadata.recorded_manually=true marks the audit shape.')]
    public function recordPayment(Request $request): JsonResponse
    {
        /** @var SuperAdmin $admin */
        $admin = $request->user();

        $validated = $request->validate([
            'tenant_id' => 'required|uuid|exists:tenants,id',
            'invoice_id' => 'nullable|uuid|exists:billing_invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|size:3',
            'provider' => 'required|string|in:manual,bank_transfer,cash,check',
            'reference_number' => 'nullable|string|max:100',
            'payment_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $admin): JsonResponse {
            $payment = Payment::create([
                'tenant_id' => $validated['tenant_id'],
                'invoice_id' => $validated['invoice_id'] ?? null,
                'provider' => $validated['provider'],
                'provider_payment_id' => 'manual_'.uniqid('', true),
                'status' => PaymentStatus::Succeeded,
                'amount' => $validated['amount'],
                'fee' => 0,
                'net_amount' => $validated['amount'],
                'currency' => $validated['currency'] ?? 'EUR',
                'payment_method_type' => $validated['provider'],
                'reference_number' => $validated['reference_number'] ?? null,
                'payment_date' => $validated['payment_date'] ?? now(),
                'recorded_by' => $admin->id,
                'paid_at' => now(),
                'metadata' => [
                    'notes' => $validated['notes'] ?? null,
                    'recorded_manually' => true,
                ],
            ]);

            // Update invoice if linked
            if ($payment->invoice_id && $payment->invoice !== null) {
                /** @var numeric-string $paidAmount */
                $paidAmount = (string) $payment->amount;
                $payment->invoice->recordPayment($paidAmount);
            }

            return response()->json(['data' => $payment->load(['tenant', 'invoice'])], 201);
        });
    }

    /**
     * Process a refund.
     */
    #[CrossTenantRoute(reason: 'Super-admin payment refund: processes a refund (full or partial) on any tenant\'s payment; routes through PaymentProviderManager for online providers (Stripe/etc.) and records a refund row with initiated_by stamping the super-admin id. Closes inventory row api.unmapped.023.')]
    public function refundPayment(Request $request, string $id): JsonResponse
    {
        /** @var SuperAdmin $admin */
        $admin = $request->user();

        $payment = Payment::findOrFail($id);

        if (! $payment->isRefundable()) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_REFUNDABLE',
                    'message' => 'This payment cannot be refunded',
                ],
            ], 422);
        }

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01|max:'.$payment->getRefundableAmount(),
            'reason' => 'nullable|string|max:255',
        ]);

        /** @var numeric-string $amount */
        $amount = (string) ($validated['amount'] ?? $payment->getRefundableAmount());

        // If online provider, process refund
        if (! $payment->isManual()) {
            $provider = $this->providerManager->provider($payment->provider);
            $result = $provider->refund(
                $payment->provider_payment_id ?? '',
                new Money($amount, $payment->currency)
            );

            if (! $result->success) {
                return response()->json([
                    'error' => [
                        'code' => $result->errorCode ?? 'REFUND_FAILED',
                        'message' => $result->message,
                    ],
                ], 422);
            }
        }

        // Record refund
        $refund = $payment->refunds()->create([
            'provider_refund_id' => $payment->isManual() ? null : ($result->paymentId ?? null),
            'status' => PaymentStatus::Succeeded,
            'amount' => $amount,
            'currency' => $payment->currency,
            'reason' => $validated['reason'] ?? null,
            'initiated_by' => $admin->id,
            'refunded_at' => now(),
        ]);

        $payment->recordRefund($amount);

        return response()->json(['data' => $refund]);
    }

    /**
     * Update subscription status.
     */
    #[CrossTenantRoute(reason: 'Super-admin subscription manual edit: changes status (active/paused/cancelled) or appends admin notes on any tenant\'s subscription via the domain methods (activate/pause/cancel); super_admin middleware gated.')]
    public function updateSubscription(Request $request, string $id): JsonResponse
    {
        $subscription = TenantSubscription::findOrFail($id);

        $validated = $request->validate([
            'status' => 'nullable|string|in:active,paused,cancelled',
            'notes' => 'nullable|string',
        ]);

        if (isset($validated['status'])) {
            match ($validated['status']) {
                'active' => $subscription->activate(),
                'paused' => $subscription->pause(),
                'cancelled' => $subscription->cancel(true),
                default => null,
            };
        }

        if (isset($validated['notes'])) {
            $subscription->update(['notes' => $validated['notes']]);
        }

        return response()->json(['data' => $subscription->fresh(['tenant', 'plan'])]);
    }

    /**
     * Calculate Monthly Recurring Revenue as a numeric-string.
     *
     * yearly / 12 computed at scale+1=3 to preserve sub-cent precision;
     * final sum normalised at scale 2 (EUR billing).
     *
     * @return numeric-string
     */
    private function calculateMRR(): string
    {
        /** @var numeric-string $monthly */
        $monthly = (string) TenantSubscription::whereIn('status', ['active', 'trial', 'past_due'])
            ->where('billing_cycle', 'monthly')
            ->sum('price');

        /** @var numeric-string $yearly */
        $yearly = (string) TenantSubscription::whereIn('status', ['active', 'trial', 'past_due'])
            ->where('billing_cycle', 'yearly')
            ->sum('price');

        $monthlyFromYearly = bcdiv($yearly, '12', 3);

        return bcadd($monthly, $monthlyFromYearly, 2);
    }
}
