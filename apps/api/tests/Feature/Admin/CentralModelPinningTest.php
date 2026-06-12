<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Modules\Billing\Domain\Invoice;
use App\Modules\Billing\Domain\InvoiceItem;
use App\Modules\Billing\Domain\Payment;
use App\Modules\Billing\Domain\Refund;
use Tests\TestCase;

class CentralModelPinningTest extends TestCase
{
    public function test_admin_audit_log_is_pinned_to_the_central_connection(): void
    {
        $central = (string) config('tenancy.database.central_connection');

        $this->assertSame($central, (new AdminAuditLog)->getConnectionName());
    }

    public function test_platform_billing_models_are_pinned_to_the_central_connection(): void
    {
        $central = (string) config('tenancy.database.central_connection');

        foreach ([Invoice::class, InvoiceItem::class, Payment::class, Refund::class] as $model) {
            $this->assertSame($central, (new $model)->getConnectionName(), $model);
        }
    }

    public function test_no_billing_table_migrations_remain_in_the_tenant_set(): void
    {
        $this->assertSame([], glob(database_path('migrations/tenant/*billing_*')));
    }
}
