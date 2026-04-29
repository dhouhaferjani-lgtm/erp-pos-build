<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance;

use App\Shared\Contracts\Compliance\DTOs\Nf525ChainVerificationResult;
use App\Shared\Contracts\Compliance\DTOs\Nf525ExportSnapshot;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReprintLogFilter;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReprintLogPage;
use App\Shared\Contracts\Compliance\DTOs\Nf525TerminalChainSummary;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/**
 * Cross-module contract — POS provides, Compliance consumes.
 *
 * Closes the Rule #6 violation surfaced by the Q2 deferred-items audit
 * (H3): Compliance previously imported 13 POS Domain classes (including
 * two POS Domain Services) directly. After this refactor Compliance only
 * depends on this interface and the DTOs under
 * `App\Shared\Contracts\Compliance\DTOs\`.
 *
 * Extension story (refund-flow workstream): the DTOs hanging off this
 * interface already carry nullable extension points for vouchers,
 * `exchange_group_id`, and return-receipt audit fields. Refund flow
 * populates them in the POS provider; Compliance only adds emit logic in
 * its private XML builder. No breaking change to this interface.
 *
 * @see docs/follow-ups/2026-04-28-q2-release-deferred-items.md (H3)
 * @see docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md §3.4 §3.6 §5.1 §5.3
 */
interface Nf525DataProviderContract
{
    /**
     * Build the full POS-side snapshot for a NF525 JET XML export.
     *
     * @throws ModelNotFoundException If $companyId is unknown.
     */
    public function buildExportSnapshot(string $companyId, Carbon $from, Carbon $to): Nf525ExportSnapshot;

    /**
     * List all terminals owned by a company (for /verify-chains).
     *
     * @return list<Nf525TerminalChainSummary>
     */
    public function listTerminalsForCompany(string $companyId): array;

    /**
     * Verify a terminal's receipt hash chain. Walks the chain, recomputes
     * each row's hash, and stops at the first mismatch.
     */
    public function verifyReceiptChain(string $terminalId): Nf525ChainVerificationResult;

    /**
     * Verify a terminal's Z-report hash chain.
     */
    public function verifyZReportChain(string $terminalId): Nf525ChainVerificationResult;

    /**
     * Paginated reprint-log query. Filter inputs are pre-validated.
     */
    public function fetchReprintLog(Nf525ReprintLogFilter $filter): Nf525ReprintLogPage;
}
