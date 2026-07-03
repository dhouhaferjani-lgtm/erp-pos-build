<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE INDEX IF NOT EXISTS documents_rfq_group_idx ON documents (tenant_id, ((payload->'rfq'->>'group_id'))) WHERE type = 'purchase_rfq'"
            );
        }

        $year = (int) date('Y');

        DB::table('companies')
            ->select(['id', 'tenant_id'])
            ->orderBy('id')
            ->each(function (object $company) use ($year): void {
                $exists = DB::table('document_sequences')
                    ->where('company_id', (string) $company->id)
                    ->where('type', 'purchase_rfq')
                    ->where('year', $year)
                    ->exists();

                if ($exists) {
                    return;
                }

                DB::table('document_sequences')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => (string) $company->tenant_id,
                    'company_id' => (string) $company->id,
                    'type' => 'purchase_rfq',
                    'year' => $year,
                    'last_number' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS documents_rfq_group_idx');
        }

        DB::table('document_sequences')
            ->where('type', 'purchase_rfq')
            ->where('last_number', 0)
            ->delete();
    }
};
