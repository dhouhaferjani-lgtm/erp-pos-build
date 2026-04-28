<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Contracts;

use App\Modules\Compliance\Domain\FraudAlert;

interface NotificationDispatcherInterface
{
    /**
     * Dispatch fraud-alert notifications to configured company recipients.
     */
    public function dispatchToFraudEmails(FraudAlert $alert): void;
}
