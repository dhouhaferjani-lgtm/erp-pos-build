<?php

declare(strict_types=1);

namespace App\Modules\Billing\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\Services\InvoiceService;
use App\Modules\Billing\Application\Services\PaymentProviderManager;
use App\Modules\Billing\Domain\Enums\PaymentStatus;
use App\Modules\Billing\Domain\Invoice;
use App\Modules\Billing\Domain\Payment;
use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Tenant\Domain\Tenant;
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
    public function dashboard(): JsonResponse
    {
        $stats = [
            'mrr' => $this->calculateMRR(),
            'arr' => $this->calculateMRR() * 12,
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
    public function providers(): JsonResponse
    {
        $providers = $this->providerManager->getProviderStatus();

        return response()->json(['data' => $providers]);
    }

    /**
     * List all plans.
     */
    public function listPlans(): JsonResponse
    {
        $plans = Plan::orderBy('display_order')->get();

        return response()->json(['data' => $plans]);
    }

    /**
     * List all subscriptions.
     */
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
    public function getSubscription(string $id): JsonResponse
    {
        $subscription = TenantSubscription::with(['tenant', 'plan', 'invoices'])
            ->findOrFail($id);

        return response()->json(['data' => $subscription]);
    }

    /**
     * List all invoices.
     */
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
    public function getInvoice(string $id): JsonResponse
    {
        $invoice = Invoice::with(['tenant', 'items', 'payments', 'subscription.plan'])
            ->findOrFail($id);

        return response()->json(['data' => $invoice]);
    }

    /**
     * Download invoice PDF.
     */
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
    public function getPayment(string $id): JsonResponse
    {
        $payment = Payment::with(['tenant', 'invoice', 'refunds', 'recorder'])
            ->findOrFail($id);

        return response()->json(['data' => $payment]);
    }

    /**
     * Record a manual payment.
     */
    public function recordPayment(Request $request): JsonResponse
    {
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

        return DB::transaction(function () use ($validated, $request): JsonResponse {
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
                'recorded_by' => $request->user()->id,
                'paid_at' => now(),
                'metadata' => [
                    'notes' => $validated['notes'] ?? null,
                    'recorded_manually' => true,
                ],
            ]);

            // Update invoice if linked
            if ($payment->invoice_id) {
                $payment->invoice->recordPayment((float) $payment->amount);
            }

            return response()->json(['data' => $payment->load(['tenant', 'invoice'])], 201);
        });
    }

    /**
     * Process a refund.
     */
    public function refundPayment(Request $request, string $id): JsonResponse
    {
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

        $amount = $validated['amount'] ?? $payment->getRefundableAmount();

        // If online provider, process refund
        if (! $payment->isManual()) {
            $provider = $this->providerManager->provider($payment->provider);
            $result = $provider->refund(
                $payment->provider_payment_id,
                new \App\Modules\Billing\Domain\ValueObjects\Money($amount, $payment->currency)
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
            'initiated_by' => $request->user()->id,
            'refunded_at' => now(),
        ]);

        // Update payment's refunded amount
        $payment->recordRefund($amount);

        return response()->json(['data' => $refund]);
    }

    /**
     * Update subscription status.
     */
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
            };
        }

        if (isset($validated['notes'])) {
            $subscription->update(['notes' => $validated['notes']]);
        }

        return response()->json(['data' => $subscription->fresh(['tenant', 'plan'])]);
    }

    /**
     * Calculate Monthly Recurring Revenue.
     */
    private function calculateMRR(): float
    {
        $monthly = TenantSubscription::whereIn('status', ['active', 'trial', 'past_due'])
            ->where('billing_cycle', 'monthly')
            ->sum('price');

        $yearly = TenantSubscription::whereIn('status', ['active', 'trial', 'past_due'])
            ->where('billing_cycle', 'yearly')
            ->sum('price');

        return (float) $monthly + ((float) $yearly / 12);
    }
}
