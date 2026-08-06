<?php

declare(strict_types=1);

use App\Modules\Treasury\Domain\Enums\RepositoryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W-5b Option B (owner ruling, 2026-08-05 —
 * docs/sessions/RULINGS-RESEARCH-2026-08-05-negative-repository-balance.md):
 * a physical/pseudo-physical repository (cash_register/safe/virtual) cannot
 * hold negative cash; a bank_account may run an authorised overdraft. This
 * column is the per-repository lever {@see TreasuryMovementService::record()}
 * enforces against, defaulted from RepositoryType.
 *
 * MIGRATION-BEARING (auto-runs on staging on push): self-guarding on both
 * legs — skip the DDL if the column already exists, and the backfill UPDATE
 * is naturally idempotent (re-running it against already-correct rows is a
 * no-op). Safe on an empty table, a partially-migrated tenant, or a re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_repositories') || Schema::hasColumn('payment_repositories', 'allow_negative')) {
            return;
        }

        Schema::table('payment_repositories', function (Blueprint $table): void {
            $table->boolean('allow_negative')->default(false)->after('type');
        });

        // Type-derived backfill: only bank_account rows flip to true — every
        // other type is left at the column default (false) they were just
        // created with, so this UPDATE only ever touches bank rows and is a
        // no-op on any subsequent run (idempotent).
        DB::table('payment_repositories')
            ->where('type', RepositoryType::BankAccount->value)
            ->update(['allow_negative' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_repositories') || ! Schema::hasColumn('payment_repositories', 'allow_negative')) {
            return;
        }

        Schema::table('payment_repositories', function (Blueprint $table): void {
            $table->dropColumn('allow_negative');
        });
    }
};
