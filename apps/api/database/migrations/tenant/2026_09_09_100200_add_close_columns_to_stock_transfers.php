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
        if (! Schema::hasColumn('stock_transfers', 'closed_by_user_id')) {
            Schema::table('stock_transfers', function (Blueprint $table): void {
                $table->uuid('closed_by_user_id')->nullable();
            });
        }

        foreach ([
            'closed_at' => static fn (Blueprint $table) => $table->timestampTz('closed_at')->nullable(),
            'close_disposition' => static fn (Blueprint $table) => $table->string('close_disposition', 20)->nullable(),
            'close_reason' => static fn (Blueprint $table) => $table->string('close_reason', 32)->nullable(),
            'close_note' => static fn (Blueprint $table) => $table->text('close_note')->nullable(),
            'freight_uncapitalized' => static fn (Blueprint $table) => $table->decimal('freight_uncapitalized', 15, 4)->default(0),
        ] as $column => $definition) {
            if (Schema::hasColumn('stock_transfers', $column)) {
                continue;
            }

            Schema::table('stock_transfers', function (Blueprint $table) use ($definition): void {
                $definition($table);
            });
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $existing = DB::selectOne(
            'SELECT 1 AS present FROM pg_constraint WHERE conname = ?',
            ['stock_transfers_closed_by_user_id_foreign']
        );

        if ($existing === null) {
            DB::statement(
                'ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_closed_by_user_id_foreign '
                .'FOREIGN KEY (closed_by_user_id) REFERENCES users (id) ON DELETE SET NULL'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_transfers DROP CONSTRAINT IF EXISTS stock_transfers_closed_by_user_id_foreign');
        }

        foreach (['freight_uncapitalized', 'close_note', 'close_reason', 'close_disposition', 'closed_at', 'closed_by_user_id'] as $column) {
            if (! Schema::hasColumn('stock_transfers', $column)) {
                continue;
            }

            Schema::table('stock_transfers', function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }
};
