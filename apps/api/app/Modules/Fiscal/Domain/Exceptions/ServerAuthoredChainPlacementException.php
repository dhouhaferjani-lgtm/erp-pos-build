<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use RuntimeException;

/**
 * ES-09 defect (b) — thrown when a SERVER-AUTHORED fiscal event's derived
 * integrity verdict is anything other than `Verified`.
 *
 * Before this milestone both server-authoring services stamped
 * `integrity_status = Verified` / `payload_parse_status = Parsed`
 * UNCONDITIONALLY, skipping the `verifyLinkage` / `verifyClock` checks
 * `OutboxIngestor` runs on every device-authored envelope. The stamp was an
 * assertion, not a finding.
 *
 * **Why this REFUSES rather than quarantining.** A device envelope is a fact
 * the ledger is obliged to preserve even when it is defective — hence the
 * quarantine table. A server-authored event is not: it is being composed right
 * now, by us, inside a transaction, from state we just read under a row lock.
 * If its own link is inadmissible, the honest outcome is that no row is
 * written at all. Admitting it as `Quarantined` would invent server-side
 * quarantine semantics no projector expects, and stamping it `Verified` is the
 * defect this exception exists to end.
 *
 * **This is not a refusal contract** (brief R-6). ES-09 is a CORRECTNESS row:
 * for a legitimate append the verdict derives to `Verified` and the write
 * succeeds, which is exactly what the two-context regression proves. This
 * exception is the fail-closed floor under that derivation, not a new gate on
 * anybody's write path.
 */
final class ServerAuthoredChainPlacementException extends RuntimeException {}
