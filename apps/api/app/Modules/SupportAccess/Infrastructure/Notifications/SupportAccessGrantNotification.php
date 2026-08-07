<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Infrastructure\Notifications;

use App\Modules\SupportAccess\Application\DTOs\SupportAccessNotificationData;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class SupportAccessGrantNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly SupportAccessNotificationData $data) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->data->toArray();
    }
}
