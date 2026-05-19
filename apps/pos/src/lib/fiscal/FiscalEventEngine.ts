/**
 * `FiscalEventEngine.append()` — the single device chain authoring path.
 *
 * Authority: spec v7 §6.1 (`append()` pseudocode) + §6.2 (the single device
 * chain head on `terminal_state`) + §4 (the canonical serialization
 * contract). Plan §1075+ (Task 15).
 *
 * Invariants:
 *
 *   - `append()` runs **inside the CALLER's SQLite transaction**. It does
 *     not `BEGIN`, `COMMIT`, or `ROLLBACK`. The caller (the receipt
 *     assembler in Phase 1) wraps `append()` together with its projection
 *     writes — `offline_receipts` row insert, voucher updates — so the
 *     fiscal-event row + the chain-head advance + the business projection
 *     are atomic. If the caller rolls back, nothing persists: no
 *     `fiscal_events` row, no chain-head advance.
 *
 *   - There is **one chain head per terminal**, stored on `terminal_state`
 *     in `fiscal_event_genesis_seed` / `fiscal_event_last_hash` /
 *     `fiscal_event_sequence`. The seed is provisioned at terminal
 *     registration. Until then `fiscal_event_genesis_seed` is the v37
 *     migration default `''` (empty-string sentinel); appending against
 *     that sentinel is structurally invalid and throws
 *     `ChainHeadNotInitializedError`.
 *
 *   - Device-side idempotency on `(source_event_class, source_event_id)`:
 *     if both are set and a local row already exists for that pair, the
 *     pre-existing row is returned without inserting a duplicate and
 *     without advancing the chain head. This lets the assembler safely
 *     retry an emission whose preceding effect already landed (mirrors
 *     the partial UNIQUE index on `fiscal_events`).
 *
 *   - Reserved event types (every `FiscalEventType` not in the Phase-1
 *     implemented set) throw `FiscalEventTypeNotImplementedError` and do
 *     NOT mutate state.
 *
 *   - Canonical payload follows spec §4 verbatim: the fourteen named
 *     fields, sorted-key JCS encoding, NFC + U+2028/U+2029 stripped at
 *     the producer, lowercase-hex SHA-256 of the UTF-8 bytes. Storage
 *     mirrors the encoder output exactly (`canonical_bytes` is persisted
 *     verbatim so the server can re-hash without re-serializing).
 */

import type Database from '@tauri-apps/plugin-sql';

import type { FiscalEventCanonicalEncoder } from './FiscalEventCanonicalEncoder';
import type { FiscalIntegrityProvider } from './HashChainIntegrityProvider';
import type {
  FiscalEventPayloadRegistry,
  FiscalEventTypeValue,
} from './FiscalEventPayloadRegistry';

/** 64-char lowercase hex — the seed / hash invariant from spec §3.1 + v37. */
const LOWER_HEX_64 = /^[0-9a-f]{64}$/;

/** UTC ISO-8601 second precision — `YYYY-MM-DDTHH:MM:SSZ`. */
const ISO_8601_SECONDS_UTC = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

/** `YYYY-MM-DD` calendar date. */
const ISO_8601_DATE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Thrown when `append()` is called on a terminal whose
 * `fiscal_event_genesis_seed` is not a valid 64-char lowercase hex
 * value — i.e. it is still the v37 migration default `''`, or a
 * known-sentinel placeholder like `'GENESIS'` / `'0'.repeat(64)`, or
 * any non-hex / non-64-char string.
 *
 * The seed is provisioned at terminal registration (server-side
 * device-bootstrap flow); `append()` cannot run until that completes.
 */
export class ChainHeadNotInitializedError extends Error {
  constructor(
    public readonly terminalId: string,
    public readonly observedSeed: string,
  ) {
    super(
      `Fiscal-event chain head not initialized for terminal ${terminalId}: ` +
        `fiscal_event_genesis_seed = ${JSON.stringify(observedSeed)} is not a valid 64-char lowercase hex seed. ` +
        'The seed is provisioned at terminal registration; append() cannot run before then.',
    );
    this.name = 'ChainHeadNotInitializedError';
  }
}

/**
 * Thrown when the caller-supplied payload or envelope fails the
 * Phase 1 canonical-payload contract (spec v7 §4) at the device
 * boundary — BEFORE any canonical_bytes are produced and BEFORE any
 * chain advance.
 *
 * The encoder rejects floats (Task 5 contract), but the spec also
 * requires monetary fields to be **decimal strings**, not integers,
 * and timestamps to be UTC ISO-8601 second precision. The encoder
 * cannot enforce those without payload-type knowledge; the engine
 * does so for the four implemented Phase 1 event types via a thin
 * validation layer. Anything stricter (per-line monetary fields,
 * nested sub-array shapes) is intentionally left to Task 16's
 * server-side `StrictCanonicalParser` — matching the deferral
 * pattern from Task 14 Opus P2-2.
 */
export class FiscalEventPayloadValidationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'FiscalEventPayloadValidationError';
  }
}

/**
 * Thrown when the chain INSERT trips the v37 partial UNIQUE on
 * `(tenant_id, terminal_id, sequence_number)` — i.e. a parallel
 * `append()` already advanced this chain head between our `readChainHead`
 * and our INSERT. The chain is unbroken; the caller should re-read the
 * head and retry, or escalate to a `CHAIN_BREAK_DETECTED` if the
 * race is structurally unexpected.
 */
export class ConcurrentChainAdvanceError extends Error {
  constructor(
    public readonly terminalId: string,
    public readonly attemptedSequence: number,
    public readonly cause: unknown,
  ) {
    super(
      `Concurrent chain advance on terminal ${terminalId} at sequence ${attemptedSequence}: ` +
        'another transaction already inserted this (terminal_id, sequence_number) pair. Re-read the head and retry.',
    );
    this.name = 'ConcurrentChainAdvanceError';
  }
}

/**
 * Caller-supplied authorship request.
 *
 * `payload` is opaque to `append()` — the caller assembles the business
 * document (lines, totals, payments, etc.) and supplies it as `payload`;
 * the encoder hashes whatever object is provided. Money values must be
 * decimal strings (the encoder rejects floats).
 */
export interface FiscalEventAppendRequest {
  event_type: FiscalEventTypeValue;
  tenant_id: string;
  company_id: string;
  terminal_id: string;
  operator_id: string;
  /** UTC ISO-8601 second precision (e.g. `'2026-05-16T10:00:00Z'`). */
  event_time_device: string;
  /** Local business date — `'YYYY-MM-DD'`. */
  business_date: string;
  /** The opaque business-document payload (hashed verbatim). */
  payload: unknown;
  reference_document_id?: string;
  reference_event_id?: string;
  /**
   * Device-side idempotency key. When both fields are set, a duplicate
   * call against the same `(tenant_id, terminal_id, source_event_class,
   * source_event_id)` 4-tuple returns the pre-existing row. The lookup
   * is scoped per-(tenant, terminal) so a generator that reuses ids
   * across terminals (e.g. per-terminal sequential receipt numbers)
   * cannot accidentally idempotent-deduplicate across terminals.
   */
  source_event_class?: string;
  source_event_id?: string;
}

// -------------------------------------------------------------------
// Typed payload-input interfaces (Phase 1 implemented event types).
//
// Compile-time defense — TS strict callers that build a typed input
// object cannot pass `total: 10` (number) where `total: '10.000'`
// (decimal string) is required. The interfaces mirror the server-side
// PHP DTOs in `apps/api/app/Modules/Fiscal/Domain/DTOs/*Payload.php`
// but at the device side every monetary field is `string` — never
// `number`.
//
// The engine's runtime validator (`validateSaleReceiptPayload` etc.)
// is the second defense: it asserts the top-level monetary fields are
// strings BEFORE encoding the canonical bytes. Per-line monetary
// fields and nested sub-array shape validation are intentionally left
// to Task 16's server-side `StrictCanonicalParser`.
// -------------------------------------------------------------------

export interface SaleReceiptPayloadInput {
  readonly currency: string;
  readonly currency_scale: number;
  readonly lines: ReadonlyArray<Record<string, unknown>>;
  readonly subtotal: string;
  readonly discount_total: string;
  readonly tax_total: string;
  readonly total: string;
  readonly vat_breakdown: ReadonlyArray<Record<string, unknown>>;
  readonly payment_lines: ReadonlyArray<Record<string, unknown>>;
  readonly voucher_redemptions: ReadonlyArray<Record<string, unknown>>;
}

export interface ChainBreakDetectedPayloadInput {
  readonly reason: string;
  readonly last_good_sequence: number;
  readonly last_good_hash: string;
  readonly offending_record_reference: Record<string, unknown>;
}

export interface ChainRestartPayloadInput {
  readonly new_genesis_reference: string;
  readonly last_good_anchor: Record<string, unknown>;
  readonly operator_authorization_evidence: Record<string, unknown>;
  readonly provenance_link: Record<string, unknown>;
}

export interface TerminalRegistrySnapshotPayloadInput {
  readonly terminals: ReadonlyArray<Record<string, unknown>>;
  readonly snapshot_hash: string;
  readonly prior_snapshot_link: string | null;
}

export interface FiscalEventAppendResult {
  id: string;
  tenant_id: string;
  company_id: string;
  terminal_id: string;
  operator_id: string;
  event_type: FiscalEventTypeValue;
  event_version: number;
  signature_version: string;
  sequence_number: number;
  event_time_device: string;
  business_date: string;
  reference_event_id: string | null;
  reference_document_id: string | null;
  source_event_class: string | null;
  source_event_id: string | null;
  canonical_bytes: string;
  previous_hash: string;
  current_hash: string;
  sync_status: 'pending';
  signature_status: 'not_required';
  created_at: string;
}

