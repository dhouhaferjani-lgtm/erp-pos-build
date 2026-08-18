<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = (string) config('tenancy.database.central_connection');

        Schema::connection($connection)->create('admin_templates', function (Blueprint $table): void {
            $table->uuid('id');
            $table->string('domain', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 32);
            $table->string('bootstrap_key')->nullable()->unique();
            $table->uuid('cloned_from_id')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('standard_ref')->nullable();
            $table->jsonb('certified_country_codes')->nullable();
            $table->string('capability_registry_version')->nullable();
            $table->uuid('certified_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->primary('id');
            $table->unique(['id', 'domain'], 'admin_templates_id_domain_unique');
            $table->foreign('cloned_from_id')->references('id')->on('admin_templates')->nullOnDelete();
            $table->foreign('certified_by')->references('id')->on('super_admins')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('super_admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $connection = (string) config('tenancy.database.central_connection');
        Schema::connection($connection)->dropIfExists('admin_templates');
    }
};
