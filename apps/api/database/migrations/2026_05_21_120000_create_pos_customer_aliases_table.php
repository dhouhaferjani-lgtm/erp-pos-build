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
        Schema::create('pos_customer_aliases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('client_customer_uuid');
            $table->uuid('server_partner_id');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id', 'client_customer_uuid'], 'pos_customer_aliases_client_unique');
            $table->index(['tenant_id', 'company_id', 'server_partner_id'], 'pos_customer_aliases_partner_idx');
            $table->index(['tenant_id', 'client_customer_uuid'], 'pos_customer_aliases_tenant_client_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE pos_customer_aliases
                ADD CONSTRAINT pos_customer_aliases_tenant_fk
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE pos_customer_aliases
                ADD CONSTRAINT pos_customer_aliases_company_fk
                FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE pos_customer_aliases
                ADD CONSTRAINT pos_customer_aliases_partner_fk
                FOREIGN KEY (server_partner_id) REFERENCES partners(id) ON DELETE CASCADE
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customer_aliases');
    }
};
