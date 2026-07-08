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
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->string('source_ref', 2048)->nullable()->after('external_url');
        });

        // Partial unique: one asset per (tenant, source URL). DB-enforced idempotency.
        // Both Postgres and SQLite support partial (`WHERE`) unique indexes, so the
        // same statement works on both drivers used in production and in the test suite.
        DB::statement(
            'CREATE UNIQUE INDEX media_assets_tenant_source_ref_unique
             ON media_assets (tenant_id, source_ref) WHERE source_ref IS NOT NULL'
        );

        // Dedupe existing owner<->asset links before enforcing uniqueness — a fresh
        // RefreshDatabase run on SQLite never has duplicates, so this is a no-op there.
        // `DELETE ... USING` is Postgres-only syntax; only run it on pgsql.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'DELETE FROM media_attachments a USING media_attachments b '
                .'WHERE a.id > b.id AND a.tenant_id = b.tenant_id AND a.owner_type = b.owner_type '
                .'AND a.owner_id = b.owner_id AND a.media_asset_id = b.media_asset_id'
            );
        }

        // An asset attaches to a given owner at most once.
        Schema::table('media_attachments', function (Blueprint $table): void {
            $table->unique(
                ['tenant_id', 'owner_type', 'owner_id', 'media_asset_id'],
                'media_attachments_owner_asset_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('media_attachments', function (Blueprint $table): void {
            $table->dropUnique('media_attachments_owner_asset_unique');
        });
        DB::statement('DROP INDEX IF EXISTS media_assets_tenant_source_ref_unique');
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropColumn('source_ref');
        });
    }
};