/**
 * Minimal subset of the `@tauri-apps/plugin-sql` Database surface
 * `append()` needs. Production callers pass a `Database`; tests pass the
 * `SqliteTestAdapter` which structurally satisfies this surface. The
 * caller controls the surrounding transaction with explicit `BEGIN` /
 * `COMMIT` / `ROLLBACK` `execute()` calls.
 *
 * Exported (Task 25) so sibling services that delegate to
 * `engine.append()` — like `ChainRecoveryService` — share the same
 * relaxed-but-typed handle shape.
 */
export interface SqlSurface {
  execute(sql: string, params?: unknown[]): Promise<{ rowsAffected: number; lastInsertId?: number }>;
  select<T>(sql: string, params?: unknown[]): Promise<T>;
}

/** Narrow `Database` / adapter to the structural surface we need. */
function asSql(tx: Database | SqlSurface): SqlSurface {
  // Database from `@tauri-apps/plugin-sql` exposes the same execute/select
  // shape; runtime type is whatever the caller threads in.
  return tx as unknown as SqlSurface;
}

export class FiscalEventEngine {
  constructor(
    private readonly defaultDb: Database | SqlSurface,
    private readonly encoder: FiscalEventCanonicalEncoder,
    private readonly integrityProvider: FiscalIntegrityProvider,
    private readonly registry: FiscalEventPayloadRegistry,
  ) {}

  /**
   * Author one fiscal event. Runs inside the caller's SQLite transaction
   * — never commits. See spec §6.1.
   *
   * @param tx The transactional handle the caller has already opened. In
   *           tests the adapter doubles as `tx`; in production this is
   *           the same `Database` reference around which the caller has
   *           issued `BEGIN`.
   */
  async append(
    tx: Database | SqlSurface,
    request: FiscalEventAppendRequest,
  ): Promise<FiscalEventAppendResult> {
    const sql = asSql(tx);

    // Step 0 (defense-in-depth) — validate the envelope + payload BEFORE
    // any state read or mutation. Closes Task 15 round-2 Codex BLOCKER
    // (opaque payload lets non-string money into immutable canonical
    // bytes) + P1-1 (timestamp / business_date format not enforced).
    // Throws FiscalEventPayloadValidationError on contract violation.
    this.validateRequestEnvelope(request);
    this.validateRequestPayload(request);

    // Step 1 — resolve event_version via the registry. Reserved types
    // throw before any state mutation.
    const eventVersion = this.registry.eventVersionFor(request.event_type);

    // Step 2 — device-side idempotency on (tenant_id, terminal_id,
    // source_event_class, source_event_id). The lookup is scoped
    // per-(tenant, terminal) so a generator that reuses source ids
    // across terminals cannot accidentally cross-terminal-pollute the
    // idempotent path (Task 15 round-2 Codex P2-1).
    if (request.source_event_class != null && request.source_event_id != null) {
      const existing = await this.findBySource(
        sql,
        request.tenant_id,
        request.terminal_id,
        request.source_event_class,
        request.source_event_id,
      );
      if (existing) {
        return existing;
      }
    }

    // Step 3 — read the single chain head from terminal_state. Refuse to
    // append unless `fiscal_event_genesis_seed` is a valid 64-char
    // lowercase hex value (Task 13 Codex P3 forward-looking input +
    // Task 15 round-2 Codex P3 hardening — also rejects 'GENESIS',
    // '0'.repeat(64), and any non-hex sentinel).
    const head = await this.readChainHead(sql, request.tenant_id, request.terminal_id);
    if (!LOWER_HEX_64.test(head.fiscal_event_genesis_seed)) {
      throw new ChainHeadNotInitializedError(
        request.terminal_id,
        head.fiscal_event_genesis_seed,
      );
    }
    const previousHash =
      head.fiscal_event_sequence > 0
        ? head.fiscal_event_last_hash
        : head.fiscal_event_genesis_seed;
    const sequenceNumber = head.fiscal_event_sequence + 1;

    // Step 4 — build canonical_bytes per spec §4 and hash via the
    // integrity provider (HashChainIntegrityProvider in Phase 1).
    const signatureVersion = this.integrityProvider.version();
    const canonicalPayload = {
      business_date: request.business_date,
      company_id: request.company_id,
      event_time_device: request.event_time_device,
      event_type: request.event_type,
      event_version: eventVersion,
      operator_id: request.operator_id,
      payload: request.payload,
      previous_hash: previousHash,
      reference_document_id: request.reference_document_id ?? null,
      reference_event_id: request.reference_event_id ?? null,
      sequence_number: sequenceNumber,
      signature_version: signatureVersion,
      tenant_id: request.tenant_id,
      terminal_id: request.terminal_id,
    };
    const canonicalBytes = this.encoder.encode(canonicalPayload);
    const currentHash = this.integrityProvider.computeHash(canonicalBytes);

    const id = generateUuidV4();
    const createdAt = isoSecondsUtc(new Date());

    // Step 5 — INSERT the fiscal_events row. sync_status='pending',
    // signature_status='not_required'. Parallel `append()`s on the same
    // terminal that race past `readChainHead` and re-INSERT the same
    // sequence_number trip the v37 chain UNIQUE; surface that as a
    // typed `ConcurrentChainAdvanceError` rather than a raw SQLITE
    // constraint message (Task 15 round-2 Codex P2-2).
    try {
      await sql.execute(
        `INSERT INTO fiscal_events (
           id, tenant_id, company_id, terminal_id, operator_id,
           event_type, event_version, signature_version,
           sequence_number, event_time_device, business_date,
           reference_event_id, reference_document_id,
           source_event_class, source_event_id,
           canonical_bytes, previous_hash, current_hash,
           signature_status, sync_status, created_at
         ) VALUES (
           $1, $2, $3, $4, $5,
           $6, $7, $8,
           $9, $10, $11,
           $12, $13,
           $14, $15,
           $16, $17, $18,
           'not_required', 'pending', $19
         )`,
        [
          id,
          request.tenant_id,
          request.company_id,
          request.terminal_id,
          request.operator_id,
          request.event_type,
          eventVersion,
          signatureVersion,
          sequenceNumber,
          request.event_time_device,
          request.business_date,
          request.reference_event_id ?? null,
          request.reference_document_id ?? null,
          request.source_event_class ?? null,
          request.source_event_id ?? null,
          canonicalBytes,
          previousHash,
          currentHash,
          createdAt,
        ],
      );
    } catch (error) {
      if (isChainSequenceUniqueViolation(error)) {
        throw new ConcurrentChainAdvanceError(request.terminal_id, sequenceNumber, error);
      }
      throw error;
    }

    // Step 6 — advance the chain head. Same `tx` so the advance is
    // atomic with the insert.
    await sql.execute(
      `UPDATE terminal_state
          SET fiscal_event_last_hash = $1,
              fiscal_event_sequence  = $2
        WHERE terminal_id = $3`,
      [currentHash, sequenceNumber, request.terminal_id],
    );

    return {
      id,
      tenant_id: request.tenant_id,
      company_id: request.company_id,
      terminal_id: request.terminal_id,
      operator_id: request.operator_id,
      event_type: request.event_type,
      event_version: eventVersion,
      signature_version: signatureVersion,
      sequence_number: sequenceNumber,
      event_time_device: request.event_time_device,
      business_date: request.business_date,
      reference_event_id: request.reference_event_id ?? null,
      reference_document_id: request.reference_document_id ?? null,
      source_event_class: request.source_event_class ?? null,
      source_event_id: request.source_event_id ?? null,
      canonical_bytes: canonicalBytes,
      previous_hash: previousHash,
      current_hash: currentHash,
      sync_status: 'pending',
      signature_status: 'not_required',
      created_at: createdAt,
    };
  }

