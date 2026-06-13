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
        Schema::create('media_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('media_asset_id');
            $table->string('owner_type');
            $table->uuid('owner_id');
            $table->string('role');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('channel')->nullable();
            $table->string('locale')->nullable();
            $table->string('alt')->nullable();
            $table->string('caption')->nullable();
            $table->timestamps();

            $table->foreign('media_asset_id')->references('id')->on('media_assets')->cascadeOnDelete();
            $table->index(['tenant_id', 'owner_type', 'owner_id', 'sort_order']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX media_attachments_one_primary
                ON media_attachments (owner_type, owner_id, COALESCE(channel, ''), COALESCE(locale, ''))
                WHERE role = 'PRIMARY'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_attachments');
    }
};
