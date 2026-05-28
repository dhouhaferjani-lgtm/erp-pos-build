<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add fiscal_chain_seed column to companies table.
 *
 * Each company gets a unique cryptographically random seed used as the
 * "previous hash" for the genesis (first) document in each hash chain.
 * This is more secure than using a fixed value like SHA256("0") because
 * it adds per-tenant entropy, making chain forgery harder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // 64 chars = 256-bit hex-encoded seed
            $table->string('fiscal_chain_seed', 64)->nullable()->after('country_code');
        });

        // Generate seeds for existing companies
        $this->generateSeedsForExistingCompanies();
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('fiscal_chain_seed');
        });
    }

    /**
     * Generate unique fiscal chain seeds for all existing companies.
     */
    private function generateSeedsForExistingCompanies(): void
    {
        $companies = DB::table('companies')->whereNull('fiscal_chain_seed')->get();

        foreach ($companies as $company) {
            DB::table('companies')
                ->where('id', $company->id)
                ->update([
                    'fiscal_chain_seed' => bin2hex(random_bytes(32)), // 256-bit random seed
                ]);
        }
    }
};
