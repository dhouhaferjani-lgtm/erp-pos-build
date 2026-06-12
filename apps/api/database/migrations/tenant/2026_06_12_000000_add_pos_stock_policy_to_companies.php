<?php

declare(strict_types=1);

use App\Modules\Company\Domain\Enums\PosStockPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('pos_stock_policy', 10)
                ->default(PosStockPolicy::Block->value)
                ->after('compliance_profile');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('pos_stock_policy');
        });
    }
};
