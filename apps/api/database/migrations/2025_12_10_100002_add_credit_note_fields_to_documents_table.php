<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Credit note relationship to original document
            $table->uuid('related_document_id')->nullable();
            $table->foreign('related_document_id')->references('id')->on('documents');

            // Credit note specific fields
            $table->string('credit_note_reason', 100)->nullable();
            $table->text('return_comment')->nullable();

            // Ensure due_date exists (for payment allocation)
            if (! Schema::hasColumn('documents', 'due_date')) {
                $table->date('due_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['related_document_id']);
            $table->dropColumn([
                'related_document_id',
                'credit_note_reason',
                'return_comment',
            ]);
        });
    }
};
