<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\LocationCashRegisterProvisionerInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Campaign lane N-12 — a POS-enabled location is born with its own drawer.
 *
 * The wave-1 finding was that `Caisse principale` / `Coffre-fort` are seeded
 * with `location_id = NULL`; the wave-4 re-runs measured what that costs once a
 * second branch exists — `CASH-01 in 452.000 (POS01, Main) · in 200.000 (POS02,
 * Ariana)`. {@see TenderRepositoryResolver} now refuses to route a branch's cash
 * into another location's till, which turns the missing drawer from a silent
 * mis-booking into a refusal. This class removes the refusal's cause: every
 * POS-enabled location gets a cash register at the moment POS is enabled there.
 *
 * ## GL: the same `53`, separated by the repository dimension
 *
 * The chart maps ONE `cash` purpose ({@see SystemAccountPurpose::Cash}) — TN
 * seeds `53 Caisse` with `531 Caisse siège` as a child and no per-till purpose
 * key, and FR/generic are the same shape. There is therefore no seeded
 * sub-account to give a branch drawer, and inventing account codes here would
 * be exactly the "country accounting hardcoded instead of seeded" mistake. Each
 * branch drawer links to the SAME `cash` purpose account; the branch separation
 * is carried by the `payment_repositories.id` / `location_id` dimension on
 * `repository_movements` and `payments`, which is what every per-branch cash
 * report already reads. A tenant whose accountant wants 531/532 per branch can
 * repoint `gl_account_id` afterwards — nothing here overwrites it.
 *
 * Self-guarding: no `cash` purpose account (chart never seeded) means no
 * GL-linked drawer can be created, and an un-GL-linked one is invisible to the
 * resolver anyway — so it logs and returns null rather than minting a row that
 * would look like provisioning succeeded.
 */
