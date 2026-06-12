<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vertical_configs', function (Blueprint $table) {
            $table->string('vertical')->primary();
            $table->jsonb('default_modules')->nullable();
            $table->jsonb('compatible_extras')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vertical_configs');
    }
};
