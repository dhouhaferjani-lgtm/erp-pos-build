<?php

declare(strict_types=1);

use App\Enums\Vertical;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $fnbVerticals = [Vertical::CoffeeShop->value, Vertical::Restaurant->value];

        Tenant::whereIn('vertical', $fnbVerticals)->each(function (Tenant $tenant): void {
            $extras = $tenant->enabled_extras ?? [];
            if (! in_array('Inventory', $extras, true)) {
                $extras[] = 'Inventory';
                $tenant->update(['enabled_extras' => $extras]);
            }
        });
    }

    public function down(): void
    {
        // Intentionally blank
    }
};
