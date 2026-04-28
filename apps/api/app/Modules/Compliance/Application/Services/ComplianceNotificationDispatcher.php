<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Services;

use App\Modules\Compliance\Application\Contracts\NotificationDispatcherInterface;
use App\Modules\Compliance\Domain\FraudAlert;
use App\Modules\Compliance\Services\FraudAlertNotificationService;

/**
 * Application-layer wrapper around FraudAlertNotificationService.
 *
 * Implements NotificationDispatcherInterface so callers can depend on the
 * interface for testing (the concrete class is final and cannot be subclassed
 * by test frameworks like Mockery).
 */
final class ComplianceNotificationDispatcher implements NotificationDispatcherInterface
{
    public function __construct(
        private readonly FraudAlertNotificationService $notifier,
    ) {}

    /**
     * Dispatch fraud-alert notifications to configured company recipients.
     */
    public function dispatchToFraudEmails(FraudAlert $alert): void
    {
        $this->notifier->notifyAdmins($alert);
    }
}
