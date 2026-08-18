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

        Schema::connection($connection)->create('country_template_assignments', function (Blueprint $table): void {
            $table->uuid('id');
            $table->string('country_code', 2);
            $table->string('domain', 64);
            $table->uuid('template_id');
            $table->timestamps();

            $table->primary('id');
            $table->unique(['country_code', 'domain'], 'country_template_assignments_country_domain_unique');
            $table->foreign(['template_id', 'domain'], 'country_template_assignments_template_domain_fk')
                ->references(['id', 'domain'])
                ->on('admin_templates');
        });
    }

    public function down(): void
    {
        $connection = (string) config('tenancy.database.central_connection');
        Schema::connection($connection)->dropIfExists('country_template_assignments');
    }
};
