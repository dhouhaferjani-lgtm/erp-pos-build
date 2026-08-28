<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use Illuminate\Support\Facades\DB;

/**
 * v3-refund-chain-integration spec §9.4/§9.5/§5.3, §9.6/X3 — manual
 * Phase 1 ("server offers") override for the two-phase
 * enable/acknowledge protocol (§9.3).
 *
 * OWNER RULING 2026-08-28: v4 refund authoring is default-on for every
 * newly created PHYSICAL terminal, and the guarded tenant migration enables
 * qualifying brownfield physical terminals automatically. This command
 * remains the explicit operator recovery/override after a guarded migration
 * skip is remediated or after an emergency disable. It deliberately retains
 * every original preflight, including the single-active-physical-terminal
 * refusal; the automatic migration's approved multi-till behavior does not
 * broaden this manual lever. Single-shot, one tenant + one company per
 * invocation (`TenantScopedCommand`'s (a-singleshot) sub-shape).
 *
 * Three preflight refusals, checked in order, none of which mutate
 * anything on failure:
 *
 *   1. **§9.4 single-DEVICE-terminal preflight.** Exactly one ACTIVE
 *      device terminal must exist for the company. This codebase's
 *      `TerminalType` enum has no literal case named `Device` — `Physical`
 *      is the actual case denoting a real device terminal (as opposed to
 *      `Web` / `VirtualAdmin`, neither of which is a physical device this
 *      capability could ever apply to), so this command reads §9.4's
 *      "TerminalType::Device" as `TerminalType::Physical`.
 *   2. **§9.6/X3 — v3-from-birth is a deployment check, not a repository-
 *      provable fact, VERIFIED here.** The target terminal must have ZERO
 *      legacy-sealed (`fiscal_event_id IS NULL`, `fiscal_status =
 *      'fiscalized'`) receipts — the equivalent-and-verifiable proxy X3
 *      names for "this terminal's `fiscal_schema_version` was never 2".
 *   3. **§5.3 — account-provisioning precheck.** `hasAccountForPurpose()`
 *      for BOTH `RefundWriteOff` and `SalesReturn` must resolve; either
 *      missing blocks enablement (never a silent partial capability).
 *
 * On success: sets `pos_terminals.v4_refund_authoring_enabled = true` for
 * the one qualifying terminal. Phase 2 (device acknowledgement,
 * `v4_refund_authoring_acknowledged_at`) is written by the device's own
 * sync round-trip (`apps/pos`, wave 2) — this command never sets it.
 */
final class EnableV4RefundAuthoringCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'fiscal:enable-v4-refund-authoring
        {--tenant= : UUID of the parent tenant}
        {--company= : UUID of the company; must belong to --tenant}
        {--dry-run : run every preflight check without writing anything}';

    /** @var string */
    protected $description = 'Manual Phase 1 v4 refund-authoring override/recovery — retains single-device-terminal, v3-from-birth, and account-provisioning preflights.';

    public function __construct(
        CompanyContext $companyContext,
        private readonly GeneralLedgerService $generalLedgerService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        if (($code = $this->bindTenantAndCompanyFromOptions()) !== null) {
            return $code;
        }

        /** @var string $companyId */
        $companyId = $this->option('company');
        $dryRun = $this->option('dry-run') === true;

        // ---- 1. §9.4 single-device-terminal preflight. ----
        $deviceTerminals = Terminal::query()
            ->where('company_id', $companyId)
            ->where('type', TerminalType::Physical)
            ->where('is_active', true)
            ->get();

        if ($deviceTerminals->count() !== 1) {
            $this->error(sprintf(
                'Refusing to enable v4 refund authoring: company %s has %d active device terminal(s); exactly 1 is required.',
                $companyId,
                $deviceTerminals->count(),
            ));

            return self::FAILURE;
        }

        /** @var Terminal $terminal */
        $terminal = $deviceTerminals->first();

        // ---- 2. §9.6/X3 — v3-from-birth verification. ----
        // Orchestrator amendment (post-wave-2 sweep): the §17 manifest bullet
        // `InventoryV3LegacyCorrectionsCommand.php` was DROPPED — this exact
        // check (a per-terminal inventory of legacy-sealed corrections that
        // must be resolved/absent before enablement) is what that command
        // would have existed to compute. Its purpose is substantively
        // subsumed here: this preflight already enumerates and refuses on
        // any legacy-sealed fiscalized receipt for the target terminal, so a
        // standalone inventory command would be duplicate machinery over the
        // same query.
        $legacyFiscalizedCount = DB::table('pos_receipts')
            ->where('terminal_id', $terminal->id)
            ->whereNull('fiscal_event_id')
            ->where('fiscal_status', 'fiscalized')
            ->count();

        if ($legacyFiscalizedCount > 0) {
            $this->error(sprintf(
                'Refusing to enable v4 refund authoring: terminal %s has %d legacy-sealed (fiscal_event_id IS NULL) fiscalized receipt(s) — it has v2 history and is not v3-from-birth.',
                $terminal->id,
                $legacyFiscalizedCount,
            ));

            return self::FAILURE;
        }

        // ---- 3. §5.3 account-provisioning precheck. ----
        $missingPurposes = [];
        foreach ([SystemAccountPurpose::RefundWriteOff, SystemAccountPurpose::SalesReturn] as $purpose) {
            if (! $this->generalLedgerService->hasAccountForPurpose($companyId, $purpose)) {
                $missingPurposes[] = $purpose->value;
            }
        }

        if ($missingPurposes !== []) {
            $this->error(sprintf(
                'Refusing to enable v4 refund authoring: company %s is missing account(s) for purpose(s): %s. Run accounting:backfill-refund-compensation-accounts first.',
                $companyId,
                implode(', ', $missingPurposes),
            ));

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info(sprintf('[DRY-RUN] All preflight checks passed — terminal %s would be enabled for v4 refund authoring.', $terminal->id));

            return self::SUCCESS;
        }

        $terminal->v4_refund_authoring_enabled = true;
        $terminal->save();

        $this->info(sprintf('v4 refund authoring enabled (Phase 1) for terminal %s. Awaiting Phase 2 device acknowledgement.', $terminal->id));

        return self::SUCCESS;
    }
}
