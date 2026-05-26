<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            // Add tax_status for partner tax classification
            if (! Schema::hasColumn('partners', 'tax_status')) {
                $table->string('tax_status', 50)->default('REGISTERED')->after('vat_number');
            }

            // Add tax_exemption_reason for exempt partners
            if (! Schema::hasColumn('partners', 'tax_exemption_reason')) {
                $table->text('tax_exemption_reason')->nullable()->after('tax_status');
            }

            // Add tax_exemption_certificate_media_id for uploaded exemption certificates
            // Note: No foreign key constraint added - media table may not exist yet
            if (! Schema::hasColumn('partners', 'tax_exemption_certificate_media_id')) {
                $table->uuid('tax_exemption_certificate_media_id')->nullable()->after('tax_exemption_reason');
            }

            // Add tax_exemption_valid_until for temporary exemptions
            if (! Schema::hasColumn('partners', 'tax_exemption_valid_until')) {
                $table->date('tax_exemption_valid_until')->nullable()->after('tax_exemption_certificate_media_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $columns = ['tax_status', 'tax_exemption_reason', 'tax_exemption_certificate_media_id', 'tax_exemption_valid_until'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('partners', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