  // -------------------------------------------------------------------
  // Internals
  // -------------------------------------------------------------------

  /**
   * Re-fetch an existing fiscal-event row for an idempotent source-backed
   * emission. The lookup is scoped to `(tenant_id, terminal_id,
   * source_event_class, source_event_id)` — Task 15 round-2 Codex P2-1
   * closure. A source id of `'r-1'` on terminal A is a DIFFERENT idempotency
   * key from `'r-1'` on terminal B even though the v37 partial UNIQUE
   * index does not include `terminal_id`; cross-terminal collisions
   * (which should not happen in practice) fall through to the INSERT
   * which then fails the partial UNIQUE and surfaces as a recoverable
   * chain incident rather than a silent cross-terminal idempotent match.
   *
   * Returns the same shape as a fresh append so the caller does not
   * need to branch on which path produced the result.
   */
  private async findBySource(
    sql: SqlSurface,
    tenantId: string,
    terminalId: string,
    sourceClass: string,
    sourceId: string,
  ): Promise<FiscalEventAppendResult | null> {
    const rows = await sql.select<FiscalEventRowShape[]>(
      `SELECT id, tenant_id, company_id, terminal_id, operator_id,
              event_type, event_version, signature_version,
              sequence_number, event_time_device, business_date,
              reference_event_id, reference_document_id,
              source_event_class, source_event_id,
              canonical_bytes, previous_hash, current_hash,
              signature_status, sync_status, created_at
         FROM fiscal_events
        WHERE tenant_id = $1
          AND terminal_id = $2
          AND source_event_class = $3
          AND source_event_id = $4
        LIMIT 1`,
      [tenantId, terminalId, sourceClass, sourceId],
    );
    const row = rows[0];
    if (!row) return null;
    return rowToResult(row);
  }

