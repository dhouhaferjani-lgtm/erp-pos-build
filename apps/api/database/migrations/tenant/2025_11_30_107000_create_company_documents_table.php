<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.1.5: Create company_documents table.
 *
 * CompanyDocument stores uploaded documents for companies (verification documents,
 * certificates, tax registrations, etc.).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_documents', function (Blueprint $table) {
            // Primary key
            $table->uuid('id')->primary();

            // Foreign key
            $table->uuid('company_id');

            // Document info
            $table->string('document_type', 50); // 'tax_registration', 'business_license', 'vat_certificate', etc.
            $table->string('file_path', 500);
            $table->string('original_filename', 255)->nullable();
            $table->unsignedInteger('file_size')->nullable(); // in bytes
            $table->string('mime_type', 100)->nullable();

            // Review status
            $table->string('status', 20)->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->text('rejection_reason')->nullable();

            // Expiration
            $table->date('expires_at')->nullable();

            // Timestamps
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamps();

            // Foreign keys
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');

            // Indexes
            $table->index('company_id', 'idx_company_docs_company');
            $table->index('status', 'idx_company_docs_status');
            $table->index(['company_id', 'document_type'], 'idx_company_docs_type');
            $table->index('expires_at', 'idx_company_docs_expires');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_documents');
    }
};