final readonly class LocationCashRegisterProvisioner implements LocationCashRegisterProvisionerInterface
{
    /**
     * ONE definition of "a drawer", shared with
     * {@see TenderRepositoryResolver}.
     *
     * Gate r1 finding 6: this class used to ask `type = cash_register` while the
     * resolver counted a `safe` too, so a location whose only drawer was a safe
     * was refused at claim on a configuration the resolver would have served
     * from that safe. The two predicates are documented as answering the same
     * question; now they are the same question. `is_active` is part of it for
     * the same reason it is part of the resolver's (finding 7) — a switched-off
     * drawer is not a place money may come to rest.
     *
     * @var list<string>
     */
    private const DRAWER_TYPES = [
        RepositoryType::CashRegister->value,
        RepositoryType::Safe->value,
    ];

    public function hasOwnDrawer(string $tenantId, string $companyId, string $locationId): bool
    {
        return $this->ownDrawerQuery($tenantId, $companyId, $locationId)->exists();
    }

    public function hasUsableDrawer(string $tenantId, string $companyId, string $locationId): bool
    {
        if ($this->hasOwnDrawer($tenantId, $companyId, $locationId)) {
            return true;
        }

        // The resolver's tier 2: a pre-N-12 tenant whose repositories were never
        // attributed to a location. Those still serve every terminal, so
        // refusing here would break every existing tenant on upgrade.
        return PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereNull('location_id')
            ->where('is_active', true)
            ->whereNotNull('gl_account_id')
            ->whereIn('type', self::DRAWER_TYPES)
            ->exists();
    }

    public function provision(string $tenantId, string $companyId, string $locationId, ?string $locationCode): ?string
    {
        $existing = $this->firstOwnDrawer($tenantId, $companyId, $locationId);
        if ($existing instanceof PaymentRepository) {
            return $existing->id;
        }

        $cashAccount = Account::findByPurpose($companyId, SystemAccountPurpose::Cash);
        if (! $cashAccount instanceof Account) {
            Log::warning('N-12: cannot provision a location cash register without a `cash` purpose account.', [
                'company_id' => $companyId,
                'location_id' => $locationId,
            ]);

            return null;
        }

        $company = Company::query()->find($companyId);
        if (! $company instanceof Company) {
            return null;
        }

        $location = Location::query()
            ->where('company_id', $companyId)
            ->find($locationId);

        // Reuses the EXISTING `treasury.default_repositories.cash_register`
        // label under the company's own locale (no new translation keys), with
        // the branch name appended so an operator can tell the two apart in the
        // repositories list — which is the whole point of per-branch drawers.
        $label = trans('treasury.default_repositories.cash_register', [], $this->resolveLocale($company));
        $name = $location instanceof Location
            ? sprintf('%s — %s', $label, $location->name)
            : $label;

        // A repository is BORN at balance 0 — `balance` is port-managed and not
        // fillable, and the direct-balance-write trigger rejects any other
        // opening value. An opening float arrives later, through the movement
        // port, as its own justifying document (document-per-action).
        // Gate r1 finding 8 — read-then-create is a race, and there is now a
        // DB-level backstop for it: the partial unique index
        // `payment_repositories_one_drawer_per_location_type` added by
        // 2026_08_26_100000_backfill_payment_repository_location_n12. Two
        // concurrent `PATCH /locations/{id} {pos_enabled:true}` calls used to
        // mint two drawers for one location, after which the resolver's
        // `orderBy('id')` tie-break silently picked one and the other
        // accumulated nothing while sitting in the repositories list. The loser
        // of the race now re-reads the winner's row and returns it, so
        // provisioning stays idempotent under concurrency rather than only
        // under sequential calls.
        // The INSERT runs in its OWN nested transaction so a unique violation
        // rolls back to a SAVEPOINT. Without one, PostgreSQL aborts the whole
        // surrounding transaction (`SQLSTATE 25P02`, "commands ignored until end
        // of transaction block") and the recovery read below cannot run at all —
        // which is how this handler behaved when the fleet backfill first called
        // it inside its own transaction. SQLite never showed it.
        try {
            $repository = DB::transaction(fn (): PaymentRepository => PaymentRepository::forceCreate([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'code' => $this->uniqueCode($companyId, $locationCode),
                'name' => $name,
                'type' => RepositoryType::CashRegister->value,
                'location_id' => $locationId,
                'account_id' => $cashAccount->id,
                'gl_account_id' => $cashAccount->id,
                'is_active' => true,
            ]));
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return $this->firstOwnDrawer($tenantId, $companyId, $locationId)?->id;
        }

        return $repository->id;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505';
    }

    /**
     * The location's own drawer, preferring the till over the safe — the same
     * type order the resolver applies within a location.
     */
    private function firstOwnDrawer(string $tenantId, string $companyId, string $locationId): ?PaymentRepository
    {
        return $this->ownDrawerQuery($tenantId, $companyId, $locationId)
            ->orderByRaw(
                'CASE WHEN type = ? THEN 0 ELSE 1 END',
                [RepositoryType::CashRegister->value],
            )
            ->orderBy('id')
            ->first();
    }

    /**
     * @return Builder<PaymentRepository>
     */
    private function ownDrawerQuery(string $tenantId, string $companyId, string $locationId): Builder
    {
        return PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('location_id', $locationId)
            ->whereIn('type', self::DRAWER_TYPES)
            ->where('is_active', true)
            ->whereNotNull('gl_account_id');
    }

    /**
     * `payment_repositories` carries UNIQUE(company_id, code), and a location
     * code is only unique by convention — a company could already own a
     * `CASH-LAC2` from a manual creation. Probe, then fall back to a short
     * random discriminator rather than letting the INSERT blow up a location
     * create that has otherwise succeeded.
     */
    private function uniqueCode(string $companyId, ?string $locationCode): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $locationCode ?? '') ?? '');
        $base = $base === '' ? 'LOC' : substr($base, 0, 12);
        $candidate = 'CASH-'.$base;

        if (! $this->codeTaken($companyId, $candidate)) {
            return $candidate;
        }

        for ($suffix = 2; $suffix <= 20; $suffix++) {
            $next = sprintf('%s-%d', $candidate, $suffix);
            if (! $this->codeTaken($companyId, $next)) {
                return $next;
            }
        }

        return sprintf('%s-%s', $candidate, strtoupper(Str::random(4)));
    }

    private function codeTaken(string $companyId, string $code): bool
    {
        return PaymentRepository::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->exists();
    }

    /**
     * Mirrors `PaymentRepositorySeeder::resolveLocale()` — a company locale
     * (`fr_TN`, `fr-FR`, `en`) reduced to a language this build has a `lang/`
     * directory for, else the fixed fallback. A tenant must never be handed a
     * raw translation key as a repository name.
     */
    private function resolveLocale(Company $company): string
    {
        $language = strtolower(explode('-', str_replace('_', '-', $company->locale))[0]);

        return in_array($language, ['en', 'fr', 'ar'], true) ? $language : 'en';
    }
}
