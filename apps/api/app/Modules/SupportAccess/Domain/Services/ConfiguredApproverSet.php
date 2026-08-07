<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Services;

use App\Models\Enums\SuperAdminRole;
use App\Models\SuperAdmin;
use Illuminate\Contracts\Config\Repository;

final class ConfiguredApproverSet
{
    public function __construct(private readonly Repository $config) {}

    public function allows(SuperAdmin $approver): bool
    {
        if (! $this->config->get('support_access.four_eyes.enabled', true)) {
            return false;
        }

        $configured = $this->config->get('support_access.four_eyes.approver_emails');
        if (! is_array($configured) || $configured === []) {
            return false;
        }

        $emails = [];
        foreach ($configured as $email) {
            if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }

            $emails[] = strtolower($email);
        }

        $current = $approver->fresh();

        return $current !== null
            && $current->is_active
            && $current->role === SuperAdminRole::SupportApprover->value
            && in_array(strtolower($current->email), $emails, true);
    }
}
