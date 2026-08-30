<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\DTOs\RepositoryCensusFinding;
use App\Modules\Treasury\Application\DTOs\RepositoryCensusResult;
use App\Modules\Treasury\Domain\Enums\RepositoryCensusCode;
use App\Modules\Treasury\Domain\Enums\RepositoryLocationAttributionVerdict;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class RepositoryCensusService
{
    /**
     * Exhaustive census boundary for all 16 direct repository-id columns across
     * 14 tenant tables that reference payment_repositories as of SG-3c-FU. A new
     * direct reference must be added here before its migration may ship.
     *
     * @var list<array{table: string, column: string}>
     */
    private const REFERENCE_SURFACES = [
        ['table' => 'repository_movements', 'column' => 'payment_repository_id'],
        ['table' => 'payments', 'column' => 'repository_id'],
        ['table' => 'repository_adjustments', 'column' => 'payment_repository_id'],
        ['table' => 'bank_statements', 'column' => 'payment_repository_id'],
        ['table' => 'bank_statement_lines', 'column' => 'payment_repository_id'],
        ['table' => 'expense_metadata', 'column' => 'payment_repository_id'],
        ['table' => 'income_metadata', 'column' => 'payment_repository_id'],
        ['table' => 'payment_methods', 'column' => 'default_repository_id'],
        ['table' => 'payment_instruments', 'column' => 'repository_id'],
        ['table' => 'payment_instruments', 'column' => 'deposited_to_id'],
        ['table' => 'instrument_events', 'column' => 'from_repository_id'],
        ['table' => 'instrument_events', 'column' => 'to_repository_id'],
        ['table' => 'instrument_remittances', 'column' => 'bank_repository_id'],
        ['table' => 'statement_import_profiles', 'column' => 'payment_repository_id'],
        ['table' => 'bank_reconciliations', 'column' => 'repository_id'],
        ['table' => 'expense_recurrence_templates', 'column' => 'payment_repository_id'],
    ];

    private const LOCATION_REMEDIATION = 'Repository location drift is not repaired by either repository command or by treasury:backfill-location-attribution. Use the N-12 migration when attribution is unambiguous; ambiguous companies require an owner ruling and an approved one-off data fix.';

    private const TRANSFER_REMEDIATION = 'post a `RepositoryTransfer` (`RepositoryTransferService`) from this safe to the canonical one, then re-run';

    public function __construct(
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function census(string $tenantId, string $companyId): RepositoryCensusResult
    {
        /** @var Collection<int, PaymentRepository> $repositories */
        $repositories = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $locationCount = DB::table('locations')
            ->where('company_id', $companyId)
            ->count();

        $findings = [];
        $activeNullDrawers = $repositories->filter(
            static fn (PaymentRepository $repository): bool => $repository->is_active
                && $repository->location_id === null
                && in_array($repository->type, [RepositoryType::CashRegister, RepositoryType::Safe], true),
        );
        foreach ($activeNullDrawers as $repository) {
            $unattributedOfType = $repositories->filter(
                static fn (PaymentRepository $candidate): bool => $candidate->location_id === null
                    && $candidate->type === $repository->type,
            )->count();
            $verdict = $unattributedOfType > 1 && $locationCount > 1
                ? RepositoryLocationAttributionVerdict::Ambiguous
                : RepositoryLocationAttributionVerdict::Attributable;
            $findings[] = new RepositoryCensusFinding(
                code: RepositoryCensusCode::CashLocationNull,
                companyId: $companyId,
                repositoryId: $repository->id,
                repositoryCode: $repository->code,
                repositoryType: $repository->type->value,
                attributionVerdict: $verdict,
                hint: self::LOCATION_REMEDIATION,
            );
        }

        /** @var Collection<int, PaymentRepository> $activeSafes */
        $activeSafes = $repositories->filter(
            static fn (PaymentRepository $repository): bool => $repository->is_active
                && $repository->type === RepositoryType::Safe,
        )->values();
        $canonical = $activeSafes->first(
            static fn (PaymentRepository $repository): bool => $repository->gl_account_id !== null,
        ) ?? $activeSafes->first();
        $canonicalSafeId = $canonical instanceof PaymentRepository ? $canonical->id : null;

        if ($activeSafes->count() !== 1) {
            $findings[] = new RepositoryCensusFinding(
                code: RepositoryCensusCode::SafeCountNotOne,
                companyId: $companyId,
                canonicalRepositoryId: $canonicalSafeId,
                hint: 'The company must have exactly one active safe.',
            );
        }

        foreach ($activeSafes as $safe) {
            if ($safe->gl_account_id !== null) {
                continue;
            }

            $findings[] = new RepositoryCensusFinding(
                code: RepositoryCensusCode::SafeGlUnlinked,
                companyId: $companyId,
                repositoryId: $safe->id,
                repositoryCode: $safe->code,
                repositoryType: $safe->type->value,
                locationId: $safe->location_id,
                canonicalRepositoryId: $canonicalSafeId,
                hint: 'Link the canonical safe to the company cash-purpose account; when that account is absent, leave it unlinked and repair the chart first.',
            );
        }

        if ($canonical instanceof PaymentRepository) {
            foreach ($activeSafes as $safe) {
                if ($safe->id === $canonical->id) {
                    continue;
                }

                $moneyBearing = $this->isMoneyBearing($safe);
                $findings[] = new RepositoryCensusFinding(
                    code: $moneyBearing
                        ? RepositoryCensusCode::DuplicateMoneyBearing
                        : RepositoryCensusCode::SafeDuplicateClean,
                    companyId: $companyId,
                    repositoryId: $safe->id,
                    repositoryCode: $safe->code,
                    repositoryType: $safe->type->value,
                    locationId: $safe->location_id,
                    canonicalRepositoryId: $canonical->id,
                    hint: $moneyBearing
                        ? self::TRANSFER_REMEDIATION
                        : 'The normaliser may deactivate this zero-balance, movement-free, unreferenced surplus safe.',
                );
            }
        }

        /** @var array<string, list<PaymentRepository>> $drawersByLocationType */
        $drawersByLocationType = [];
        foreach ($repositories as $repository) {
            if (! $repository->is_active
                || $repository->location_id === null
                || $repository->gl_account_id === null
                || ! in_array($repository->type, [RepositoryType::CashRegister, RepositoryType::Safe], true)) {
                continue;
            }

            $key = $repository->location_id.'|'.$repository->type->value;
            $drawersByLocationType[$key][] = $repository;
        }
        foreach ($drawersByLocationType as $duplicates) {
            if (count($duplicates) < 2) {
                continue;
            }

            $first = $duplicates[0];
            $findings[] = new RepositoryCensusFinding(
                code: RepositoryCensusCode::DuplicatePerLocationType,
                companyId: $companyId,
                repositoryId: $first->id,
                repositoryCode: $first->code,
                repositoryType: $first->type->value,
                locationId: $first->location_id,
                hint: 'A pre-existing active GL-linked per-location duplicate requires operator review and is not auto-repaired.',
            );
        }

        return new RepositoryCensusResult($tenantId, $companyId, $canonicalSafeId, $findings);
    }

    public function isMoneyBearingRepository(string $tenantId, string $companyId, string $repositoryId): bool
    {
        $repository = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereKey($repositoryId)
            ->first();

        return $repository instanceof PaymentRepository && $this->isMoneyBearing($repository);
    }

    private function isMoneyBearing(PaymentRepository $repository): bool
    {
        return bccomp(
            $repository->balance,
            '0',
            $this->scaleResolver->getScale($repository->currency),
        ) !== 0 || $this->hasReference($repository->id);
    }

    private function hasReference(string $repositoryId): bool
    {
        foreach (self::REFERENCE_SURFACES as $surface) {
            if (DB::table($surface['table'])->where($surface['column'], $repositoryId)->exists()) {
                return true;
            }
        }

        return false;
    }
}
