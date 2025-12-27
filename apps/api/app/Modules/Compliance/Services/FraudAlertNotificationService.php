<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Domain\CompanyFraudSettings;
use App\Modules\Compliance\Domain\FraudAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Service for sending fraud alert notifications to company admins.
 *
 * Notifications are sent via:
 * - Email (to configured addresses in company fraud settings)
 * - Application logs (for monitoring/alerting systems)
 *
 * Future: Slack webhooks, SMS, in-app notifications
 */
final class FraudAlertNotificationService
{
    /**
     * Notify admins about a new fraud alert.
     */
    public function notifyAdmins(FraudAlert $alert): void
    {
        $settings = CompanyFraudSettings::where('company_id', $alert->company_id)->first();

        if ($settings === null || ! $settings->alert_enabled) {
            return; // Alerts disabled for this company
        }

        // Log to application logs (for monitoring systems)
        $this->logAlert($alert);

        // Send email notifications
        if ($settings->alert_emails !== null && count($settings->alert_emails) > 0) {
            $this->sendEmailNotifications($alert, $settings->alert_emails);
        }

        // Future: Slack webhook
        // $this->sendSlackNotification($alert);

        // Future: In-app notification
        // $this->createInAppNotification($alert);
    }

    /**
     * Log fraud alert to application logs.
     */
    private function logAlert(FraudAlert $alert): void
    {
        $severity = match ($alert->severity) {
            'critical' => 'critical',
            'warning' => 'warning',
            default => 'info',
        };

        Log::log($severity, 'Fraud alert created', [
            'alert_id' => $alert->id,
            'company_id' => $alert->company_id,
            'user_id' => $alert->user_id,
            'alert_type' => $alert->alert_type,
            'severity' => $alert->severity,
            'description' => $alert->description,
            'flagged_products_count' => $alert->flagged_products !== null ? count($alert->flagged_products) : 0,
        ]);
    }

    /**
     * Send email notifications to configured admins.
     *
     * @param array<string> $recipients
     */
    private function sendEmailNotifications(FraudAlert $alert, array $recipients): void
    {
        foreach ($recipients as $email) {
            try {
                Mail::send(
                    'emails.fraud-alert',
                    [
                        'alert' => $alert,
                        'user' => $alert->user,
                        'company' => $alert->company,
                    ],
                    function ($message) use ($email, $alert) {
                        $message->to($email)
                            ->subject("[{$alert->severity}] Fraud Alert: {$alert->alert_type}");
                    }
                );

                Log::info('Fraud alert email sent', [
                    'alert_id' => $alert->id,
                    'recipient' => $email,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to send fraud alert email', [
                    'alert_id' => $alert->id,
                    'recipient' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
