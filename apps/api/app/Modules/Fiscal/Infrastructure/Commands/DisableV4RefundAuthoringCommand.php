<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Application\Services\LegacyCorrectionGuard;
use App\Modules\POS\Domain\Terminal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v3-refund-chain-integration — the ROLLBACK lever for the §9.3 two-phase
 * enable/acknowledge protocol, and the counterpart to
 * {@see EnableV4RefundAuthoringCommand}.
 *
 * **Why a command and not `UPDATE pos_terminals SET … = false` (whole-branch
 * review finding I-2 / merge condition C-2).** The capability is carried by
 * a PAIR of columns with different consumers:
 *
 *  - `v4_refund_authoring_enabled` is what the DEVICE pulls
 *    (`TerminalResource` → `syncService.pullTerminalState` →
 *    `terminal_state.v4_refund_authoring_enabled`) and is the single boolean
 *    `HomePage` routes a refund on;
 *  - `v4_refund_authoring_acknowledged_at` is what the SERVER's
 *    {@see LegacyCorrectionGuard}
 *    reads to decide whether the legacy `/return` + `/void` endpoints are
 *    retired for the terminal.
 *
 * Clearing only the first — the intuitive launch-night emergency action —
 * puts the terminal in NEITHER path: the device routes to legacy, and the
 * server 409s `LEGACY_CORRECTION_RETIRED` on it. Neither column is
 * `$fillable`, so `PATCH /pos/terminals/{id}` cannot reach them either.
 * This command clears BOTH in ONE statement so that half-state is not
 * reachable through the supported lever.
 *
 * It also RESOLVES that half-state if an operator already produced it: the
 * target set is every terminal of the company carrying EITHER flag, not
 * just the enabled ones.
 *
 * Conventions mirrored from the Enable command: `TenantScopedCommand`'s
 * (a-singleshot) sub-shape (`--tenant` + `--company`), a `--dry-run` that
 * writes nothing, and refusals that mutate nothing. Added here because this
 * is a destructive rollout reversal: an explicit confirmation prompt
 * (bypassable with `--force` for non-interactive deploys, the repository's
 * established pattern) and a `Log::warning` audit line carrying the exact
 * terminal ids, mirroring the `Log::info` the acknowledgement writes when
 * the legacy path was retired.
 */
