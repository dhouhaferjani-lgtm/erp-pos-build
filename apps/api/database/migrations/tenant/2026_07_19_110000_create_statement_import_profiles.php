<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('statement_import_profiles')) {
            return;
        }

        Schema::create('statement_import_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->foreignUuid('payment_repository_id')
                ->constrained('payment_repositories')
                ->restrictOnDelete();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->string('parser_key', 10);
            $table->jsonb('column_map');
            $table->string('date_format', 40);
            $table->string('decimal_format', 20);
            $table->string('direction_convention', 30);
            $table->unsignedSmallInteger('header_rows')->default(1);
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
            $table->index(['payment_repository_id', 'is_active']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE statement_import_profiles ADD CONSTRAINT statement_import_profiles_parser_key_check CHECK (parser_key IN ('csv','xlsx'))");
            DB::statement("ALTER TABLE statement_import_profiles ADD CONSTRAINT statement_import_profiles_direction_check CHECK (direction_convention IN ('signed_amount','debit_credit_columns'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_import_profiles');
    }
};
