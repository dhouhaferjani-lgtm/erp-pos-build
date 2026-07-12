<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Notifications;

use Illuminate\Notifications\Notification;

final class TreasuryAlertNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private readonly string $alertType,
        private readonly array $data,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->alertType;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }
}