final class DisableV4RefundAuthoringCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'pos:disable-v4-refund-authoring
        {--tenant= : UUID of the parent tenant}
        {--company= : UUID of the company; must belong to --tenant}
        {--terminal= : optional UUID of ONE terminal to roll back; defaults to every terminal of the company carrying either flag}
        {--dry-run : list the affected terminals without writing anything}
        {--force : skip the interactive confirmation (non-interactive deploys)}';

    /** @var string */
    protected $description = 'ROLLBACK of the v4 refund-authoring capability — atomically clears BOTH v4_refund_authoring_enabled AND v4_refund_authoring_acknowledged_at so the legacy /return + /void path is available again.';

    /** @var list<string> */
    protected $aliases = ['fiscal:disable-v4-refund-authoring'];

    /**
     * ⚖️ C-6 ruling — ACCEPTED AS FAIL-CLOSED, not merge-blocking.
     *
     * Clearing the flags re-opens the legacy path LOGICALLY (the guard goes
     * inert and the remaining-returnable cap is era-correct), but on a
     * **v3-from-birth** terminal — zero legacy-era receipts, i.e. the launch
     * configuration — a legacy return still cannot be AUTHORED, for a
     * structural reason unrelated to the flags:
     *
     *  - the v3/v4 ingestion path never advances
     *    `pos_terminals.current_sequence`, while the projector fills
     *    `pos_receipts.chain_sequence` from the device's own fiscal-event
     *    sequence numbers (`PosCoreReceiptProjection`);
     *  - the legacy path allocates `chain_sequence = current_sequence`
     *    (`ReceiptFinalizationService`), which on such a terminal is 0 and
     *    behind the projected chain.
     *
     * The attempt therefore fails closed inside its own transaction — no cash
     * leaves the drawer, no fiscal event is written, nothing is half-applied.
     * This is §1's PRE-EXISTING failure mode re-exposed (not introduced) by
     * the lever, and the honest operational semantic of an emergency disable
     * on a v3-from-birth terminal is "refunds halted until re-enable" — the
     * pre-Lane-C interim no-refunds prohibition — NOT "legacy returns work".
     * A real fix changes fiscal chain numbering and has its own gated lane
     * post-launch.
     */
    private const V3_FROM_BIRTH_WARNING = 'WARNING — THIS HALTS REFUNDS, it does not fall back to working legacy refunds. On a v3-from-birth terminal (no legacy-era receipts — the launch configuration) legacy /return remains STRUCTURALLY UNAVAILABLE after this rollback: the legacy path numbers receipts off pos_terminals.current_sequence, which the v3/v4 path never advances, so the attempt fails closed (no cash movement, no fiscal event). Expect NO refunds of any kind on this terminal until v4 refund authoring is RE-ENABLED with fiscal:enable-v4-refund-authoring.';

    private const CONFIRMATION_QUESTION = 'Clear BOTH v4 refund-authoring flags, accepting that on a v3-from-birth terminal this HALTS ALL REFUNDS until v4 is re-enabled?';

    public function __construct(CompanyContext $companyContext)
    {
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
        $force = $this->option('force') === true;

        $terminalOption = $this->option('terminal');
        $terminalId = is_string($terminalOption) && $terminalOption !== '' ? $terminalOption : null;

        // The target set is EITHER flag, deliberately: a terminal that has
        // already had `enabled` cleared by hand is exactly the bricked S6
        // state this command exists to resolve, and selecting on `enabled`
        // alone would silently skip it.
        $query = Terminal::query()
            ->where('company_id', $companyId)
            ->where(function ($q): void {
                $q->where('v4_refund_authoring_enabled', true)
                    ->orWhereNotNull('v4_refund_authoring_acknowledged_at');
            });

        if ($terminalId !== null) {
            $query->where('id', $terminalId);
        }

        /** @var Collection<int, Terminal> $terminals */
        $terminals = $query->get();

        if ($terminals->isEmpty()) {
            if ($terminalId !== null) {
                // An explicit --terminal that matched nothing is an operator
                // mistake (wrong id, wrong company, or already rolled back).
                // Fail rather than report a misleading success.
                $this->error(sprintf(
                    'Refusing to proceed: terminal %s carries neither v4 refund-authoring flag for company %s (wrong terminal, wrong company, or already rolled back).',
                    $terminalId,
                    $companyId,
                ));

                return self::FAILURE;
            }

            $this->info(sprintf('Nothing to do: no terminal of company %s carries a v4 refund-authoring flag.', $companyId));

            return self::SUCCESS;
        }

        $this->table(
            ['terminal_id', 'code', 'enabled', 'acknowledged_at'],
            $terminals->map(static fn (Terminal $terminal): array => [
                (string) $terminal->id,
                (string) $terminal->code,
                $terminal->v4_refund_authoring_enabled === true ? 'true' : 'false',
                $terminal->v4_refund_authoring_acknowledged_at?->toISOString() ?? '—',
            ])->all(),
        );

        if ($dryRun) {
            $this->info(sprintf('[DRY-RUN] %d terminal(s) would have BOTH v4 refund-authoring flags cleared. Nothing written.', $terminals->count()));

            return self::SUCCESS;
        }

        // ⚖️ C-6 ruling (ACCEPTED AS FAIL-CLOSED): on a v3-from-birth terminal
        // the rollback does NOT restore working legacy refunds — it HALTS
        // refunds. The operator must consent to that, not discover it, so the
        // statement is inside the prompt itself and not only in the epilogue.
        $this->newLine();
        $this->warn(self::V3_FROM_BIRTH_WARNING);
        $this->newLine();

        if (! $force && ! $this->confirm(self::CONFIRMATION_QUESTION)) {
            $this->error('Aborted — nothing was written.');

            return self::FAILURE;
        }

        /** @var list<string> $ids */
        $ids = $terminals->map(static fn (Terminal $terminal): string => (string) $terminal->id)->all();

        // ONE statement, both columns. The whole point of the command is
        // that no window exists in which only one of the two is cleared.
        DB::transaction(function () use ($ids): void {
            Terminal::query()->whereIn('id', $ids)->update([
                'v4_refund_authoring_enabled' => false,
                'v4_refund_authoring_acknowledged_at' => null,
            ]);
        });

        Log::warning('[fiscal] v4 refund authoring DISABLED (rollback) — legacy correction path re-opened for terminal(s)', [
            'company_id' => $companyId,
            'terminal_ids' => $ids,
        ]);

        $this->info(sprintf('v4 refund authoring DISABLED for %d terminal(s). Both flags cleared; the LegacyCorrectionGuard is inert again server-side.', count($ids)));

        // Repeated after the write, not only before it: an operator who ran
        // this with --force never saw the prompt.
        $this->newLine();
        $this->warn(self::V3_FROM_BIRTH_WARNING);
        $this->warn('A terminal WITH legacy-era history (its current_sequence is coherent with its receipt chain) does get a working legacy /return back.');

        // Device-side recovery. Verified against `apps/pos` at this HEAD:
        //  - `pullTerminalState` writes `setV4RefundAuthoringEnabled(db, id,
        //    false)` from this response, and `HomePage` routes refunds on
        //    exactly that local boolean, so ROUTING reverts by itself;
        //  - the device's own `terminal_state.v4_refund_authoring_acknowledged_at`
        //    / `_ack_error` are written once and never cleared, but their
        //    only reader is `getV4RefundAuthoringAckState`, which has no
        //    production caller (diagnostics only) — a stale value there
        //    changes no behaviour.
        // The only real gap is the interval BEFORE that pull lands.
        $this->newLine();
        $this->warn('DEVICE: the terminal keeps routing refunds to the v4 flow until its NEXT SUCCESSFUL terminal-state pull (GET /pos/terminals/{id}); that pull writes the local flag to 0 and refunds route to legacy again. Until then BOTH paths accept work.');
        $this->warn('RECOVERY STEP: force that pull now — trigger a sync from the POS, or restart the POS app (the pull is a startup step). Verify on the device with: SELECT v4_refund_authoring_enabled FROM terminal_state WHERE terminal_id = \'<id>\'; it must read 0.');
        $this->warn('The device\'s local terminal_state.v4_refund_authoring_acknowledged_at / _ack_error are NOT cleared by this command. They are diagnostic-only (no production reader) — leave them; they are the audit trail of the rollout that was rolled back.');

        return self::SUCCESS;
    }
}
