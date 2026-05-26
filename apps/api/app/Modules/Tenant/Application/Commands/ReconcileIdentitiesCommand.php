<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Commands;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\IdentityIndexService;
use App\Modules\Tenant\Domain\CentralIdentity;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;

/**
 * Backfill / repair the central identity index (topology contract §9.1).
 *
 * The index should hold exactly one row per (email, tenant) for every user
 * that has a non-null email and is NOT deactivated (status != Inactive) —
 * mirroring the {@see IdentityIndexService} lifecycle wiring. This command
 * reconciles drift: it backfills missing rows and prunes stale ones (rows for
 * deactivated/deleted users or orphan rows pointing at no user).
 *
 * Flip-agnostic note: today every tenant's users live on the default
 * connection, so users are queried directly by tenant_id. Post-flip this loop
 * is the natural place to `tenancy()->initialize($tenant)` per iteration; the
 * reconcile contract (which emails map to which tenant) is unchanged.
 */
class ReconcileIdentitiesCommand extends Command
{
    protected $signature = 'tenant:reconcile-identities {--tenant= : Limit reconciliation to a single tenant id}';

    protected $description = 'Backfill and repair the central_identities index from tenant users.';

    public function handle(IdentityIndexService $identityIndex): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $added = 0;
        $pruned = 0;

        foreach ($tenants as $tenant) {
            /** @var Tenant $tenant */
            // Desired index emails: users with an email that are not deactivated.
            $desired = User::query()
                ->where('tenant_id', $tenant->id)
                ->whereNotNull('email')
                ->where('status', '!=', UserStatus::Inactive->value)
                ->get(['id', 'email']);

            $desiredEmails = [];
            foreach ($desired as $user) {
                $desiredEmails[(string) $user->email] = (string) $user->id;
            }

            // Backfill missing / refresh user_id pointers.
            foreach ($desiredEmails as $email => $userId) {
                $existing = CentralIdentity::query()
                    ->where('email', $email)
                    ->where('tenant_id', $tenant->id)
                    ->first();

                if ($existing === null) {
                    $identityIndex->record($email, $tenant->id, $userId);
                    $added++;
                } elseif ($existing->user_id !== $userId) {
                    $existing->update(['user_id' => $userId]);
                }
            }

            // Prune rows whose email is no longer desired (deactivated / orphan).
            $stale = CentralIdentity::query()
                ->where('tenant_id', $tenant->id)
                ->whereNotIn('email', array_keys($desiredEmails))
                ->get();

            foreach ($stale as $row) {
                $row->delete();
                $pruned++;
            }
        }

        $this->info("Reconciled central identities: {$added} added, {$pruned} pruned across {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
