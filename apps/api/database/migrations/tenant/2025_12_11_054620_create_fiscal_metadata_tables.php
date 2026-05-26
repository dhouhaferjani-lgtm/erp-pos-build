<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // France NF525 metadata
        Schema::create('fr_fiscal_metadata', function (Blueprint $table) {
            $table->uuid('document_id')->primary();
            $table->foreign('document_id')
                ->references('id')
                ->on('documents')
                ->onDelete('cascade');

            $table->bigInteger('nf525_sequence_no');
            $table->uuid('z_report_id')->nullable();
            $table->uuid('period_closure_id')->nullable();
            $table->text('signed_xml_snapshot');
            $table->binary('signature');

            $table->timestampTz('created_at')->useCurrent();

            $table->index('z_report_id');
            $table->index('period_closure_id');
            $table->index('nf525_sequence_no');
        });

        // Saudi Arabia ZATCA metadata
        Schema::create('sa_fiscal_metadata', function (Blueprint $table) {
            $table->uuid('document_id')->primary();
            $table->foreign('document_id')
                ->references('id')
                ->on('documents')
                ->onDelete('cascade');

            $table->uuid('zatca_uuid');
            $table->text('cryptographic_stamp');
            $table->text('qr_code_data');
            $table->text('xml_ubl_snapshot');
            $table->json('clearance_response')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index('zatca_uuid');
        });

        // Germany TSE/KassenSichV metadata
        Schema::create('de_fiscal_metadata', function (Blueprint $table) {
            $table->uuid('document_id')->primary();
            $table->foreign('document_id')
                ->references('id')
                ->on('documents')
                ->onDelete('cascade');

            $table->bigInteger('tse_transaction_id');
            $table->string('tse_serial_number', 50);
            $table->binary('tse_signature');
            $table->boolean('dsfinvk_export_ready')->default(false);

            $table->timestampTz('created_at')->useCurrent();

            $table->index('tse_transaction_id');
            $table->index('tse_serial_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('de_fiscal_metadata');
        Schema::dropIfExists('sa_fiscal_metadata');
        Schema::dropIfExists('fr_fiscal_metadata');
    }
};
