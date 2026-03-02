<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Query stuck documents first for audit trail
        $stuckDocuments = DB::table('documents')
            ->whereIn('type', ['sales_order', 'purchase_order'])
            ->where('status', 'paid')
            ->select(['id', 'type', 'document_number'])
            ->get();

        if ($stuckDocuments->isEmpty()) {
            return;
        }

        // Log each document individually for fiscal audit trail
        foreach ($stuckDocuments as $doc) {
            Log::warning("Fixing stuck 'paid' status on {$doc->type} {$doc->document_number} (id: {$doc->id})");
        }

        // Bulk-update by collected IDs
        $ids = $stuckDocuments->pluck('id')->all();
        $affected = DB::table('documents')
            ->whereIn('id', $ids)
            ->update(['status' => 'confirmed']);

        Log::warning("Fixed {$affected} SO/PO documents stuck with 'paid' status (total identified: {$stuckDocuments->count()})");
    }

    public function down(): void
    {
        // No rollback — corrects data that should never have existed.
    }
};
