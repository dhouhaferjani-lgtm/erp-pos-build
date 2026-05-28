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
        Schema::table('companies', function (Blueprint $table) {
            $table->string('smart_prompts_variant', 10)->default('off')->change();
        });

        // Reset rows still on the old default to 'off'. Rows explicitly set
        // to 'toast' or 'both' are intentional and left alone.
        DB::table('companies')
            ->where('smart_prompts_variant', 'inline')
            ->update(['smart_prompts_variant' => 'off']);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('smart_prompts_variant', 10)->default('inline')->change();
        });
    }
};
