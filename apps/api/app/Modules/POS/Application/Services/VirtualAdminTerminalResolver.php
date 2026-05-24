<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\Location;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class VirtualAdminTerminalResolver
{
    public function resolve(string $tenantId, string $companyId): Terminal
    {
        return DB::transaction(function () use ($tenantId, $companyId): Terminal {
            $existing = Terminal::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('type', TerminalType::VirtualAdmin)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof Terminal) {
                return $existing;
            }

            $locationId = Location::query()
                ->where('company_id', $companyId)
                ->orderBy('created_at')
                ->value('id');

            if (! is_string($locationId) || $locationId === '') {
                throw new RuntimeException('Cannot create virtual admin terminal without a company location.');
            }

            return Terminal::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'location_id' => $locationId,
                'type' => TerminalType::VirtualAdmin,
                'code' => 'VADMIN',
                'name' => 'Virtual Admin Terminal',
                'description' => 'Server-only administrative fiscal event terminal',
                'genesis_seed' => bin2hex(random_bytes(32)),
                'current_sequence' => 1,
                'current_year' => (int) now()->format('Y'),
                'fiscal_schema_version' => 3,
                'is_active' => true,
                'activated_at' => now(),
                'allow_line_discounts' => false,
                'allow_transaction_discounts' => false,
                'max_discount_percent' => 0,
            ]);
        });
    }
}
