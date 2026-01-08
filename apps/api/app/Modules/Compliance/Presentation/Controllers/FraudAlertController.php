<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\FraudAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller for managing fraud alerts.
 *
 * Provides admin interface for:
 * - Viewing fraud alerts
 * - Investigating suspicious patterns
 * - Assigning alerts to admins
 * - Dismissing false positives
 * - Resolving confirmed fraud cases
 */
class FraudAlertController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List fraud alerts with filtering and pagination.
     *
     * @group Compliance
     *
     * @subgroup Fraud Detection
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $query = FraudAlert::where('company_id', $companyId)
            ->with(['user', 'assignedUser']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by severity
        if ($request->has('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        // Filter by alert type
        if ($request->has('alert_type')) {
            $query->where('alert_type', $request->input('alert_type'));
        }

        // Filter by date range
        if ($request->has('detected_after')) {
            $query->where('detected_at', '>=', $request->input('detected_after'));
        }

        if ($request->has('detected_before')) {
            $query->where('detected_at', '<=', $request->input('detected_before'));
        }

        // Order by most recent first
        $query->orderByDesc('detected_at');

        $alerts = $query->paginate(20);

        return response()->json($alerts);
    }

    /**
     * Get single fraud alert with full details.
     *
     * @group Compliance
     *
     * @subgroup Fraud Detection
     */
    public function show(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $alert = FraudAlert::where('company_id', $companyId)
            ->with(['user', 'assignedUser', 'company'])
            ->findOrFail($id);

        return response()->json(['data' => $alert]);
    }

    /**
     * Assign fraud alert to admin for investigation.
     *
     * @group Compliance
     *
     * @subgroup Fraud Detection
     */
    public function assign(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $validated = $request->validate([
            'assigned_to' => 'required|uuid|exists:users,id',
        ]);

        $alert = FraudAlert::where('company_id', $companyId)->findOrFail($id);

        $alert->assignTo($validated['assigned_to']);

        return response()->json([
            'data' => $alert->fresh(['user', 'assignedUser']),
            'message' => 'Alert assigned successfully',
        ]);
    }

    /**
     * Dismiss fraud alert as false positive.
     *
     * @group Compliance
     *
     * @subgroup Fraud Detection
     */
    public function dismiss(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $validated = $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $alert = FraudAlert::where('company_id', $companyId)->findOrFail($id);

        $alert->dismiss($validated['notes']);

        return response()->json([
            'data' => $alert->fresh(['user', 'assignedUser']),
            'message' => 'Alert dismissed',
        ]);
    }

    /**
     * Resolve fraud alert (confirmed fraud case).
     *
     * @group Compliance
     *
     * @subgroup Fraud Detection
     */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $validated = $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $alert = FraudAlert::where('company_id', $companyId)->findOrFail($id);

        $alert->resolve($validated['notes']);

        return response()->json([
            'data' => $alert->fresh(['user', 'assignedUser']),
            'message' => 'Alert resolved',
        ]);
    }

    /**
     * Get fraud alert statistics for dashboard.
     *
     * @group Compliance
     *
     * @subgroup Fraud Detection
     */
    public function statistics(): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $stats = [
            'total_alerts' => FraudAlert::where('company_id', $companyId)->count(),
            'open_alerts' => FraudAlert::where('company_id', $companyId)
                ->where('status', 'open')
                ->count(),
            'investigating' => FraudAlert::where('company_id', $companyId)
                ->where('status', 'investigating')
                ->count(),
            'dismissed' => FraudAlert::where('company_id', $companyId)
                ->where('status', 'dismissed')
                ->count(),
            'resolved' => FraudAlert::where('company_id', $companyId)
                ->where('status', 'resolved')
                ->count(),
            'by_severity' => [
                'critical' => FraudAlert::where('company_id', $companyId)
                    ->where('severity', 'critical')
                    ->where('status', '!=', 'resolved')
                    ->count(),
                'warning' => FraudAlert::where('company_id', $companyId)
                    ->where('severity', 'warning')
                    ->where('status', '!=', 'resolved')
                    ->count(),
                'info' => FraudAlert::where('company_id', $companyId)
                    ->where('severity', 'info')
                    ->where('status', '!=', 'resolved')
                    ->count(),
            ],
            'recent_alerts' => FraudAlert::where('company_id', $companyId)
                ->where('detected_at', '>=', now()->subDays(7))
                ->count(),
        ];

        return response()->json(['data' => $stats]);
    }
}