  /**
   * Envelope-level format validation — UTC ISO-8601 second precision on
   * `event_time_device`, `YYYY-MM-DD` on `business_date`. Closes Task 15
   * round-2 Codex P1-1 (caller could supply millisecond precision or a
   * non-UTC offset; the engine would copy the malformed value into
   * canonical_bytes verbatim, producing non-spec hashes the server
   * would later quarantine).
   */
  private validateRequestEnvelope(request: FiscalEventAppendRequest): void {
    if (!ISO_8601_SECONDS_UTC.test(request.event_time_device)) {
      throw new FiscalEventPayloadValidationError(
        `event_time_device must be UTC ISO-8601 second precision (YYYY-MM-DDTHH:MM:SSZ); got ${JSON.stringify(request.event_time_device)}.`,
      );
    }
    if (!ISO_8601_DATE.test(request.business_date)) {
      throw new FiscalEventPayloadValidationError(
        `business_date must be YYYY-MM-DD; got ${JSON.stringify(request.business_date)}.`,
      );
    }
  }

  /**
   * Per-event-type payload validation — asserts the top-level monetary
   * fields are strings (per spec v7 §4: "money as CurrencyScale::bcformat()
   * decimal strings"). Closes Task 15 round-2 Codex BLOCKER. The encoder
   * already rejects floats; this layer additionally rejects integers in
   * monetary-named fields, which the encoder cannot distinguish from
   * legitimate count fields without payload-type knowledge.
   *
   * Per-line monetary fields + nested sub-array shapes are intentionally
   * deferred to Task 16's server-side StrictCanonicalParser (matches
   * Task 14 Opus P2-2 deferral pattern). The engine layer enforces only
   * the top-level invariants that are unambiguously monetary.
   *
   * **Round-2 (Task 25 Codex T25-P2 closure):** CHAIN_BREAK_DETECTED +
   * CHAIN_RESTART now get runtime validation that MIRRORS the PHP
   * `FiscalPayloadConstraintValidator` constraint set EXACTLY — 64-char
   * lowercase hex on hash fields, non-empty assoc on the four
   * non-empty-object fields. Cross-language drift gate (Task 14 standing
   * pattern): TS validation must reject what PHP rejects.
   * `TERMINAL_REGISTRY_SNAPSHOT` remains deferred to Task 16's parser
   * since no Phase 1 TS caller authors it (only server emits).
   */
  private validateRequestPayload(request: FiscalEventAppendRequest): void {
    switch (request.event_type) {
      case 'SALE_RECEIPT':
        validateSaleReceiptPayload(request.payload);
        return;
      case 'CHAIN_BREAK_DETECTED':
        validateChainBreakDetectedPayload(request.payload);
        return;
      case 'CHAIN_RESTART':
        validateChainRestartPayload(request.payload);
        return;
      case 'TERMINAL_REGISTRY_SNAPSHOT':
        // No TS authoring path in Phase 1 — sub-array validation
        // deferred to Task 16's parser. Falling through with no
        // engine-side checks is the intentional Phase 1 posture.
        return;
      default:
        // Reserved types are rejected by the registry in step 1; this
        // branch is a defensive fallthrough.
        return;
    }
  }

  private async readChainHead(
    sql: SqlSurface,
    tenantId: string,
    terminalId: string,
  ): Promise<{
    fiscal_event_genesis_seed: string;
    fiscal_event_last_hash: string;
    fiscal_event_sequence: number;
  }> {
    const rows = await sql.select<
      Array<{
        fiscal_event_genesis_seed: unknown;
        fiscal_event_last_hash: unknown;
        fiscal_event_sequence: unknown;
      }>
    >(
      `SELECT fiscal_event_genesis_seed, fiscal_event_last_hash, fiscal_event_sequence
         FROM terminal_state
        WHERE terminal_id = $1`,
      [terminalId],
    );
    const row = rows[0];
    if (!row) {
      // Tenant scope is enforced by the caller / Spatie teams middleware in
      // production; this guard catches the truly-missing-terminal_state
      // case (e.g. an unprovisioned device).
      throw new Error(
        `terminal_state row not found for terminal ${terminalId} (tenant ${tenantId})`,
      );
    }
    if (
      typeof row.fiscal_event_genesis_seed !== 'string' ||
      typeof row.fiscal_event_last_hash !== 'string' ||
      typeof row.fiscal_event_sequence !== 'number'
    ) {
      throw new Error(
        `terminal_state chain-head columns have unexpected types for terminal ${terminalId}`,
      );
    }
    return {
      fiscal_event_genesis_seed: row.fiscal_event_genesis_seed,
      fiscal_event_last_hash: row.fiscal_event_last_hash,
      fiscal_event_sequence: row.fiscal_event_sequence,
    };
  }

  /** Expose the default DB handle for callers that want a non-tx convenience. */
  protected get db(): SqlSurface {
    return asSql(this.defaultDb);
  }
}

