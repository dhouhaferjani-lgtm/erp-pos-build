<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->uuid('impersonator_id')->nullable()->index();
            $table->uuid('impersonation_session_id')->nullable()->index();
            $table->uuid('impersonation_event_id')->nullable()->index();
            $table->unsignedBigInteger('impersonation_sequence')->nullable();
            $table->char('impersonation_previous_hash', 64)->nullable();
            $table->char('impersonation_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropColumn([
                'impersonator_id',
                'impersonation_session_id',
                'impersonation_event_id',
                'impersonation_sequence',
                'impersonation_previous_hash',
                'impersonation_hash',
            ]);
        });
    }
};
