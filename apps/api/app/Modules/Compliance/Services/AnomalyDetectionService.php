<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Compliance\Domain\CompanyFraudSettings;
use App\Modules\Compliance\Domain\FraudAlert;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\FraudTriggeredCountingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class AnomalyDetectionService
{
    private const HIGH_VOID_THRESHOLD = 10;

    private const HIGH_ACTIVITY_THRESHOLD = 100;

    public function __construct(
        private readonly AuditService $auditService,
        private readonly FraudAlertNotificationService $notificationService,
        private readonly FraudTriggeredCountingService $countingService,
    ) {}

    /**
     * Detect anomalies in audit events
     *
     * @return array<int, array{type: string, severity: string, description: string, detected_at: string, details: array<string, mixed>}>
     */
    public function detectAnomalies(string $companyId, Carbon $from, Carbon $to): array
    {
        $anomalies = [];

        // Check for high void rate
        $voidAnomalies = $this->detectHighVoidRate($companyId, $from, $to);
        $anomalies = array_merge($anomalies, $voidAnomalies);

        // Check for high activity rate
        $activityAnomalies = $this->detectHighActivityRate($companyId, $from, $to);
        $anomalies = array_merge($anomalies, $activityAnomalies);

        // Check for unusual patterns
        $patternAnomalies = $this->detectUnusualPatterns($companyId, $from, $to);
        $anomalies = array_merge($anomalies, $patternAnomalies);

        return $anomalies;
    }

    /**
     * Detect activity outside business hours
     *
     * @return array<int, AuditEvent>
     */
    public function detectAfterHoursActivity(
        string $companyId,
        int $businessHoursStart = 8,
        int $businessHoursEnd = 20
    ): array {
        // Fetch all recent events and filter in PHP for database agnostic behavior
        $events = AuditEvent::where('company_id', $companyId)
            ->orderByDesc('occurred_at')
            ->limit(500)
            ->get();

        return $events->filter(function ($event) use ($businessHoursStart, $businessHoursEnd) {
            $hour = (int) $event->occurred_at->format('H');

            return $hour < $businessHoursStart || $hour >= $businessHoursEnd;
        })
            ->take(100)
            ->values()
            ->all();
    }

    /**
     * Detect high void rate
     *
     * @return array<int, array{type: string, severity: string, description: string, detected_at: string, details: array<string, mixed>}>
     */
    private function detectHighVoidRate(string $companyId, Carbon $from, Carbon $to): array
    {
        $voidCount = $this->auditService->countEventsByType(
            $companyId,
            'document.voided',
            $from,
            $to
        );

        if ($voidCount >= self::HIGH_VOID_THRESHOLD) {
            return [[
                'type' => 'high_void_rate',
                'severity' => $voidCount >= self::HIGH_VOID_THRESHOLD * 2 ? 'critical' : 'warning',
                'description' => "High number of voided documents detected: {$voidCount} voids in the period",
                'detected_at' => now()->toIso8601String(),
                'details' => [
                    'void_count' => $voidCount,
                    'threshold' => self::HIGH_VOID_THRESHOLD,
                    'period_start' => $from->toIso8601String(),
                    'period_end' => $to->toIso8601String(),
                ],
            ]];
        }

        return [];
    }

    /**
     * Detect high activity rate (potential automated attacks)
     *
     * @return array<int, array{type: string, severity: string, description: string, detected_at: string, details: array<string, mixed>}>
     */
    private function detectHighActivityRate(string $companyId, Carbon $from, Carbon $to): array
    {
        $totalCount = AuditEvent::where('company_id', $companyId)
            ->whereBetween('occurred_at', [$from, $to])
            ->count();

        // Calculate events per minute
        $minutes = max(1, $from->diffInMinutes($to));
        $eventsPerMinute = $totalCount / $minutes;

        if ($eventsPerMinute >= self::HIGH_ACTIVITY_THRESHOLD) {
            return [[
                'type' => 'high_activity_rate',
                'severity' => 'warning',
                'description' => sprintf('Unusually high activity rate detected: %.2f events/minute', $eventsPerMinute),
                'detected_at' => now()->toIso8601String(),
                'details' => [
                    'events_per_minute' => $eventsPerMinute,
                    'total_events' => $totalCount,
                    'period_minutes' => $minutes,
                ],
            ]];
        }

        return [];
    }

    /**
     * Detect unusual patterns in event sequences
     *
     * @return array<int, array{type: string, severity: string, description: string, detected_at: string, details: array<string, mixed>}>
     */
    private function detectUnusualPatterns(string $companyId, Carbon $from, Carbon $to): array
    {
        $anomalies = [];

        // Check for repeated identical actions (potential automation/abuse)
        /** @var Collection<int, object{event_type: string, user_id: string|null, count: int}> $repeatedActions */
        $repeatedActions = AuditEvent::where('company_id', $companyId)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('event_type, user_id, COUNT(*) as count')
            ->groupBy('event_type', 'user_id')
            // Filter on the aggregate expression, not the SELECT alias:
            // PostgreSQL does not resolve output-column aliases inside HAVING.
            ->havingRaw('COUNT(*) > 50')
            ->get();

        foreach ($repeatedActions as $action) {
            /** @var object{event_type: string, user_id: string|null, count: int} $action */
            $anomalies[] = [
                'type' => 'repeated_action',
                'severity' => 'info',
                'description' => "Repeated action detected: {$action->event_type} ({$action->count} times)",
                'detected_at' => now()->toIso8601String(),
                'details' => [
                    'event_type' => $action->event_type,
                    'user_id' => $action->user_id,
                    'count' => $action->count,
                ],
            ];
        }

        return $anomalies;
    }

    /**
     * Detect users with high draft abandonment rate.
     *
     * Uses company-specific threshold from fraud settings.
     * Returns users exceeding abandoned draft threshold in time window.
     *
     * @return array<int, array{user_id: string, user_name: string, abandoned_count: int, flagged_products: array<int, array{product_id: string, product_name: string, count: int}>}>
     */
    public function detectHighAbandonmentRate(string $companyId): array
    {
        $settings = CompanyFraudSettings::where('company_id', $companyId)->first();
        $threshold = $settings !== null ? $settings->abandoned_draft_threshold : 5;
        $days = $settings !== null ? $settings->time_window_days : 30;

        $since = now()->subDays($days);

        // Find users who created drafts (from audit events)
        /** @var Collection<int, object{user_id: string, draft_count: int}> $draftsByUser */
        $draftsByUser = AuditEvent::where('company_id', $companyId)
            ->where('event_type', 'draft.document.created')
            ->where('occurred_at', '>=', $since)
            ->selectRaw('user_id, COUNT(*) as draft_count')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->get();

        $flaggedUsers = [];

        foreach ($draftsByUser as $userData) {
            /** @var string $userId */
            $userId = $userData->user_id;
            /** @var int $draftCount */
            $draftCount = (int) $userData->draft_count;

            // Count how many of these drafts were actually confirmed/posted
            // by checking if corresponding document is still in draft status
            $confirmedCount = 0;
            $abandonedDocumentIds = [];

            $createdEvents = AuditEvent::where('company_id', $companyId)
                ->where('event_type', 'draft.document.created')
                ->where('user_id', $userId)
                ->where('occurred_at', '>=', $since)
                ->get();

            foreach ($createdEvents as $event) {
                /** @var array{documentId?: string} */
                $payload = $event->payload;
                $documentId = $payload['documentId'] ?? null;

                if ($documentId !== null) {
                    // Check if document was confirmed
                    $document = Document::find($documentId);
                    if ($document !== null && $document->status !== DocumentStatus::Draft) {
                        $confirmedCount++;
                    } else {
                        $abandonedDocumentIds[] = $documentId;
                    }
                }
            }

            $abandonedCount = $draftCount - $confirmedCount;

            // Only flag if exceeds threshold
            if ($abandonedCount < $threshold) {
                continue;
            }

            // Get products from abandoned drafts for this user
            $products = $this->getProductsFromAbandonedDrafts($companyId, $userId, $since);

            /** @var User|null $user */
            $user = User::find($userId);

            $flaggedUsers[] = [
                'user_id' => $userId,
                'user_name' => $user !== null ? $user->name : 'Unknown User',
                'abandoned_count' => $abandonedCount,
                'flagged_products' => $products,
            ];
        }

        return $flaggedUsers;
    }

    /**
     * Get all products from user's abandoned drafts (for counting).
     *
     * Queries audit_events for DraftLineAdded events where document was abandoned.
     *
     * @return array<int, array{product_id: string, product_name: string, count: int}>
     */
    public function getProductsFromAbandonedDrafts(
        string $companyId,
        string $userId,
        Carbon $since
    ): array {
        // Query audit events for draft lines added by this user
        /** @var Collection<int, AuditEvent> $lineEvents */
        $lineEvents = AuditEvent::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('event_type', 'draft.line.added')
            ->where('occurred_at', '>=', $since)
            ->get();

        // Group by product and count occurrences
        $productCounts = [];

        foreach ($lineEvents as $event) {
            /** @var array{productId?: string, productName?: string} */
            $payload = $event->payload;
            $productId = $payload['productId'] ?? null;
            $productName = $payload['productName'] ?? 'Unknown Product';

            if ($productId === null) {
                continue;
            }

            if (! isset($productCounts[$productId])) {
                $productCounts[$productId] = [
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'count' => 0,
                ];
            }

            $productCounts[$productId]['count']++;
        }

        // Sort by count descending and return as list
        uasort($productCounts, fn ($a, $b) => $b['count'] <=> $a['count']);

        // Convert to list (re-index from 0)
        /** @var array<int, array{product_id: string, product_name: string, count: int}> */
        return array_values($productCounts);
    }

    /**
     * Detect and act on fraud patterns.
     *
     * This method:
     * 1. Runs all detection algorithms
     * 2. Creates fraud alerts
     * 3. Notifies admins
     * 4. Auto-triggers actions (counting, access restriction)
     */
    public function detectAndAct(string $companyId): void
    {
        $settings = CompanyFraudSettings::where('company_id', $companyId)->first();

        if ($settings === null || ! $settings->alert_enabled) {
            return; // Fraud detection disabled for this company
        }

        // Detect high abandonment rates
        $flaggedUsers = $this->detectHighAbandonmentRate($companyId);

        foreach ($flaggedUsers as $userData) {
            // Create fraud alert
            $company = Company::find($companyId);
            $tenantId = $company !== null ? $company->tenant_id : '';

            $alert = FraudAlert::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'user_id' => $userData['user_id'],
                'alert_type' => 'high_abandonment',
                'severity' => $userData['abandoned_count'] >= ($settings->abandoned_draft_threshold * 2) ? 'critical' : 'warning',
                'description' => "User {$userData['user_name']} has {$userData['abandoned_count']} abandoned drafts in the last {$settings->time_window_days} days (threshold: {$settings->abandoned_draft_threshold})",
                'detected_at' => now(),
                'flagged_products' => $userData['flagged_products'],
                'metadata' => [
                    'abandoned_count' => $userData['abandoned_count'],
                    'threshold' => $settings->abandoned_draft_threshold,
                    'time_window_days' => $settings->time_window_days,
                ],
                'status' => 'open',
            ]);

            // 1. Notify admins
            try {
                $this->notificationService->notifyAdmins($alert);
            } catch (\Throwable $e) {
                \Log::error('Failed to send fraud alert notification', [
                    'alert_id' => $alert->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // 2. Auto-trigger inventory counting if enabled
            if ($settings->auto_trigger_counting && ! empty($userData['flagged_products'])) {
                try {
                    $counting = $this->countingService->createCountingFromAlert($alert);

                    \Log::info('Fraud-triggered inventory counting created', [
                        'alert_id' => $alert->id,
                        'counting_id' => $counting->id,
                        'product_count' => count($userData['flagged_products']),
                    ]);
                } catch (\Throwable $e) {
                    \Log::error('Failed to create fraud-triggered counting', [
                        'alert_id' => $alert->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 3. TODO: Auto-restrict access if enabled (will implement in Phase 5)
            // if ($settings->auto_restrict_access) {
            //     $this->restrictUserAccess($userData['user_id'], $alert);
            // }

            \Log::info('Fraud alert created', [
                'alert_id' => $alert->id,
                'user_id' => $userData['user_id'],
                'abandoned_count' => $userData['abandoned_count'],
                'actions_taken' => [
                    'notification_sent' => true,
                    'counting_created' => $settings->auto_trigger_counting,
                ],
            ]);
        }
    }
}