// -------------------------------------------------------------------
// Payload-shape validators for the four Phase 1 implemented event types.
// Top-level invariants only; per-line and nested sub-array shape stays
// the server-side StrictCanonicalParser's job (Task 16, Task 14 Opus P2-2).
// -------------------------------------------------------------------

const SALE_RECEIPT_STRING_MONETARY_FIELDS = [
  'subtotal',
  'discount_total',
  'tax_total',
  'total',
] as const;

function validateSaleReceiptPayload(payload: unknown): void {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    throw new FiscalEventPayloadValidationError(
      'SALE_RECEIPT payload must be an object.',
    );
  }
  const p = payload as Record<string, unknown>;

  if (typeof p['currency'] !== 'string') {
    throw new FiscalEventPayloadValidationError(
      `SALE_RECEIPT.currency must be a string; got ${typeofTag(p['currency'])}.`,
    );
  }
  if (typeof p['currency_scale'] !== 'number' || !Number.isInteger(p['currency_scale'])) {
    throw new FiscalEventPayloadValidationError(
      `SALE_RECEIPT.currency_scale must be an integer; got ${typeofTag(p['currency_scale'])}.`,
    );
  }
  for (const field of SALE_RECEIPT_STRING_MONETARY_FIELDS) {
    if (typeof p[field] !== 'string') {
      throw new FiscalEventPayloadValidationError(
        `SALE_RECEIPT.${field} must be a decimal string (e.g. "10.000"); got ${typeofTag(p[field])}. ` +
          'Spec v7 §4 — money MUST be a CurrencyScale::bcformat() decimal string, never a number.',
      );
    }
  }
  if (!Array.isArray(p['lines'])) {
    throw new FiscalEventPayloadValidationError(
      `SALE_RECEIPT.lines must be an array; got ${typeofTag(p['lines'])}.`,
    );
  }
}

/**
 * Allowed top-level payload keys per event type. Mirrors PHP
 * `FiscalPayloadConstraintValidator::PAYLOAD_KEYS` byte-for-byte —
 * Task 14 cross-language drift gate (Task 25 round-3 Codex P1 closure).
 *
 * The PHP authority lives at
 * `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:68-85`.
 * Any change there MUST land here in the same commit.
 */
const CHAIN_BREAK_DETECTED_PAYLOAD_KEYS = [
  'last_good_hash',
  'last_good_sequence',
  'offending_record_reference',
  'reason',
] as const;

const CHAIN_RESTART_PAYLOAD_KEYS = [
  'last_good_anchor',
  'new_genesis_reference',
  'operator_authorization_evidence',
  'provenance_link',
] as const;

/**
 * Assert `payload` has no top-level keys outside `allowed`. Mirrors PHP
 * `FiscalPayloadConstraintValidator::validatePayloadKeySet()` exactly —
 * `payload_extra_field:<csv>` prefix included so the error surface is
 * cross-language symmetric (one verifier rule, two enforcement points).
 *
 * @throws FiscalEventPayloadValidationError when any unknown key is found.
 */
function assertNoExtraTopLevelKeys(
  payload: Record<string, unknown>,
  allowed: ReadonlyArray<string>,
  eventTypeLabel: string,
): void {
  const allowedSet = new Set<string>(allowed);
  const extras: string[] = [];
  for (const key of Object.keys(payload)) {
    if (!allowedSet.has(key)) {
      extras.push(key);
    }
  }
  if (extras.length > 0) {
    throw new FiscalEventPayloadValidationError(
      `payload_extra_field:${extras.join(',')} — ${eventTypeLabel} payload carries unknown top-level key(s) ` +
        `not in PHP FiscalPayloadConstraintValidator::PAYLOAD_KEYS. ` +
        `Allowed: ${[...allowedSet].sort().join(', ')}.`,
    );
  }
}

/**
 * Validate CHAIN_BREAK_DETECTED payload — mirrors the PHP
 * `FiscalPayloadConstraintValidator::validateChainBreakDetectedPayload()`
 * constraint set EXACTLY. Closes Task 25 round-2 Codex T25-P2
 * (cross-language drift gate — TS must reject what PHP rejects) and
 * Task 25 round-3 Codex P1 (extras rejection mirroring PHP
 * `validatePayloadKeySet`).
 *
 *   - No extra top-level keys beyond `CHAIN_BREAK_DETECTED_PAYLOAD_KEYS`.
 *   - `last_good_hash` must be 64-char lowercase hex.
 *   - `offending_record_reference` must be a non-empty object.
 *   - If `offending_record_reference.observed_previous_hash` is present,
 *     it must be 64-char lowercase hex.
 */
