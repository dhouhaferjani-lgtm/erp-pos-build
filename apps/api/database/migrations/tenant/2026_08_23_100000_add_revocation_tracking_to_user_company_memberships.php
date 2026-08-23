<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offboarding cascade — revocation provenance on user_company_memberships.
 *
 * `MembershipStatus::Revoked` existed in the enum but nothing in the
 * application ever wrote it: deactivating a user set `users.status = inactive`
 * and stopped there, so every membership stayed Active and every authorization
 * site that reads membership status (CompanyContext, PinVerifier,
 * PosAuthController::pinData, AuthorizedManagersController) kept letting a
 * fired employee through — including their POS manager-override PIN.
 *
 * The cascade needs to record WHO revoked, WHEN, and WHY, because the reverse
 * edge (`UserController::activate`) restores only the rows the cascade itself
 * took. Without `revoked_reason` the two cases are indistinguishable and
 * reactivation would silently resurrect independently-revoked memberships.
 *
 * Additive and self-guarding: each column is added only if absent, so a re-run
 * (or a tenant DB already carrying the columns) is a no-op. No FK is declared
 * on `revoked_by` — SQLite cannot add a foreign key to an existing table, and
 * the column is provenance metadata, not a join key: a hard-deleted actor
 * leaving a dangling UUID is preferable to losing the audit stamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_company_memberships')) {
            return;
        }

        Schema::table('user_company_memberships', function (Blueprint $table): void {
            if (! Schema::hasColumn('user_company_memberships', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable();
            }

            if (! Schema::hasColumn('user_company_memberships', 'revoked_by')) {
                $table->uuid('revoked_by')->nullable();
            }

            if (! Schema::hasColumn('user_company_memberships', 'revoked_reason')) {
                $table->string('revoked_reason', 40)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_company_memberships')) {
            return;
        }

        Schema::table('user_company_memberships', function (Blueprint $table): void {
            foreach (['revoked_at', 'revoked_by', 'revoked_reason'] as $column) {
                if (Schema::hasColumn('user_company_memberships', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
