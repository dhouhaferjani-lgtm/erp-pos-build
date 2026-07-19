<?php

declare(strict_types=1);

use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * §1 authorization activation, step 1 (spec §3 / review A2, A11, finding 1).
 *
 * Backfill a NULL-membership (= all locations) for every ACTIVE user that is
 * TRULY MEMBERLESS (has zero membership rows for ANY company in this tenant DB).
 * `UserController::store` historically created staff with no membership row at
 * all, so enforcement on stock transfers / replenishment would deny them
 * wholesale once restricted memberships start to exist. NULL (not []) means
 * "all" — see LocationContext::getAllowedLocationIds().
 *
 * Company mapping (review finding 1 — BLOCKER): the old cross-join of every
 * user with every company granted every user access to every company. Instead:
 *  - SINGLE-company tenant → the target company is unambiguous; backfill.
 *  - MULTI-company tenant → no auditable per-user company signal; SKIP + emit a
 *    structured `multiloc.backfill.skipped_ambiguous_users` log. Skipped users
 *    remain deny-all until an owner explicitly maps them with the companion
 *    `users:backfill-memberships --company=` command. The migration NEVER fails
 *    (push=deploy), but deployment must treat the manual mapping as a gate.
 *
 * Idempotent (memberless set already excludes anyone with a membership) and
 * self-guarding. Membership role is the least-privilege VIEWER — the membership
 * role is NOT the permission axis (Spatie roles are); its only authz consumer is
 * the self-escalation owner-exemption (Task 4), which must NOT treat backfilled
 * staff as owners.
 */
return new class extends Migration
{
    public function up(): void
    {
        $companyIds = DB::table('companies')->pluck('id')->all();
        $companyCount = count($companyIds);
        if ($companyCount === 0) {
            return;
        }

        // Truly memberless active users: no membership row for ANY company.
        $memberlessUserIds = DB::table('users')
            ->where('status', UserStatus::Active->value)
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('user_company_memberships')
                    ->whereColumn('user_company_memberships.user_id', 'users.id');
            })
            ->pluck('id')
            ->all();

        if ($memberlessUserIds === []) {
            return;
        }

        if ($companyCount > 1) {
            // Ambiguous — cannot safely pick a company. Skip + report; never fail.
            Log::warning('multiloc.backfill.skipped_ambiguous_users', [
                'reason' => 'multi_company_tenant_no_company_mapping',
                'company_count' => $companyCount,
                'company_ids' => array_map('strval', $companyIds),
                'skipped_user_ids' => array_map('strval', $memberlessUserIds),
            ]);

            return;
        }

        $companyId = (string) $companyIds[0];
        $now = now();

        foreach ($memberlessUserIds as $userId) {
            DB::table('user_company_memberships')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'company_id' => $companyId,
                'role' => MembershipRole::Viewer->value,
                'allowed_location_ids' => null,
                'is_primary' => false,
                'status' => MembershipStatus::Active->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Non-reversible: backfilled rows are indistinguishable from legitimate
        // all-access memberships created afterwards. Intentional no-op.
    }
};