function validateChainBreakDetectedPayload(payload: unknown): void {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    throw new FiscalEventPayloadValidationError(
      'CHAIN_BREAK_DETECTED payload must be an object.',
    );
  }
  const p = payload as Record<string, unknown>;

  assertNoExtraTopLevelKeys(p, CHAIN_BREAK_DETECTED_PAYLOAD_KEYS, 'CHAIN_BREAK_DETECTED');

  assertHashField(p, 'last_good_hash', 'CHAIN_BREAK_DETECTED.last_good_hash');
  assertNonEmptyAssoc(p, 'offending_record_reference', 'CHAIN_BREAK_DETECTED.offending_record_reference');

  const ref = p['offending_record_reference'] as Record<string, unknown>;
  if (Object.prototype.hasOwnProperty.call(ref, 'observed_previous_hash')) {
    assertHashField(
      ref,
      'observed_previous_hash',
      'CHAIN_BREAK_DETECTED.offending_record_reference.observed_previous_hash',
    );
  }
}

/**
 * Validate CHAIN_RESTART payload — mirrors the PHP
 * `FiscalPayloadConstraintValidator::validateChainRestartPayload()`
 * constraint set EXACTLY. Closes Task 25 round-2 Codex T25-P2 and
 * Task 25 round-3 Codex P1 (extras rejection mirroring PHP
 * `validatePayloadKeySet`).
 *
 *   - No extra top-level keys beyond `CHAIN_RESTART_PAYLOAD_KEYS`.
 *   - `new_genesis_reference` must be 64-char lowercase hex.
 *   - `last_good_anchor`, `operator_authorization_evidence`,
 *     `provenance_link` must each be non-empty objects.
 *   - If `last_good_anchor.hash` is present, it must be 64-char lowercase hex.
 */
function validateChainRestartPayload(payload: unknown): void {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    throw new FiscalEventPayloadValidationError(
      'CHAIN_RESTART payload must be an object.',
    );
  }
  const p = payload as Record<string, unknown>;

  assertNoExtraTopLevelKeys(p, CHAIN_RESTART_PAYLOAD_KEYS, 'CHAIN_RESTART');

  assertHashField(p, 'new_genesis_reference', 'CHAIN_RESTART.new_genesis_reference');
  assertNonEmptyAssoc(p, 'last_good_anchor', 'CHAIN_RESTART.last_good_anchor');
  assertNonEmptyAssoc(
    p,
    'operator_authorization_evidence',
    'CHAIN_RESTART.operator_authorization_evidence',
  );
  assertNonEmptyAssoc(p, 'provenance_link', 'CHAIN_RESTART.provenance_link');

  const anchor = p['last_good_anchor'] as Record<string, unknown>;
  if (Object.prototype.hasOwnProperty.call(anchor, 'hash')) {
    assertHashField(anchor, 'hash', 'CHAIN_RESTART.last_good_anchor.hash');
  }
}

/**
 * Assert a field on `bag` is a 64-char lowercase hex string. Mirrors
 * `FiscalPayloadConstraintValidator::assertHashField()` exactly. Throws
 * `FiscalEventPayloadValidationError` on violation.
 */
function assertHashField(bag: Record<string, unknown>, field: string, label: string): void {
  const value = bag[field];
  if (typeof value !== 'string' || !LOWER_HEX_64.test(value)) {
    throw new FiscalEventPayloadValidationError(
      `${label} must be 64-char lowercase hex; got ${typeofTag(value)}${
        typeof value === 'string' ? ` (${JSON.stringify(value)})` : ''
      }.`,
    );
  }
}

/**
 * Assert a field on `bag` is a non-empty object (not null, not an array,
 * not a string-keyed empty object). Mirrors
 * `FiscalPayloadConstraintValidator::validateNonEmptyAssoc()` exactly.
 * Throws `FiscalEventPayloadValidationError` on violation.
 */
function assertNonEmptyAssoc(bag: Record<string, unknown>, field: string, label: string): void {
  const value = bag[field];
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    throw new FiscalEventPayloadValidationError(
      `${label} must be a non-empty object; got ${typeofTag(value)}.`,
    );
  }
  if (Object.keys(value as Record<string, unknown>).length === 0) {
    throw new FiscalEventPayloadValidationError(
      `${label} must be a non-empty object; got empty object.`,
    );
  }
}

function typeofTag(value: unknown): string {
  if (value === null) return 'null';
  if (Array.isArray(value)) return 'array';
  return typeof value;
}

/**
 * Detect a chain-sequence UNIQUE violation on `fiscal_events`. SQLite
 * surfaces it as a string containing `UNIQUE constraint failed:
 * fiscal_events.tenant_id, fiscal_events.terminal_id,
 * fiscal_events.sequence_number` (the index name varies by sqlite
 * version + plugin, so we match on column names which are stable).
 *
 * Only the (terminal_id, sequence_number) collision is treated as
 * concurrent-chain-advance. A collision on the
 * (source_event_class, source_event_id) partial UNIQUE is a different
 * class of incident (a cross-terminal source-id collision under the
 * engine-side scope from Task 15 round-2 P2-1) and falls through to
 * the generic `throw` so the caller / recovery flow can disambiguate.
 */
