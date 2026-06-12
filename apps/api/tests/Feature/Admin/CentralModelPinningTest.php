<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use Tests\TestCase;

class CentralModelPinningTest extends TestCase
{
    public function test_admin_audit_log_is_pinned_to_the_central_connection(): void
    {
        $central = (string) config('tenancy.database.central_connection');

        $this->assertSame($central, (new AdminAuditLog)->getConnectionName());
    }
}
