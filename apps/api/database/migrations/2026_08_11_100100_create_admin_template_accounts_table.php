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

        Schema::connection($connection)->create('admin_template_accounts', function (Blueprint $table): void {
            $table->uuid('id');
            $table->uuid('template_id');
            $table->string('code');
            $table->string('name');
            $table->string('type', 32);
            $table->string('parent_code')->nullable();
            $table->string('system_purpose')->nullable();
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->primary('id');
            $table->unique(['template_id', 'code'], 'admin_template_accounts_template_code_unique');
            $table->unique(['template_id', 'system_purpose'], 'admin_template_accounts_template_purpose_unique');
            $table->unique(['template_id', 'sort_order'], 'admin_template_accounts_template_sort_unique');
            $table->foreign('template_id')->references('id')->on('admin_templates')->cascadeOnDelete();
            $table->foreign(['template_id', 'parent_code'], 'admin_template_accounts_parent_fk')
                ->references(['template_id', 'code'])
                ->on('admin_template_accounts');
        });
    }

    public function down(): void
    {
        $connection = (string) config('tenancy.database.central_connection');
        Schema::connection($connection)->dropIfExists('admin_template_accounts');
    }
};