function isChainSequenceUniqueViolation(error: unknown): boolean {
  const message =
    error instanceof Error
      ? error.message
      : typeof error === 'string'
        ? error
        : '';
  return (
    /UNIQUE constraint failed/i.test(message) &&
    /fiscal_events\.terminal_id/i.test(message) &&
    /fiscal_events\.sequence_number/i.test(message)
  );
}

// -------------------------------------------------------------------
// Row shape helpers — narrow `unknown` SELECT rows into the typed result.
// -------------------------------------------------------------------

interface FiscalEventRowShape {
  id: unknown;
  tenant_id: unknown;
  company_id: unknown;
  terminal_id: unknown;
  operator_id: unknown;
  event_type: unknown;
  event_version: unknown;
  signature_version: unknown;
  sequence_number: unknown;
  event_time_device: unknown;
  business_date: unknown;
  reference_event_id: unknown;
  reference_document_id: unknown;
  source_event_class: unknown;
  source_event_id: unknown;
  canonical_bytes: unknown;
  previous_hash: unknown;
  current_hash: unknown;
  signature_status: unknown;
  sync_status: unknown;
  created_at: unknown;
}

function rowToResult(row: FiscalEventRowShape): FiscalEventAppendResult {
  return {
    id: requireString(row.id, 'id'),
    tenant_id: requireString(row.tenant_id, 'tenant_id'),
    company_id: requireString(row.company_id, 'company_id'),
    terminal_id: requireString(row.terminal_id, 'terminal_id'),
    operator_id: requireString(row.operator_id, 'operator_id'),
    event_type: requireString(row.event_type, 'event_type') as FiscalEventTypeValue,
    event_version: requireNumber(row.event_version, 'event_version'),
    signature_version: requireString(row.signature_version, 'signature_version'),
    sequence_number: requireNumber(row.sequence_number, 'sequence_number'),
    event_time_device: requireString(row.event_time_device, 'event_time_device'),
    business_date: requireString(row.business_date, 'business_date'),
    reference_event_id: nullableString(row.reference_event_id),
    reference_document_id: nullableString(row.reference_document_id),
    source_event_class: nullableString(row.source_event_class),
    source_event_id: nullableString(row.source_event_id),
    canonical_bytes: requireString(row.canonical_bytes, 'canonical_bytes'),
    previous_hash: requireString(row.previous_hash, 'previous_hash'),
    current_hash: requireString(row.current_hash, 'current_hash'),
    sync_status: requireExact(row.sync_status, 'pending', 'sync_status'),
    signature_status: requireExact(row.signature_status, 'not_required', 'signature_status'),
    created_at: requireString(row.created_at, 'created_at'),
  };
}

function requireString(value: unknown, field: string): string {
  if (typeof value !== 'string') {
    throw new Error(`fiscal_events.${field} expected string, got ${typeof value}`);
  }
  return value;
}

function requireNumber(value: unknown, field: string): number {
  if (typeof value !== 'number') {
    throw new Error(`fiscal_events.${field} expected number, got ${typeof value}`);
  }
  return value;
}

function requireExact<T extends string>(value: unknown, expected: T, field: string): T {
  if (value !== expected) {
    throw new Error(
      `fiscal_events.${field} expected ${expected}, got ${typeof value === 'string' ? value : typeof value}`,
    );
  }
  return expected;
}

function nullableString(value: unknown): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value !== 'string') {
    throw new Error(`expected string or null, got ${typeof value}`);
  }
  return value;
}

// -------------------------------------------------------------------
// Free-function helpers
// -------------------------------------------------------------------

/**
 * RFC 4122 UUID v4 generator. Prefers the platform `crypto.randomUUID()`
 * (available in Node 19+ and all evergreen browsers/Tauri 2). Falls back
 * to `crypto.getRandomValues()` so the engine doesn't bring a test-only
 * polyfill into runtime. Falling further back to `Math.random()` is
 * deliberately not done — a fiscal id is integrity-critical.
 */
function generateUuidV4(): string {
  const c = globalThis.crypto;
  if (c && typeof c.randomUUID === 'function') {
    return c.randomUUID();
  }
  if (c && typeof c.getRandomValues === 'function') {
    const bytes = new Uint8Array(16);
    c.getRandomValues(bytes);
    // Per RFC 4122 §4.4: version = 4 (top nibble of byte 6 == 0b0100),
    // variant = 10xx (top two bits of byte 8).
    bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40;
    bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20, 32)}`;
  }
  throw new Error('No crypto source available for UUID v4 generation');
}

/** ISO-8601 UTC timestamp truncated to second precision (`...Z`). */
function isoSecondsUtc(now: Date): string {
  return now.toISOString().replace(/\.\d{3}Z$/, 'Z');
}
