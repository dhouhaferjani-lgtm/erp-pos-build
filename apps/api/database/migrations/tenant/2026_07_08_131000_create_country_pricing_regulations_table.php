<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('country_pricing_regulations', function (Blueprint $table): void {
            $table->id();
            $table->char('country_code', 2);
            $table->string('rule_type', 64);
            $table->string('enforcement', 32)->default('Advisory');
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['country_code', 'rule_type']);
            $table->index(['country_code', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('country_pricing_regulations');
    }
};
