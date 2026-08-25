<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\BatchExpiry\Application\Services\LotLedgerDriftCensus;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\QuantityScale;

/**
 * READ-ONLY detector for lot-ledger drift: `Σ inventory_batch_stock` versus
 * `stock_levels.quantity`, per batch-tracked (product, variant, location).
 *
 * 🚨 **W4R-2 gate r1 F-4 (inventory) / F-5 (fiscal).** The POS receipt
 * projection draws FEFO lots NON-STRICTLY on purpose — it projects a SEALED
 * fiscal event and a projector may never reject one, so a lot it cannot draw is
 * a logged shortfall rather than a refused sale. Same for a contained lot-arm
 * failure. Both are the right call at the moment they are made, and both leave
 * the tuple drifted with nothing but a log line to say so. Both gates asked for
 * the other half: a way to FIND that drift.
 *
 * This command is that half, and nothing else. It **writes nothing** — no
 * corrections, no movements, no repair run id, no `--execute` arm exists to
 * pass by accident. When it finds drift, the remedy is a separate, deliberate
 * act: `inventory:repair-phantom-default-batches` for the phantom-`DEFAULT`
 * shape, and an operator judgement for the rest.
 *
 * It is safe to schedule. `--fail-on-drift` turns a drifted tenant into a
 * non-zero exit so a cron or CI check can alert on it; without the flag the
 * command reports and exits 0, so an unattended run cannot page anyone by
 * surprise on day one.
 *
 * Sign convention (the repair command's, kept identical so the two transcripts
 * read the same way): POSITIVE drift means the lot ledger OVERSTATES on-hand —
 * an outbound act moved `stock_levels` and not the lots. NEGATIVE means it
 * understates — inbound stock credited the aggregate and not the lots.
 */
final class LotLedgerDriftCensusCommand extends TenantScopedCommand
{
    private const int SCALE = 4;

    private const string CAUSE_POSITIVE =
        '    cause: an outbound act moved stock_levels, not the lot ledger — a POS lot shortfall, a contained lot-arm failure, or a pre-W4R-2 sale';

    private const string CAUSE_NEGATIVE =
        '    cause: inbound stock (a return or a receipt) credited stock_levels, not the lot ledger';

    protected $signature = 'inventory:lot-drift-census
        {--tenant= : Tenant UUID to census (required unless --all-tenants)}
        {--all-tenants : Deliberate fleet-wide census over every reachable tenant}
        {--company= : Narrow the census to one company UUID inside the selected tenant(s)}
        {--fail-on-drift : Exit non-zero when any tuple is drifted, for a scheduled check}';

    protected $description = 'READ-ONLY: report every batch-tracked tuple where the lot ledger and stock_levels disagree (writes nothing)';

    public function __construct(
        CompanyContext $companyContext,
        private readonly LotLedgerDriftCensus $census,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $companyFilter = $this->stringOption('company');

        $tuplesDrifted = 0;
        /** @var numeric-string $netDrift */
        $netDrift = QuantityScale::round('0', self::SCALE, QuantityScale::FLOOR);
        /** @var numeric-string $absoluteDrift */
        $absoluteDrift = $netDrift;

        $exit = $this->forEachExplicitlySelectedTenant(
            $this->stringOption('tenant'),
            $this->option('all-tenants') === true,
            function (Tenant $tenant) use ($companyFilter, &$tuplesDrifted, &$netDrift, &$absoluteDrift): int {
                $companies = Company::query()
                    ->where('tenant_id', $tenant->id)
                    ->when($companyFilter !== null, fn ($query) => $query->where('id', $companyFilter))
                    ->get();

                foreach ($companies as $company) {
                    $rows = $this->census->driftedTuples((string) $company->id);

                    if ($rows === []) {
                        continue;
                    }

                    $this->line(sprintf('  %s / %s', (string) $tenant->id, (string) $company->id));

                    foreach ($rows as $row) {
                        $tuplesDrifted++;
                        $netDrift = bcadd($netDrift, $row['drift'], self::SCALE);
                        $absoluteDrift = bcadd(
                            $absoluteDrift,
                            bccomp($row['drift'], '0', self::SCALE) < 0
                                ? bcmul($row['drift'], '-1', self::SCALE)
                                : $row['drift'],
                            self::SCALE,
                        );

                        $this->warn(sprintf(
                            '    DRIFT %s  product %s%s @ %s (lot ledger %s vs stock_levels %s)',
                            $row['drift'],
                            $row['product_id'],
                            $row['variant_id'] === null ? '' : ' / variant '.$row['variant_id'],
                            $row['location_id'],
                            $row['lot_total'],
                            $row['aggregate'],
                        ));
                        $this->warn(bccomp($row['drift'], '0', self::SCALE) > 0
                            ? self::CAUSE_POSITIVE
                            : self::CAUSE_NEGATIVE);
                    }
                }

                return self::SUCCESS;
            },
        );

        if ($exit !== self::SUCCESS) {
            return $exit;
        }

        $this->info(sprintf('Tuples drifted: %d', $tuplesDrifted));
        $this->info(sprintf('Net drift: %s', $netDrift));
        $this->info(sprintf('Absolute drift: %s', $absoluteDrift));
        $this->info('Read-only census: nothing was written.');

        if ($tuplesDrifted > 0 && $this->option('fail-on-drift') === true) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
